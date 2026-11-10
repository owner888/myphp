<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use FFI;
use FFI\CData;
use MyPHP\{MessageOptions, MessageResult, OpenDialogOptions, SaveDialogOptions, Window};
use MyPHP\Native\{Com, Mem};
use MyPHP\Platform;
use MyPHP\Platform\Unsupported;

/**
 * Windows's dialogs (mygo's windows dialog.go): files opened and saved
 * through the Common Item Dialog (IFileOpenDialog, IFileSaveDialog), modal
 * to their window or the app; messages as task dialogs (TaskDialogIndirect,
 * of version 6 of the common controls, which an activation context of
 * shell32's manifest brings to an executable without one, as PHP's), a
 * plain message box where there are none; prompts and logins as dialogs of
 * a template made here (form()), which upstream has none of on Windows.
 */
final class Dialogs implements Platform\Dialogs
{
    private const CLSID_OPEN = 'dc1c5a9c-e88a-4dde-a5a1-60f82a20aef7', IID_OPEN = 'd57c7288-d4ad-4768-be02-9d969532d960';
    private const CLSID_SAVE = 'c0b4e2f3-ba21-4773-8dba-335ec946eb8b', IID_SAVE = '84bccd23-5fde-4cdb-aea4-af64b83d78ab';
    private const IID_SHELLITEM = '43826d1e-e718-42ee-bc55-a1e261c37bfe';
    private const FOS_OVERWRITEPROMPT = 0x2, FOS_PICKFOLDERS = 0x20, FOS_FORCEFILESYSTEM = 0x40, FOS_ALLOWMULTISELECT = 0x200, FOS_PATHMUSTEXIST = 0x800, FOS_FILEMUSTEXIST = 0x1000, FOS_FORCESHOWHIDDEN = 0x10000000;
    private const SIGDN_FILESYSPATH = 0x80058000;
    private const FIRST_BUTTON = 100;

    private static ?FFI $f = null, $shell = null, $formFfi = null;
    /** TaskDialogIndirect, once: a struct holding it read through a pointer, as Com's vtables (docs/architecture.md); false where there is none. */
    private static CData|false|null $taskDialog = null;
    private static ?CData $taskDialogFns = null;

    public function __construct(private readonly Backend $b) {}

    private static function f(): FFI
    {
        return self::$f ??= FFI::cdef(<<<'C'
            typedef struct { void *name, *spec; } COMDLG_FILTERSPEC;
            typedef struct {
                void *r0[3];
                int32_t (*Show)(void *self, void *owner);
                int32_t (*SetFileTypes)(void *self, uint32_t n, const COMDLG_FILTERSPEC *specs);
                void *r5[4];
                int32_t (*SetOptions)(void *self, uint32_t options);
                int32_t (*GetOptions)(void *self, uint32_t *options);
                void *r11;
                int32_t (*SetFolder)(void *self, void *item);
                void *r13[2];
                int32_t (*SetFileName)(void *self, const void *name);
                void *r16;
                int32_t (*SetTitle)(void *self, const void *title);
                int32_t (*SetOkButtonLabel)(void *self, const void *label);
                int32_t (*SetFileNameLabel)(void *self, const void *label);
                int32_t (*GetResult)(void *self, void **item);
                void *r21[6];
                int32_t (*GetResults)(void *self, void **items);
            } IFileDialogVtbl;
            typedef struct { IFileDialogVtbl *v; } IFileDialog;
            typedef struct { void *r0[5]; int32_t (*GetDisplayName)(void *self, uint32_t sigdn, uint16_t **name); } IShellItemVtbl;
            typedef struct { IShellItemVtbl *v; } IShellItem;
            typedef struct { void *r0[7]; int32_t (*GetCount)(void *self, uint32_t *n); int32_t (*GetItemAt)(void *self, uint32_t i, void **item); } IShellItemArrayVtbl;
            typedef struct { IShellItemArrayVtbl *v; } IShellItemArray;
            typedef struct { int32_t (*fn)(const void *config, int32_t *pressed, int32_t *radio, int32_t *checked); } TaskDialog;
            int32_t CoCreateInstance(const void *clsid, void *outer, uint32_t context, const void *iid, void *out);
            void CoTaskMemFree(void *p);
            C, 'ole32.dll');
    }

