<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use Closure;
use MyPHP\Native\iOS;
use MyPHP\Platform;
use MyPHP\Platform\FileTypes;
use MyPHP\Platform\Unsupported;
use MyPHP\{MessageOptions, MessageResult, OpenDialogOptions, SaveDialogOptions, Window};

/**
 * iOS's dialogs: UIAlertController; for files UIDocumentPickerViewController, or PHPickerViewController
 * when the filters name only pictures and films (main.m, myphp_ios_pick_files). What is opened is a
 * copy in tmp/open/<id>/ under its name (a document picker's URL is security scoped, a photo has
 * no path). Saving has no dialog on iOS (a file is exported once written): Unsupported.
 */
final class Dialogs implements Platform\Dialogs
{
    private static int $nextCallbackId = 1;

    public function __construct(private readonly Backend $b)
    {
    }

    public function message(MessageOptions $o, array $buttons, int $cancel): MessageResult
    {
        $id = self::$nextCallbackId++;
        $title = $o->title !== '' ? $o->title : $o->message;
        $detail = $o->title !== '' ? $o->detail : ($o->detail !== '' ? $o->detail : $o->message);
        if ($title === $detail) {
            $detail = '';
        }

        $f = iOS::ffi();
        $btnCount = count($buttons);
        $btnArray = null;
        $keep = [];
        if ($btnCount > 0) {
            $btnArray = $f->new("char*[$btnCount]");
            foreach ($buttons as $i => $btn) {
                $keep[] = $buf = $f->new('char[' . (strlen($btn) + 1) . ']');
                \FFI::memcpy($buf, $btn, strlen($btn));
                $btnArray[$i] = $f->cast('char*', \FFI::addr($buf));
            }
        }

        $f->myphp_ios_show_alert_dialog(
            $title,
            $detail,
            $btnArray !== null ? \FFI::addr($btnArray[0]) : null,
            $btnCount,
            $id
        );

        // Spin run loop until user taps a button in UIAlertController
        $result = -1;
        while (($result = $f->myphp_ios_get_dialog_result($id)) < 0) {
            $this->b->step();
        }

        return new MessageResult(
            response: max(0, $result),
            checkbox: false
        );
    }

    public function prompt(?Window $window, string $prompt, string $default, string $detail = '', ?Closure $check = null): ?string
    {
        throw new Unsupported('Dialog::prompt is not supported on iOS: ask in the window, with an input');
    }

    public function login(?Window $window, string $prompt, string $detail, string $user, string $password): ?array
    {
        throw new Unsupported('Dialog::login is not supported on iOS: ask in the window, with inputs');
    }

    public function open(OpenDialogOptions $o): array
    {
        if ($o->directory) {
            throw new Unsupported('Dialog::open of a directory is not supported on iOS: files are chosen one by one');
        }
        $ext = FileTypes::extensions($o->filters);
        $photos = FileTypes::mediaOnly($ext);
        $id = self::$nextCallbackId++;
        $f = iOS::ffi();
        $n = count($ext);
        $arr = $f->new('char*[' . max(1, $n) . ']');
        $keep = [];
        foreach ($ext as $i => $e) {
            $keep[] = $buf = $f->new('char[' . (strlen($e) + 1) . ']');
            \FFI::memcpy($buf, $e, strlen($e));
            $arr[$i] = $f->cast('char*', \FFI::addr($buf));
        }
        $f->myphp_ios_pick_files(\FFI::addr($arr[0]), $n, $o->multiple ? 1 : 0, $photos ? 1 : 0, $id);
        $buf = $f->new('char[65536]');
        while ($f->myphp_ios_take_files_result($id, $buf, 65536) < 0) {
            $this->b->step();
        }
        $answer = \FFI::string($buf);
        if ($answer !== '' && $answer[0] === '!') {
            throw new \RuntimeException('file dialog: ' . substr($answer, 1));
        }
        return $answer === '' ? [] : explode("\n", $answer);
    }

    public function save(SaveDialogOptions $o): string
    {
        throw new Unsupported('Dialog::save is not supported on iOS: write the file, then share it');
    }

    public function chooseForPage(Window $window, bool $multiple, bool $dirs): ?array
    {
        if ($dirs) {
            throw new Unsupported('a page choosing a directory is not supported on iOS');
        }
        $files = $this->open(new OpenDialogOptions(multiple: $multiple));
        return $files === [] ? null : $files;
    }
}
