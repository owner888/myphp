<?php
declare(strict_types=1);

namespace MyPHP;

use MyPHP\Native\ObjC;

/**
 * System dialogs, as mygo's Dialog module: open and save panels and
 * message boxes. With a parent window they show as its sheet, otherwise
 * app-modal; either way the call returns when the dialog closes.
 */
final class Dialog
{
    private const MODAL_OK = 1;
    private const FIRST_BUTTON = 1000;
    private const STYLE = ['warning' => 0, 'info' => 1, 'none' => 1, 'question' => 1, 'error' => 2];
    /** The block prompt()'s field's changes call, and the check it calls while a prompt is up. */
    private static mixed $textBlock = null;
    private static ?\Closure $textChanged = null;
    /** The monitor of the keys that cancel alerts, made with the first; the cancel button of each alert's window showing, by the window's address. @var array<int, mixed> */
    private static mixed $alertKeys = null;
    private static array $alertCancels = [];

    /** @return string[] chosen paths; empty when canceled */
    public static function open(OpenDialogOptions $o = new OpenDialogOptions()): array
    {
        $panel = ObjC::msg('p:', ObjC::cls('NSOpenPanel'), 'openPanel');
        ObjC::msg('v:b', $panel, 'setCanChooseFiles:', !$o->directory);
        ObjC::msg('v:b', $panel, 'setCanChooseDirectories:', $o->directory);
        ObjC::msg('v:b', $panel, 'setAllowsMultipleSelection:', $o->multiple);
        ObjC::msg('v:b', $panel, 'setShowsHiddenFiles:', $o->showHiddenFiles);
        ObjC::msg('v:b', $panel, 'setCanCreateDirectories:', $o->createDirectories);
        self::common($panel, $o->title, $o->message, $o->buttonLabel, $o->defaultPath, $o->filters);
        if (self::run($panel, $o->parent) !== self::MODAL_OK) {
            return [];
        }
        $urls = ObjC::msg('p:', $panel, 'URLs');
        $paths = [];
        for ($i = 0, $n = ObjC::msg('L:', $urls, 'count'); $i < $n; $i++) {
            $paths[] = ObjC::phpStr(ObjC::msg('p:', ObjC::msg('p:L', $urls, 'objectAtIndex:', $i), 'path'));
        }
        return $paths;
    }

    /** @return string the chosen path, or '' when canceled */
    public static function save(SaveDialogOptions $o = new SaveDialogOptions()): string
    {
        $panel = ObjC::msg('p:', ObjC::cls('NSSavePanel'), 'savePanel');
        ObjC::msg('v:b', $panel, 'setShowsHiddenFiles:', $o->showHiddenFiles);
        ObjC::msg('v:b', $panel, 'setCanCreateDirectories:', $o->createDirectories);
        if ($o->nameFieldLabel !== '') {
            ObjC::msg('v:p', $panel, 'setNameFieldLabel:', ObjC::str($o->nameFieldLabel));
        }
        self::common($panel, $o->title, $o->message, $o->buttonLabel, $o->defaultPath, $o->filters);
        if (self::run($panel, $o->parent) !== self::MODAL_OK) {
            return '';
        }
        return ObjC::phpStr(ObjC::msg('p:', ObjC::msg('p:', $panel, 'URL'), 'path'));
    }

    /** Shows a panel or alert as the parent's sheet, or app-modal, and returns its response. */
    private static function run($panel, ?Window $parent): int
    {
        if ($parent === null) {
            return ObjC::msg('l:', $panel, 'runModal');
        }
        $response = null;
        ObjC::msg('v:pp', $panel, 'beginSheetModalForWindow:completionHandler:', $parent->nativeHandle(),
            ObjC::block('l', function (int $r) use (&$response) { $response = $r; }));
        App::wait(function () use (&$response) { return $response !== null; }); // by reference: an arrow function would keep the null it was made with
        return $response;
    }