    private static function shell(): FFI
    {
        return self::$shell ??= FFI::cdef('int32_t SHCreateItemFromParsingName(const void *path, void *bc, const void *iid, void *out);', 'shell32.dll');
    }

    /** The window a dialog is modal to, null for the app. */
    private static function owner(?Window $w): ?CData
    {
        $n = $w?->native();
        return $n instanceof \MyPHP\Platform\Windows\Window && !$n->isDead() ? $n->hwnd : null;
    }

    /** A Common Item Dialog of $clsid, as an IFileDialog, null where there is none. */
    private static function create(string $clsid, string $iid): ?CData
    {
        $f = self::f();
        $out = $f->new('void *');
        [$c, $i] = [Com::guid($clsid), Com::guid($iid)];
        if ($f->CoCreateInstance(FFI::addr($c), null, 1, FFI::addr($i), FFI::addr($out)) < 0 || FFI::isNull($out)) { // CLSCTX_INPROC_SERVER
            return null;
        }
        return Com::typed($f, $out, 'IFileDialog *');
    }

    /** The options, title, button, the name field's label, the folder and name it starts at, and the filters of a dialog (mygo's setupFileDialog). @param list<\MyPHP\FileFilter> $filters */
    private static function setup(CData $d, int $options, string $title, string $button, string $nameLabel, string $defaultPath, array $filters): void
    {
        $f = self::f();
        $now = $f->new('uint32_t');
        ($d->v->GetOptions)($d, FFI::addr($now));
        ($d->v->SetOptions)($d, $now->cdata | $options);
        if ($title !== '') { ($d->v->SetTitle)($d, Win32::wide($title)); }
        if ($button !== '') { ($d->v->SetOkButtonLabel)($d, Win32::wide(str_replace('_', '', $button))); }
        if ($nameLabel !== '') { ($d->v->SetFileNameLabel)($d, Win32::wide($nameLabel)); }
        if ($defaultPath !== '') {
            [$dir, $name] = is_dir($defaultPath) ? [$defaultPath, ''] : [dirname($defaultPath), basename($defaultPath)];
            $folder = $f->new('void *');
            $iid = Com::guid(self::IID_SHELLITEM);
            if (self::shell()->SHCreateItemFromParsingName(Win32::wide(str_replace('/', '\\', $dir)), null, FFI::addr($iid), FFI::addr($folder)) >= 0 && !FFI::isNull($folder)) {
                ($d->v->SetFolder)($d, $folder);
                Com::release($folder);
            }
            if ($name !== '') { ($d->v->SetFileName)($d, Win32::wide($name)); }
        }
        $specs = [];
        $strings = []; // kept alive until the call returns
        foreach ($filters as $filter) {
            $patterns = array_map(fn(string $e): string => $e === '*' ? '*.*' : '*.' . ltrim($e, '.'), $filter->extensions);
            if ($patterns !== []) {
                $specs[] = [$strings[] = Win32::wide($filter->name), $strings[] = Win32::wide(implode(';', $patterns))];
            }
        }
        if ($specs !== []) {
            $arr = $f->new('COMDLG_FILTERSPEC[' . count($specs) . ']');
            foreach ($specs as $i => [$name, $spec]) {
                $arr[$i]->name = $f->cast('void *', Mem::addr(FFI::addr($name[0])));
                $arr[$i]->spec = $f->cast('void *', Mem::addr(FFI::addr($spec[0])));
            }
            ($d->v->SetFileTypes)($d, count($specs), $arr);
        }
    }

    /** An IShellItem's path in the file system, '' for none. */
    private static function path(CData $item): string
    {
        $f = self::f();
        $it = Com::typed($f, $item, 'IShellItem *');
        $p = $f->new('uint16_t *');
        if (($it->v->GetDisplayName)($it, self::SIGDN_FILESYSPATH, FFI::addr($p)) < 0 || FFI::isNull($p)) {
            return '';
        }
        $s = Win32::str($p);
        $f->CoTaskMemFree($p);
        return $s;
    }

