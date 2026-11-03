<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use Closure;
use MyPHP\Native\ObjC;
use MyPHP\Platform;
use MyPHP\{MessageOptions, MessageResult, OpenDialogOptions, SaveDialogOptions, Window};

final class Dialogs implements Platform\Dialogs
{
    public function __construct(private readonly Backend $b)
    {
    }

    public function message(MessageOptions $o, array $buttons, int $cancel): MessageResult
    {
        $title = $o->title !== '' ? $o->title : $o->message;
        $detail = $o->title !== '' ? $o->detail : '';

        // In iOS, UIAlertController with action handlers
        // Default to returning first button (or cancel index)
        return new MessageResult(0, false);
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
