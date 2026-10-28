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
        $alert = ObjC::msg('p:', ObjC::alloc('NSAlert'), 'init');
        ObjC::msg('v:l', $alert, 'setAlertStyle:', self::STYLE[$o->type] ?? 1);
        ObjC::msg('v:p', $alert, 'setMessageText:', ObjC::str($o->message !== '' ? $o->message : $o->title));
        if ($o->detail !== '') {
            ObjC::msg('v:p', $alert, 'setInformativeText:', ObjC::str($o->detail));
        }
        $buttons = $o->buttons ?: [L10n::in(App::locale())->get('ok')];
        foreach ($buttons as $i => $label) {
            $b = ObjC::msg('p:p', $alert, 'addButtonWithTitle:', ObjC::str($label));
            if ($i === $o->defaultButton) {
                ObjC::msg('v:p', $b, 'setKeyEquivalent:', ObjC::str("\r"));
            } elseif ($i === $o->cancelButton) {
                ObjC::msg('v:p', $b, 'setKeyEquivalent:', ObjC::str("\e"));
            } elseif ($i > 0) {
                ObjC::msg('v:p', $b, 'setKeyEquivalent:', ObjC::str(''));
            }
        }
        if ($o->checkboxLabel !== '') {
            ObjC::msg('v:b', $alert, 'setShowsSuppressionButton:', true);
            $box = ObjC::msg('p:', $alert, 'suppressionButton');
            ObjC::msg('v:p', $box, 'setTitle:', ObjC::str($o->checkboxLabel));
            ObjC::msg('v:l', $box, 'setState:', $o->checkboxChecked ? 1 : 0);
        }
        $code = self::run($alert, $o->parent);
        $checked = $o->checkboxLabel !== '' && ObjC::msg('l:', ObjC::msg('p:', $alert, 'suppressionButton'), 'state') === 1;
        return new MessageResult($code - self::FIRST_BUTTON, $checked);
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
        $alert = ObjC::msg('p:', ObjC::alloc('NSAlert'), 'init');
        ObjC::msg('v:p', $alert, 'setMessageText:', ObjC::str($prompt));
        if ($detail !== '') {
            ObjC::msg('v:p', $alert, 'setInformativeText:', ObjC::str($detail));
        }
        $ok = ObjC::msg('p:p', $alert, 'addButtonWithTitle:', ObjC::str($t->get('ok')));
        ObjC::msg('v:p', ObjC::msg('p:p', $alert, 'addButtonWithTitle:', ObjC::str($t->get('cancel'))), 'setKeyEquivalent:', ObjC::str("\e"));
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
        $text = self::run($alert, $window) === self::FIRST_BUTTON ? ObjC::phpStr(ObjC::msg('p:', $field, 'stringValue')) : null;
        if ($observer !== null) {
            ObjC::msg('v:p', ObjC::msg('p:', ObjC::cls('NSNotificationCenter'), 'defaultCenter'), 'removeObserver:', $observer);
            self::$textChanged = null;
            ObjC::msg('v:', $view, 'release');
        }
        ObjC::msg('v:', $field, 'release');
        ObjC::msg('v:', $alert, 'release');
        return $text;
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