    private static function common($panel, string $title, string $message, string $button, string $defaultPath, array $filters): void
    {
        if ($title !== '') {
            ObjC::msg('v:p', $panel, 'setTitle:', ObjC::str($title));
        }
        if ($message !== '') {
            ObjC::msg('v:p', $panel, 'setMessage:', ObjC::str($message));
        }
        if ($button !== '') {
            ObjC::msg('v:p', $panel, 'setPrompt:', ObjC::str($button));
        }
        if ($defaultPath !== '') {
            $dir = is_dir($defaultPath) ? $defaultPath : dirname($defaultPath);
            ObjC::msg('v:p', $panel, 'setDirectoryURL:', ObjC::msg('p:p', ObjC::cls('NSURL'), 'fileURLWithPath:', ObjC::str($dir)));
            if (!is_dir($defaultPath)) {
                ObjC::msg('v:p', $panel, 'setNameFieldStringValue:', ObjC::str(basename($defaultPath)));
            }
        }
        if ($filters !== []) {
            $types = ObjC::msg('p:', ObjC::cls('NSMutableArray'), 'array');
            foreach ($filters as $f) {
                foreach ($f->extensions as $ext) {
                    $t = ObjC::msg('p:p', ObjC::cls('UTType'), 'typeWithFilenameExtension:', ObjC::str(ltrim($ext, '.')));
                    if ($t !== null) {
                        ObjC::msg('v:p', $types, 'addObject:', $t);
                    }
                }
            }
            ObjC::msg('v:p', $panel, 'setAllowedContentTypes:', $types);
        }
    }

    public static function message(MessageOptions $o): MessageResult
    {
        $buttons = $o->buttons ?: [L10n::in(App::locale())->get('ok')];
        $alert = self::alert($o->type, $o->message !== '' ? $o->message : $o->title, $o->detail, $buttons, $o->defaultButton, $o->destructiveButtons);
        if ($o->checkboxLabel !== '') {
            ObjC::msg('v:b', $alert, 'setShowsSuppressionButton:', true);
            $box = ObjC::msg('p:', $alert, 'suppressionButton');
            ObjC::msg('v:p', $box, 'setTitle:', ObjC::str($o->checkboxLabel));
            ObjC::msg('v:l', $box, 'setState:', $o->checkboxChecked ? 1 : 0);
        }
        $code = self::runAlert($alert, $o->parent, $o->cancelButton >= 0 ? $o->cancelButton : self::cancelOf($buttons));
        $checked = $o->checkboxLabel !== '' && ObjC::msg('l:', ObjC::msg('p:', $alert, 'suppressionButton'), 'state') === 1;
        return new MessageResult($code - self::FIRST_BUTTON, $checked);
    }

    /** @internal the first of $buttons labeled Cancel or No, in English or the app's language, or -1 (mygo's Message). @param string[] $buttons */
    public static function cancelOf(array $buttons): int
    {
        $cancel = L10n::in(App::locale())->get('cancel');
        foreach ($buttons as $i => $b) {
            if (in_array(strtolower($b), ['cancel', 'no'], true) || $b === $cancel) { return $i; }
        }
        return -1;
    }

    /**
     * @internal an NSAlert of $buttons, retained: Return activates
     * $default's (none for -1), no other a key of its own, as Escape is
     * runAlert's; $destructive's are set apart, on macOS 11 and later
     * (mygo's newAlert). @param string[] $buttons @param int[] $destructive
     */
    public static function alert(string $type, string $message, string $detail, array $buttons, int $default, array $destructive = []): mixed
    {
        $alert = ObjC::msg('p:', ObjC::alloc('NSAlert'), 'init');
        ObjC::msg('v:l', $alert, 'setAlertStyle:', self::STYLE[$type] ?? 1);
        ObjC::msg('v:p', $alert, 'setMessageText:', ObjC::str($message));
        if ($detail !== '') {
            ObjC::msg('v:p', $alert, 'setInformativeText:', ObjC::str($detail));
        }
        $items = [];
        foreach ($buttons as $i => $label) {
            $items[] = $b = ObjC::msg('p:p', $alert, 'addButtonWithTitle:', ObjC::str($label));
            ObjC::msg('v:p', $b, 'setKeyEquivalent:', ObjC::str($i === $default ? "\r" : ''));
        }
        foreach ($destructive as $i) {
            if (isset($items[$i]) && ObjC::msg('b:p', $items[$i], 'respondsToSelector:', ObjC::sel('setHasDestructiveAction:'))) {
                ObjC::msg('v:b', $items[$i], 'setHasDestructiveAction:', true);
            }
        }
        return $alert;
    }

    /** Runs $alert as run() does, Escape and Cmd+. activating its button $cancel (none for -1), also when it is the default, which takes Return as its key (mygo's runAlert). */
    private static function runAlert($alert, ?Window $parent, int $cancel): int
    {
        $win = ObjC::addr(ObjC::msg('p:', $alert, 'window'));
        $items = ObjC::msg('p:', $alert, 'buttons');
        if ($cancel >= 0 && $cancel < ObjC::msg('L:', $items, 'count')) {
            self::$alertCancels[$win] = ObjC::msg('p:L', $items, 'objectAtIndex:', $cancel);
            self::watchAlertKeys();
        }
        try {
            return self::run($alert, $parent);
        } finally {
            unset(self::$alertCancels[$win]);
        }
    }

