<?php
declare(strict_types=1);

namespace MyPHP\Platform\Android;

use Closure;
use MyPHP\Native\Android;
use MyPHP\Platform;
use MyPHP\Platform\FileTypes;
use MyPHP\Platform\Unsupported;
use MyPHP\{MessageOptions, MessageResult, OpenDialogOptions, SaveDialogOptions, Window};

/**
 * Android's dialogs over JNI: AlertDialog; for files the Storage Access Framework's document
 * picker, or the photo picker when the filters name only pictures and films (MyphpActivity.pickFiles).
 * What is opened is a copy in the cache under its name (open/<id>/<name>): a content: URI is not a
 * path, and the name matters to an app that uploads the file. What is saved is /proc/self/fd/N of
 * the descriptor the provider gave, written through.
 */
final class Dialogs implements Platform\Dialogs
{
    private static int $nextCallbackId = 1;
    /** @var array<int, int> */
    private static array $dialogResults = [];
    /** @var array<int, string> the picker's answer by callback id: paths one per line, "" cancelled, "!message" failed */
    private static array $filesResults = [];

    public function __construct(private readonly Backend $b) {}

    public static function onDialogResult(int $callbackId, int $buttonIndex): void
    {
        self::$dialogResults[$callbackId] = $buttonIndex;
    }

    public static function onFilesResult(int $callbackId): void
    {
        $f = Android::ffi();
        $buf = $f->new('char[65536]');
        $len = $f->myphp_take_files_result($callbackId, $buf, 65536);
        if ($len < 0) {
            throw new \RuntimeException("no files result for #$callbackId");
        }
        self::$filesResults[$callbackId] = \FFI::string($buf);
    }

    public function message(MessageOptions $o, array $buttons, int $cancel): MessageResult
    {
        $id = self::$nextCallbackId++;
        $title = $o->title !== '' ? $o->title : $o->message;
        $detail = $o->title !== '' ? $o->detail : ($o->detail !== '' ? $o->detail : $o->message);
        if ($title === $detail) {
            $detail = '';
        }

        $f = Android::ffi();
        $btnCount = count($buttons);
        $btnArray = null;
        if ($btnCount > 0) {
            $btnArray = $f->new("char*[$btnCount]");
            foreach ($buttons as $i => $btn) {
                $len = strlen($btn) + 1;
                $buf = $f->new("char[$len]");
                \FFI::memcpy($buf, $btn, strlen($btn));
                $btnArray[$i] = \FFI::cast('char*', \FFI::addr($buf));
            }
        }

        $f->myphp_show_alert_dialog($title, $detail, $btnArray !== null ? \FFI::addr($btnArray[0]) : null, $btnCount, $id);

        // Spin run loop until user taps a button
        while (!isset(self::$dialogResults[$id])) {
            $this->b->step();
        }

        $result = self::$dialogResults[$id];
        unset(self::$dialogResults[$id]);

        return new MessageResult(
            response: max(0, $result),
            checkbox: false
        );
    }

    public function prompt(?Window $window, string $prompt, string $default, string $detail = '', ?Closure $check = null): ?string
    {
        throw new Unsupported('Dialog::prompt is not supported on Android: ask in the window, with an input');
    }

    public function login(?Window $window, string $prompt, string $detail, string $user, string $password): ?array
    {
        throw new Unsupported('Dialog::login is not supported on Android: ask in the window, with inputs');
    }

    public function open(OpenDialogOptions $o): array
    {
        if ($o->directory) {
            throw new Unsupported('Dialog::open of a directory is not supported on Android: files are chosen one by one');
        }
        $ext = FileTypes::extensions($o->filters);
        $photos = FileTypes::mediaOnly($ext);
        $types = $photos ? FileTypes::mediaKinds($ext) : FileTypes::mimeTypes($ext);
        $id = self::$nextCallbackId++;
        $f = Android::ffi();
        $n = count($types);
        $arr = $f->new("char*[" . max(1, $n) . "]");
        $keep = [];
        foreach ($types as $i => $t) {
            $keep[] = $buf = $f->new('char[' . (strlen($t) + 1) . ']');
            \FFI::memcpy($buf, $t, strlen($t));
            $arr[$i] = \FFI::cast('char*', \FFI::addr($buf));
        }
        $f->myphp_pick_files(\FFI::addr($arr[0]), $n, $o->multiple ? 1 : 0, $photos ? 1 : 0, $id);
        $answer = $this->waitFiles($id);
        return $answer === '' ? [] : explode("\n", $answer);
    }

    public function save(SaveDialogOptions $o): string
    {
        $ext = FileTypes::extensions($o->filters);
        $mime = $ext === [] ? '' : FileTypes::mimeTypes([$ext[0]])[0];
        $id = self::$nextCallbackId++;
        Android::ffi()->myphp_save_file($mime, basename($o->defaultPath), $id);
        return $this->waitFiles($id);
    }

    public function chooseForPage(Window $window, bool $multiple, bool $dirs): ?array
    {
        if ($dirs) {
            throw new Unsupported('a page choosing a directory is not supported on Android');
        }
        $files = $this->open(new OpenDialogOptions(multiple: $multiple));
        return $files === [] ? null : $files;
    }

    /** The picker's paths for $id (one per line; "" when cancelled), the run loop stepped until it answered. */
    private function waitFiles(int $id): string
    {
        while (!isset(self::$filesResults[$id])) {
            $this->b->step();
        }
        $answer = self::$filesResults[$id];
        unset(self::$filesResults[$id]);
        if ($answer !== '' && $answer[0] === '!') {
            throw new \RuntimeException('file dialog: ' . substr($answer, 1));
        }
        return $answer;
    }
}