    /** Files or a folder chosen to open (mygo's ShowOpenDialog). */
    public function open(OpenDialogOptions $o): array
    {
        $f = self::f();
        $d = self::create(self::CLSID_OPEN, self::IID_OPEN) ?? throw new Unsupported('myphp: no file dialog on this Windows');
        try {
            $options = self::FOS_FORCEFILESYSTEM | self::FOS_PATHMUSTEXIST | ($o->directory ? self::FOS_PICKFOLDERS : self::FOS_FILEMUSTEXIST)
                | ($o->multiple ? self::FOS_ALLOWMULTISELECT : 0) | ($o->showHiddenFiles ? self::FOS_FORCESHOWHIDDEN : 0);
            self::setup($d, $options, $o->title, $o->buttonLabel, '', $o->defaultPath, $o->directory ? [] : $o->filters);
            if (($d->v->Show)($d, self::owner($o->parent)) < 0) {
                return []; // canceled
            }
            $out = $f->new('void *');
            if (($d->v->GetResults)($d, FFI::addr($out)) < 0 || FFI::isNull($out)) {
                return [];
            }
            $items = Com::typed($f, $out, 'IShellItemArray *');
            $n = $f->new('uint32_t');
            ($items->v->GetCount)($items, FFI::addr($n));
            $paths = [];
            for ($i = 0; $i < $n->cdata; $i++) {
                $item = $f->new('void *');
                if (($items->v->GetItemAt)($items, $i, FFI::addr($item)) >= 0 && !FFI::isNull($item)) {
                    if (($p = self::path($item)) !== '') { $paths[] = $p; }
                    Com::release($item);
                }
            }
            Com::release($out);
            return $paths;
        } finally {
            Com::release($d);
        }
    }

    /** A path chosen to save to, '' when canceled (mygo's ShowSaveDialog). */
    public function save(SaveDialogOptions $o): string
    {
        $f = self::f();
        $d = self::create(self::CLSID_SAVE, self::IID_SAVE) ?? throw new Unsupported('myphp: no file dialog on this Windows');
        try {
            $options = self::FOS_FORCEFILESYSTEM | self::FOS_OVERWRITEPROMPT | self::FOS_PATHMUSTEXIST | ($o->showHiddenFiles ? self::FOS_FORCESHOWHIDDEN : 0);
            self::setup($d, $options, $o->title, $o->buttonLabel, $o->nameFieldLabel, $o->defaultPath, $o->filters);
            if (($d->v->Show)($d, self::owner($o->parent)) < 0) {
                return '';
            }
            $item = $f->new('void *');
            if (($d->v->GetResult)($d, FFI::addr($item)) < 0 || FFI::isNull($item)) {
                return '';
            }
            $path = self::path($item);
            Com::release($item);
            return $path;
        } finally {
            Com::release($d);
        }
    }