    /** @internal whether a key typed with $mods (NSEvent's modifier flags) as $chars (ignoring them) cancels an alert: Escape alone, or Cmd+., Shift allowed as it may type the period, as on AZERTY. */
    public static function cancels(int $mods, string $chars): bool
    {
        $mods &= 1 << 17 | 1 << 18 | 1 << 19 | 1 << 20; // Shift, Control, Option, Command
        return $mods === 0 && $chars === "\e" || ($mods & ~(1 << 17)) === 1 << 20 && $chars === '.';
    }

    /** Sees the keys of the alerts before them, as a button has one key, Return's when the cancel is the default: made once. */
    private static function watchAlertKeys(): void
    {
        if (self::$alertKeys !== null) { return; }
        self::$alertKeys = ObjC::msg('p:Lp', ObjC::cls('NSEvent'), 'addLocalMonitorForEventsMatchingMask:handler:', 1 << 10, ObjC::block('p', function ($ev) { // NSEventMaskKeyDown
            $win = ObjC::msg('p:', $ev, 'window');
            $btn = $win === null ? null : self::$alertCancels[ObjC::addr($win)] ?? null;
            if ($btn === null) { return $ev; }
            // A key typed while the input method composes in a prompt's field is the input method's, as Escape giving the composition up.
            $field = ObjC::msg('p:', $win, 'firstResponder');
            if ($field !== null && ObjC::msg('b:p', $field, 'respondsToSelector:', ObjC::sel('hasMarkedText')) && ObjC::msg('b:', $field, 'hasMarkedText')) { return $ev; }
            if (!self::cancels(ObjC::msg('L:', $ev, 'modifierFlags'), ObjC::phpStr(ObjC::msg('p:', $ev, 'charactersIgnoringModifiers')))) { return $ev; }
            ObjC::msg('v:p', $btn, 'performClick:', null);
            return null;
        }, false, 'p'));
    }

    /**
     * @internal an alert with a text field, as $window's sheet (app-modal
     * without one): the text, or null when canceled. A page's prompt()
     * (mygo's jsPrompt) is $prompt alone; IINA's quickPromptPanel has
     * $detail under it and $check, what is wrong with the text ('' when
     * nothing), shown under the field as it is typed, OK disabled while
     * it is anything.
     */
    public static function prompt(?Window $window, string $prompt, string $default, string $detail = '', ?\Closure $check = null): ?string
    {
        $t = L10n::in(App::locale());
        $alert = self::alert('info', $prompt, $detail, [$t->get('ok'), $t->get('cancel')], 0);
        $ok = ObjC::msg('p:L', ObjC::msg('p:', $alert, 'buttons'), 'objectAtIndex:', 0);
        $field = ObjC::msg('p:r', ObjC::alloc('NSTextField'), 'initWithFrame:', ObjC::rect(0, $check === null ? 0 : 22, 280, 24));
        ObjC::msg('v:p', $field, 'setStringValue:', ObjC::str($default));
        $observer = null;
        if ($check === null) {
            $view = $field;
        } else { // the field over a small secondary label of what is wrong (IINA's)
            $view = ObjC::msg('p:r', ObjC::alloc('NSView'), 'initWithFrame:', ObjC::rect(0, 0, 280, 46));
            $label = ObjC::msg('p:p', ObjC::cls('NSTextField'), 'labelWithString:', ObjC::str(''));
            ObjC::msg('v:r', $label, 'setFrame:', ObjC::rect(0, 0, 280, 16));
            ObjC::msg('v:p', $label, 'setTextColor:', ObjC::msg('p:', ObjC::cls('NSColor'), 'secondaryLabelColor'));
            ObjC::msg('v:p', $label, 'setFont:', ObjC::msg('p:d', ObjC::cls('NSFont'), 'systemFontOfSize:', ObjC::msg('d:', ObjC::cls('NSFont'), 'smallSystemFontSize')));
            ObjC::msg('v:p', $view, 'addSubview:', $field);
            ObjC::msg('v:p', $view, 'addSubview:', $label);
            $validate = function () use ($check, $field, $label, $ok): void {
                $wrong = $check(ObjC::phpStr(ObjC::msg('p:', $field, 'stringValue')));
                ObjC::msg('v:p', $label, 'setStringValue:', ObjC::str($wrong));
                ObjC::msg('v:b', $ok, 'setEnabled:', $wrong === '');
            };
            self::$textChanged = $validate;
            self::$textBlock ??= ObjC::block('p', static function ($note): void { (self::$textChanged)(); }, false); // one for the process, the prompt's check through $textChanged
            $observer = ObjC::msg('p:pppp', ObjC::msg('p:', ObjC::cls('NSNotificationCenter'), 'defaultCenter'), 'addObserverForName:object:queue:usingBlock:',
                ObjC::str('NSControlTextDidChangeNotification'), $field, null, self::$textBlock);
            $validate();
        }
        ObjC::msg('v:p', $alert, 'setAccessoryView:', $view);
        ObjC::msg('v:p', ObjC::msg('p:', $alert, 'window'), 'setInitialFirstResponder:', $field);
        $text = self::runAlert($alert, $window, 1) === self::FIRST_BUTTON ? ObjC::phpStr(ObjC::msg('p:', $field, 'stringValue')) : null;
        if ($observer !== null) {
            ObjC::msg('v:p', ObjC::msg('p:', ObjC::cls('NSNotificationCenter'), 'defaultCenter'), 'removeObserver:', $observer);
            self::$textChanged = null;
            ObjC::msg('v:', $view, 'release');
        }
        ObjC::msg('v:', $field, 'release');
        ObjC::msg('v:', $alert, 'release');
        return $text;
    }

