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
 * plain message box where there are none.
 */
final class Dialogs implements Platform\Dialogs
{
    private const CLSID_OPEN = 'dc1c5a9c-e88a-4dde-a5a1-60f82a20aef7', IID_OPEN = 'd57c7288-d4ad-4768-be02-9d969532d960';
    private const CLSID_SAVE = 'c0b4e2f3-ba21-4773-8dba-335ec946eb8b', IID_SAVE = '84bccd23-5fde-4cdb-aea4-af64b83d78ab';
    private const IID_SHELLITEM = '43826d1e-e718-42ee-bc55-a1e261c37bfe';
    private const FOS_OVERWRITEPROMPT = 0x2, FOS_PICKFOLDERS = 0x20, FOS_FORCEFILESYSTEM = 0x40, FOS_ALLOWMULTISELECT = 0x200, FOS_PATHMUSTEXIST = 0x800, FOS_FILEMUSTEXIST = 0x1000, FOS_FORCESHOWHIDDEN = 0x10000000;
    private const SIGDN_FILESYSPATH = 0x80058000;
    private const FIRST_BUTTON = 100;

    private static ?FFI $f = null, $shell = null;
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

    public function prompt(?Window $window, string $prompt, string $default, string $detail = '', ?\Closure $check = null): ?string
    {
        throw new Unsupported('myphp: a prompt is not on Windows yet');
    }

    public function login(?Window $window, string $prompt, string $detail, string $user, string $password): ?array
    {
        throw new Unsupported('myphp: a login dialog is not on Windows yet');
    }

    public function chooseForPage(Window $window, bool $multiple, bool $dirs): ?array
    {
        throw new Unsupported('myphp: web pages are not on Windows yet');
    }
}