    /**
     * A task dialog of its buttons, $o's message over its detail, its icon
     * of its type, its check box, Escape and the close button its $cancel
     * one; a plain message box where there are no task dialogs (mygo's
     * messageBox).
     */
    public function message(MessageOptions $o, array $buttons, int $cancel): MessageResult
    {
        $buttons = $buttons === [] ? ['OK'] : array_values($buttons);
        $fn = self::taskDialog();
        $owner = self::owner($o->parent);
        if ($fn === null) {
            return self::messageBox($owner, $o, $buttons, $cancel);
        }
        $keep = []; // every string alive until TaskDialogIndirect returns
        $str = function (string $s) use (&$keep): int {
            $keep[] = $w = Win32::wide($s);
            return Mem::addr(FFI::addr($w[0]));
        };
        // TASKDIALOG_BUTTON and TASKDIALOGCONFIG are packed (pshpack1.h): laid out by hand, their pointers not 8-byte aligned (mygo's).
        $btns = '';
        foreach ($buttons as $i => $label) {
            $btns .= pack('lP', self::FIRST_BUTTON + $i, $str($label));
        }
        $keep[] = $bbuf = self::f()->new('uint8_t[' . strlen($btns) . ']');
        FFI::memcpy($bbuf, $btns, strlen($btns));
        $flags = 0x01000000 | ($cancel >= 0 ? 0x8 : 0) | ($owner !== null ? 0x1000 : 0) | ($o->checkboxChecked ? 0x100 : 0); // TDF_SIZE_TO_CONTENT, TDF_ALLOW_DIALOG_CANCELLATION, TDF_POSITION_RELATIVE_TO_WINDOW, TDF_VERIFICATION_FLAG_CHECKED
        $icon = match ($o->type) { 'warning' => 0xFFFF, 'error' => 0xFFFE, 'info', 'question' => 0xFFFD, default => 0 }; // TD_WARNING_ICON, TD_ERROR_ICON, TD_INFORMATION_ICON
        $cfg = str_repeat("\0", 160);
        $put = function (int $at, string $bytes) use (&$cfg): void { $cfg = substr_replace($cfg, $bytes, $at, strlen($bytes)); };
        $put(0, pack('V', 160));
        $put(4, pack('P', Mem::addr($owner)));
        $put(12, pack('P', Mem::addr(Win32::instance())));
        $put(20, pack('V', $flags));
        $put(28, pack('P', $str($o->title !== '' ? $o->title : \MyPHP\App::name())));
        $put(36, pack('P', $icon));
        $put(44, pack('P', $str($o->message)));
        if ($o->detail !== '') { $put(52, pack('P', $str($o->detail))); }
        $put(60, pack('V', count($buttons)));
        $put(64, pack('P', Mem::addr(FFI::addr($bbuf[0]))));
        $put(72, pack('V', self::FIRST_BUTTON + max(0, min($o->defaultButton, count($buttons) - 1))));
        if ($o->checkboxLabel !== '') { $put(92, pack('P', $str($o->checkboxLabel))); }
        $f = self::f();
        $config = $f->new('uint8_t[160]');
        FFI::memcpy($config, $cfg, 160);
        $pressed = $f->new('int32_t'); $radio = $f->new('int32_t'); $checked = $f->new('int32_t');
        $hr = ($fn->fn)($config, FFI::addr($pressed), FFI::addr($radio), FFI::addr($checked));
        $p = $pressed->cdata - self::FIRST_BUTTON;
        return new MessageResult($hr >= 0 && $p >= 0 && $p < count($buttons) ? $p : $cancel, $checked->cdata !== 0);
    }

    /**
     * TaskDialogIndirect, of version 6 of the common controls, which an
     * activation context of shell32.dll's manifest (its resource 124) brings
     * to an executable without one, as PHP's (mygo's activateCommonControls);
     * null where there is none.
     */
    private static function taskDialog(): ?CData
    {
        if (self::$taskDialog === null) {
            self::$taskDialog = false;
            $k = FFI::cdef(<<<'C'
                typedef struct { uint32_t cbSize, dwFlags; const void *lpSource; uint16_t wProcessorArchitecture, wLangId; const void *lpAssemblyDirectory; uintptr_t lpResourceName; const void *lpApplicationName; void *hModule; } ACTCTXW;
                void *CreateActCtxW(const ACTCTXW *ctx);
                int32_t ActivateActCtx(void *ctx, uintptr_t *cookie);
                void *LoadLibraryExW(const void *name, void *file, uint32_t flags);
                void *GetProcAddress(void *module, const char *name);
                uint32_t GetSystemDirectoryW(uint16_t *buf, uint32_t n);
                C, 'kernel32.dll');
            $buf = $k->new('uint16_t[260]');
            $n = $k->GetSystemDirectoryW($buf, 260);
            $sys = (string)iconv('UTF-16LE', 'UTF-8', FFI::string($buf, $n * 2));
            $ctx = $k->new('ACTCTXW');
            $ctx->cbSize = FFI::sizeof($ctx);
            $ctx->dwFlags = 0x8 | 0x4; // ACTCTX_FLAG_RESOURCE_NAME_VALID, ACTCTX_FLAG_ASSEMBLY_DIRECTORY_VALID
            [$source, $dir] = [Win32::wide("$sys\\shell32.dll"), Win32::wide($sys)];
            $ctx->lpSource = $k->cast('void *', Mem::addr(FFI::addr($source[0])));
            $ctx->lpAssemblyDirectory = $k->cast('void *', Mem::addr(FFI::addr($dir[0])));
            $ctx->lpResourceName = 124;
            $h = $k->CreateActCtxW(FFI::addr($ctx));
            if ($h !== null && Mem::addr($h) !== -1 && Mem::addr($h) !== PHP_INT_MAX) {
                $cookie = $k->new('uintptr_t');
                $k->ActivateActCtx($h, FFI::addr($cookie));
            }
            $lib = $k->LoadLibraryExW(Win32::wide('comctl32.dll'), null, 0x800); // LOAD_LIBRARY_SEARCH_SYSTEM32, under the context: version 6
            $proc = $lib === null ? null : $k->GetProcAddress($lib, 'TaskDialogIndirect');
            if ($proc !== null) {
                $f = self::f();
                self::$taskDialogFns = $f->new('TaskDialog', false);
                FFI::memcpy(self::$taskDialogFns, pack('P', Mem::addr($proc)), 8);
                self::$taskDialog = Com::typed($f, FFI::addr(self::$taskDialogFns), 'TaskDialog *');
            }
        }
        return self::$taskDialog === false ? null : self::$taskDialog;
    }

