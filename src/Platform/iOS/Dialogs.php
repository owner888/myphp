<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use Closure;
use MyPHP\Native\iOS;
use MyPHP\Platform;
use MyPHP\{MessageOptions, MessageResult, OpenDialogOptions, SaveDialogOptions, Window};

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

        try {
            $f = iOS::ffi();
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
        } catch (\Throwable $e) {
            return new MessageResult(0, false);
        }
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

    public function save(SaveDialogOptions $o): ?string
    {
        return null;
    }
}
