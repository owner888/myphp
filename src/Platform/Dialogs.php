<?php
declare(strict_types=1);

namespace MyPHP\Platform;

use MyPHP\{MessageOptions, MessageResult, OpenDialogOptions, SaveDialogOptions, Window};

/**
 * Native dialogs (mygo's platform.Dialogs): with a parent window they
 * show attached to it, otherwise app-modal. myphp's return once the
 * dialog closes, the run loop turning meanwhile (mygo's take a callback).
 */
interface Dialogs
{
    /** @return list<string> the paths chosen, none when canceled */
    public function open(OpenDialogOptions $o): array;

    /** The path chosen, '' when canceled. */
    public function save(SaveDialogOptions $o): string;

    /** @param list<string> $buttons the buttons, defaulted by the core @param int $cancel the one Escape activates, -1 for none */
    public function message(MessageOptions $o, array $buttons, int $cancel): MessageResult;

    /** A message with a text field: the text, null when canceled; $check says what is wrong with it as it is typed (IINA's). */
    public function prompt(?Window $window, string $prompt, string $default, string $detail = '', ?\Closure $check = null): ?string;

    /** A user's name and password, null when canceled (IINA's). @return ?array{0: string, 1: string} */
    public function login(?Window $window, string $prompt, string $detail, string $user, string $password): ?array;

    /** A page's file input: files, folders too with $dirs, several with $multiple; null when canceled. @return ?list<string> */
    public function chooseForPage(Window $window, bool $multiple, bool $dirs): ?array;
}