    /** A plain message box, where there are no task dialogs: OK, OK and Cancel, or Yes, No and Cancel by the buttons' count (mygo's legacyMessageBox). @param list<string> $buttons */
    private static function messageBox(?CData $owner, MessageOptions $o, array $buttons, int $cancel): MessageResult
    {
        $u = FFI::cdef('int32_t MessageBoxW(void *hwnd, const void *text, const void *caption, uint32_t type);', 'user32.dll');
        $style = match (count($buttons)) { 2 => 0x1, 3 => 0x3, default => 0x0 } | match ($o->type) { 'error' => 0x10, 'warning' => 0x30, 'info', 'question' => 0x40, default => 0 };
        $text = $o->detail === '' ? $o->message : "{$o->message}\n\n{$o->detail}";
        $r = $u->MessageBoxW($owner, Win32::wide($text), Win32::wide($o->title), $style);
        $button = match ($r) { 1, 6 => 0, 7 => 1, 2 => count($buttons) >= 2 ? count($buttons) - 1 : $cancel, default => $cancel }; // IDOK, IDYES; IDNO; IDCANCEL
        return new MessageResult($button, false);
    }

    /** A prompt: $prompt over $detail and a field of $default, $check's complaint ('' when none) under it as it is typed, OK disabled while there is one; the text, null when canceled. */
    public function prompt(?Window $window, string $prompt, string $default, string $detail = '', ?\Closure $check = null): ?string
    {
        return self::form($window, $prompt, $detail, [['text' => $default, 'cue' => '', 'secret' => false]], $check)[0] ?? null;
    }

    /** A user's name and password: $prompt over $detail and a field of each, $user and $password their placeholders, the password's hidden; null when canceled. @return ?array{0: string, 1: string} */
    public function login(?Window $window, string $prompt, string $detail, string $user, string $password): ?array
    {
        $got = self::form($window, $prompt, $detail, [['text' => '', 'cue' => $user, 'secret' => false], ['text' => '', 'cue' => $password, 'secret' => true]], null);
        return $got === null ? null : [$got[0], $got[1]];
    }

    /** A page's file input: an open dialog over its window, null when canceled (WebView2 shows its own; a page of another engine asks here). @return ?list<string> */
    public function chooseForPage(Window $window, bool $multiple, bool $dirs): ?array
    {
        $paths = $this->open(new OpenDialogOptions($window, directory: $dirs, multiple: $multiple));
        return $paths === [] ? null : $paths;
    }

