<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * System dialogs, as mygo's Dialog module: open and save panels and
 * message boxes. With a parent window they show as its sheet, otherwise
 * app-modal; either way the call returns when the dialog closes.
 */
final class Dialog
{
    /** @return string[] chosen paths; empty when canceled */
    public static function open(OpenDialogOptions $o = new OpenDialogOptions()): array
    {
        return App::backend()->dialogs()->open($o);
    }

    /** @return string the chosen path, or '' when canceled */
    public static function save(SaveDialogOptions $o = new SaveDialogOptions()): string
    {
        return App::backend()->dialogs()->save($o);
    }

    public static function message(MessageOptions $o): MessageResult
    {
        $buttons = $o->buttons ?: [L10n::in(App::locale())->get('ok')];
        return App::backend()->dialogs()->message($o, $buttons, self::cancelFor($o->cancelButton, $buttons));
    }

    /** @internal the button Escape activates for MessageOptions' $cancel: it, or for 0 the first labeled Cancel or No, else the first; -1 for none (mygo's Message). @param string[] $buttons */
    public static function cancelFor(int $cancel, array $buttons): int
    {
        return $cancel === 0 ? max(self::cancelOf($buttons), 0) : $cancel;
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
     * @internal an alert with a text field, as $window's sheet (app-modal
     * without one): the text, or null when canceled. A page's prompt()
     * (mygo's jsPrompt) is $prompt alone; IINA's quickPromptPanel has
     * $detail under it and $check, what is wrong with the text ('' when
     * nothing), shown under the field as it is typed, OK disabled while
     * it is anything.
     */
    public static function prompt(?Window $window, string $prompt, string $default, string $detail = '', ?\Closure $check = null): ?string
    {
        return App::backend()->dialogs()->prompt($window, $prompt, $default, $detail, $check);
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
        return App::backend()->dialogs()->login($window, $prompt, $detail, $user, $password);
    }

    /** @internal a page's file input: files, folders too with $dirs, several with $multiple, as $window's sheet; null when canceled (mygo's jsFileInput) @return ?list<string> */
    public static function chooseForPage(Window $window, bool $multiple, bool $dirs): ?array
    {
        return App::backend()->dialogs()->chooseForPage($window, $multiple, $dirs);
    }

    public static function error(string $title, string $content): void
    {
        self::message(new MessageOptions(type: 'error', message: $title, detail: $content));
    }
}