    /**
     * @internal an alert asking for a user's name and password, as $window's
     * sheet (app-modal without one), as IINA's quickUsernamePasswordPanel:
     * $prompt over $detail, a field of each, $user's and $password's their
     * placeholders, the password's a secure one. Both, or null when
     * canceled. @return ?array{0: string, 1: string}
     */
    public static function login(?Window $window, string $prompt, string $detail, string $user, string $password): ?array
    {
        $t = L10n::in(App::locale());
        $alert = self::alert('info', $prompt, $detail, [$t->get('ok'), $t->get('cancel')], 0);
        $view = ObjC::msg('p:r', ObjC::alloc('NSView'), 'initWithFrame:', ObjC::rect(0, 0, 280, 54));
        $fields = [];
        foreach ([['NSTextField', $user, 30], ['NSSecureTextField', $password, 0]] as [$cls, $placeholder, $y]) {
            $f = ObjC::msg('p:r', ObjC::alloc($cls), 'initWithFrame:', ObjC::rect(0, $y, 280, 24));
            ObjC::msg('v:p', $f, 'setPlaceholderString:', ObjC::str($placeholder));
            ObjC::msg('v:p', $view, 'addSubview:', $f);
            $fields[] = $f;
        }
        ObjC::msg('v:p', $fields[0], 'setNextKeyView:', $fields[1]); // Tab from the name to the password
        ObjC::msg('v:p', $alert, 'setAccessoryView:', $view);
        ObjC::msg('v:p', ObjC::msg('p:', $alert, 'window'), 'setInitialFirstResponder:', $fields[0]);
        $got = self::runAlert($alert, $window, 1) === self::FIRST_BUTTON ? [ObjC::phpStr(ObjC::msg('p:', $fields[0], 'stringValue')), ObjC::phpStr(ObjC::msg('p:', $fields[1], 'stringValue'))] : null;
        foreach ([...$fields, $view, $alert] as $o) { ObjC::msg('v:', $o, 'release'); }
        return $got;
    }

    /** @internal a page's file input: files, folders too with $dirs, several with $multiple, as $window's sheet; null when canceled (mygo's jsFileInput) @return ?list<string> */
    public static function chooseForPage(Window $window, bool $multiple, bool $dirs): ?array
    {
        $panel = ObjC::msg('p:', ObjC::cls('NSOpenPanel'), 'openPanel');
        ObjC::msg('v:b', $panel, 'setCanChooseFiles:', true);
        ObjC::msg('v:b', $panel, 'setCanChooseDirectories:', $dirs);
        ObjC::msg('v:b', $panel, 'setAllowsMultipleSelection:', $multiple);
        if (self::run($panel, $window) !== self::MODAL_OK) {
            return null;
        }
        $urls = ObjC::msg('p:', $panel, 'URLs');
        $paths = [];
        for ($i = 0, $n = ObjC::msg('L:', $urls, 'count'); $i < $n; $i++) {
            $paths[] = ObjC::phpStr(ObjC::msg('p:', ObjC::msg('p:L', $urls, 'objectAtIndex:', $i), 'path'));
        }
        return $paths;
    }

    public static function error(string $title, string $content): void
    {
        self::message(new MessageOptions(type: 'error', message: $title, detail: $content));
    }
}