    private const FORM_FIELD = 10, FORM_ERROR = 20, IDOK = 1, IDCANCEL = 2;
    private const WM_INITDIALOG = 0x0110, WM_COMMAND = 0x0111, EN_CHANGE = 0x0300, EM_SETCUEBANNER = 0x1501;
    /** The dialog procedure, once (a struct holding it, as Win32's window procedure), and the form it runs now: a form is modal, one at a time. */
    private static ?CData $formProc = null;
    private static ?\Closure $formHandler = null;

    /**
     * A dialog of $prompt, $detail and a field of each of $fields, OK and
     * Cancel (DialogBoxIndirectParamW of a template made here: the dialog
     * manager's loop, Tab, Enter and Escape, its controls version 6's under
     * the activation context taskDialog() brings, cue banners theirs). Its
     * size is estimated from the texts' widths in dialog units (an average
     * character 4 wide, a wide one 8, a line 9 high). The fields' texts, null
     * when canceled. @param list<array{text: string, cue: string, secret: bool}> $fields @return ?list<string>
     */
    private static function form(?Window $window, string $prompt, string $detail, array $fields, ?\Closure $check): ?array
    {
        self::taskDialog();
        $u = self::formFfi();
        $t = \MyPHP\L10n::in(\MyPHP\App::locale());
        [$width, $margin] = [262, 7];
        $inner = $width - 2 * $margin;
        $lines = function (string $s) use ($inner): int {
            $n = 0;
            foreach (explode("\n", $s) as $line) { $n += max(1, (int)ceil(mb_strwidth($line) * 4 / $inner)); }
            return $n;
        };
        $items = [];
        $item = function (int $style, int $ex, int $x, int $y, int $w, int $h, int $id, int $atom, string $text) use (&$items): void {
            $items[] = [$style, $ex, $x, $y, $w, $h, $id, $atom, $text];
        };
        [$static, $child] = [0x50000080, 0x50010000]; // WS_CHILD | WS_VISIBLE, SS_NOPREFIX; WS_TABSTOP
        $y = $margin;
        foreach ([$prompt, $detail] as $text) {
            if ($text !== '') {
                $h = $lines($text) * 9;
                $item($static, 0, $margin, $y, $inner, $h, 0xFFFF, 0x82, $text);
                $y += $h + 5;
            }
        }
        foreach ($fields as $i => $field) {
            $item($child | 0x80 | ($field['secret'] ? 0x20 : 0), 0x200, $margin, $y, $inner, 14, self::FORM_FIELD + $i, 0x81, $field['text']); // ES_AUTOHSCROLL, ES_PASSWORD; WS_EX_CLIENTEDGE
            $y += 19;
        }
        if ($check !== null) {
            $item($static, 0, $margin, $y, $inner, 18, self::FORM_ERROR, 0x82, '');
            $y += 21;
        }
        $y += 2;
        $item($child | 0x1, 0, $width - $margin - 104, $y, 50, 14, self::IDOK, 0x80, $t->get('ok')); // BS_DEFPUSHBUTTON
        $item($child, 0, $width - $margin - 50, $y, 50, 14, self::IDCANCEL, 0x80, $t->get('cancel'));
        $height = $y + 14 + $margin;
        $wide = fn(string $s): string => (string)iconv('UTF-8', 'UTF-16LE', $s) . "\0\0";
        // DLGTEMPLATE: WS_POPUP | WS_CAPTION | WS_SYSMENU, DS_MODALFRAME | DS_SHELLFONT | DS_SETFOREGROUND | DS_CENTER; then each DLGITEMTEMPLATE on a DWORD.
        $tpl = pack('VVvvvvv', 0x80C80000 | 0x80 | 0x48 | 0x200 | 0x800, 0, count($items), 0, 0, $width, $height) . "\0\0\0\0" . $wide(\MyPHP\App::name()) . pack('v', 9) . $wide('Segoe UI');
        foreach ($items as [$style, $ex, $x, $iy, $w, $h, $id, $atom, $text]) {
            $tpl .= str_repeat("\0", (4 - strlen($tpl) % 4) % 4) . pack('VVvvvvvvv', $style, $ex, $x, $iy, $w, $h, $id, 0xFFFF, $atom) . $wide($text) . "\0\0";
        }
        $buf = $u->new('uint32_t[' . intdiv(strlen($tpl) + 3, 4) . ']');
        FFI::memcpy($buf, $tpl, strlen($tpl));

        $text = function (CData $dlg, int $id) use ($u): string {
            $n = $u->GetWindowTextLengthW($u->GetDlgItem($dlg, $id));
            $out = $u->new('uint16_t[' . ($n + 1) . ']');
            $u->GetDlgItemTextW($dlg, $id, $out, $n + 1);
            return Win32::str($out, $n);
        };
        $wrong = function (CData $dlg) use ($check, $text, $u): string {
            if ($check === null) { return ''; }
            $complaint = $check($text($dlg, self::FORM_FIELD));
            $u->SetDlgItemTextW($dlg, self::FORM_ERROR, Win32::wide($complaint));
            $u->EnableWindow($u->GetDlgItem($dlg, self::IDOK), $complaint === '' ? 1 : 0);
            return $complaint;
        };
        $got = null;
        self::$formHandler = function (CData $dlg, int $msg, int $wp) use ($fields, $u, $text, $wrong, &$got): int {
            if ($msg === self::WM_INITDIALOG) {
                foreach ($fields as $i => $field) {
                    if ($field['cue'] !== '') { $u->SendMessageW($u->GetDlgItem($dlg, self::FORM_FIELD + $i), self::EM_SETCUEBANNER, 1, Mem::addr(FFI::addr(Win32::wide($field['cue'])[0]))); }
                }
                $wrong($dlg);
                return 1; // the first field focused
            }
            if ($msg !== self::WM_COMMAND) { return 0; }
            [$id, $code] = [$wp & 0xFFFF, ($wp >> 16) & 0xFFFF];
            if ($id === self::FORM_FIELD && $code === self::EN_CHANGE) {
                $wrong($dlg);
            } elseif ($id === self::IDOK && $wrong($dlg) === '') {
                $got = array_map(fn(int $i) => $text($dlg, self::FORM_FIELD + $i), array_keys($fields));
                $u->EndDialog($dlg, self::IDOK);
            } elseif ($id === self::IDCANCEL) {
                $u->EndDialog($dlg, self::IDCANCEL);
            } else {
                return 0;
            }
            return 1;
        };
        try {
            $r = $u->DialogBoxIndirectParamW(Win32::instance(), $buf, self::owner($window), self::formProc()->fn, 0);
        } finally {
            self::$formHandler = null;
        }
        if ($r === -1 || $r === 0) {
            throw new \RuntimeException('myphp: cannot show a dialog (error ' . Win32::kernel32()->GetLastError() . ')');
        }
        return $got;
    }

