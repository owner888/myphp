<?php
declare(strict_types=1);

namespace MyPHP;

use MyPHP\Native\ObjC;

/**
 * System dialogs, as mygo's Dialog module: open and save panels and
 * message boxes. They run app-modal and return when closed; a parent
 * window only positions them for now (sheets need an ObjC block).
 */
final class Dialog
{
    private const MODAL_OK = 1;
    private const FIRST_BUTTON = 1000;
    private const STYLE = ['warning' => 0, 'info' => 1, 'none' => 1, 'question' => 1, 'error' => 2];

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
        if (ObjC::msg('l:', $panel, 'runModal') !== self::MODAL_OK) {
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
        if (ObjC::msg('l:', $panel, 'runModal') !== self::MODAL_OK) {
            return '';
        }
        return ObjC::phpStr(ObjC::msg('p:', ObjC::msg('p:', $panel, 'URL'), 'path'));
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
        $buttons = $o->buttons ?: ['OK'];
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
        $code = ObjC::msg('l:', $alert, 'runModal');
        $checked = $o->checkboxLabel !== '' && ObjC::msg('l:', ObjC::msg('p:', $alert, 'suppressionButton'), 'state') === 1;
        return new MessageResult($code - self::FIRST_BUTTON, $checked);
    }

    public static function error(string $title, string $content): void
    {
        self::message(new MessageOptions(type: 'error', message: $title, detail: $content));
    }
}
