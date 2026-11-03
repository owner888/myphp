<?php
declare(strict_types=1);

namespace MyPHP\Platform\Android;

use Closure;
use MyPHP\Native\Android;
use MyPHP\Platform;
use MyPHP\{MessageOptions, MessageResult, OpenDialogOptions, SaveDialogOptions, Window};

/**
 * Android system dialogs via AlertDialog over JNI.
 */
final class Dialogs implements Platform\Dialogs
{
    private static int $nextCallbackId = 1;
    /** @var array<int, int> */
    private static array $dialogResults = [];

    public function __construct(private readonly Backend $b) {}

    public static function onDialogResult(int $callbackId, int $buttonIndex): void
    {
        self::$dialogResults[$callbackId] = $buttonIndex;
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
        return null;
    }

    public function login(?Window $window, string $prompt, string $detail, string $user, string $password): ?array
    {
        return null;
    }

    public function open(OpenDialogOptions $o): array
    {
        return [];
    }

    public function save(SaveDialogOptions $o): string
    {
        return '';
    }

    public function chooseForPage(Window $window, bool $multiple, bool $dirs): ?array
    {
        return null;
    }
}