    private static function formFfi(): FFI
    {
        return self::$formFfi ??= FFI::cdef(<<<'C'
            typedef intptr_t (*DLGPROC)(void *hwnd, uint32_t msg, uintptr_t wp, intptr_t lp);
            typedef struct { DLGPROC fn; } DlgProc;
            intptr_t DialogBoxIndirectParamW(void *inst, const void *tmpl, void *owner, DLGPROC proc, intptr_t param);
            int32_t EndDialog(void *hwnd, intptr_t result);
            void *GetDlgItem(void *hwnd, int32_t id);
            int32_t SetDlgItemTextW(void *hwnd, int32_t id, const void *text);
            uint32_t GetDlgItemTextW(void *hwnd, int32_t id, uint16_t *text, int32_t max);
            int32_t GetWindowTextLengthW(void *hwnd);
            int32_t EnableWindow(void *hwnd, int32_t enable);
            intptr_t SendMessageW(void *hwnd, uint32_t msg, uintptr_t wp, intptr_t lp);
            C, 'user32.dll');
    }

    private static function formProc(): CData
    {
        if (self::$formProc === null) {
            self::$formProc = self::formFfi()->new('DlgProc', false);
            self::$formProc->fn = static fn($hwnd, int $msg, int $wp, int $lp): int => self::$formHandler !== null ? (self::$formHandler)($hwnd, $msg, $wp) : 0;
        }
        return self::$formProc;
    }
}
