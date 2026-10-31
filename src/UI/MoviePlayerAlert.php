<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;
use MyPHP\App;
use MyPHP\Shell;
use MyPHP\Window;
use MyPHP\WindowOptions;

/**
 * An alert with a view of its own under its text, as IINA's NSAlert with
 * an accessory view (its prompts, its key mapping): the app's icon, the
 * title, the message, the view, OK (enabled while $ready says) and Cancel;
 * a sheet of the window in front.
 *
 * @internal MoviePlayerSettingsPages
 */
final class MoviePlayerAlert
{
    /**
     * @param Closure(Context, object): void $view builds the view under the text, of the alert's state $s
     * @param Closure(object): bool $ready whether OK is enabled
     * @param Closure(object): void $ok told the state as OK closes it
     */
    public static function open(string $title, string $message, object $s, Closure $view, Closure $ready, Closure $ok, float $width = 260, float $height = 220): void
    {
        $parent = Window::focused();
        $sheet = null;
        $close = function () use (&$sheet, $parent): void {
            App::later(function () use (&$sheet, $parent): void { $sheet?->close(); $parent?->invalidate(); });
        };
        $sheet = new Window(new WindowOptions(parent: $parent, modal: true, width: $width + 32, height: $height, disableResize: true,
            content: Content::view(function (Context $c) use ($title, $message, $s, $view, $ready, $ok, $close): void {
                $t = $c->theme;
                $c->column(0, 6, 1)->padding(20, 16, 16, 16)->alignItems(Node::CENTER)->background(MoviePlayerSettings::colors($t)['page']);
                  static $icon = null;
                  $icon ??= App::running() ? Image::fromBytes(Shell::appIcon((int)(64 * $c->scale)), 512) : null;
                  if ($icon !== null) { $c->image($icon, 64, 64); }
                  $c->text($title, 13, $t->text, Font::BOLD)->textAlign(Node::CENTER)->margin(6, 0, 0, 0);
                  $c->text($message, 11, $t->text)->textAlign(Node::CENTER);
                  $c->column(0, 4)->fillWidth()->margin(8, 0, 0, 0);
                    $view($c, $s);
                  $c->end();
                  $c->column()->grow(1);
                  $c->end();
                  $c->row(0, 8)->fillWidth();
                    $cancel = $c->row()->height(24)->grow(1)->radius(8)->center()->role(Role::BUTTON)->label($c->texts()->get('cancel'))->background(MoviePlayerSettings::colors($t)['control'], MoviePlayerSettings::colors($t)['controlAlpha']);
                      $c->text($c->texts()->get('cancel'), 13, $t->text);
                    $c->end();
                    $enabled = $ready($s);
                    $okButton = $c->row()->height(24)->grow(1)->radius(8)->center()->role(Role::BUTTON)->label($c->texts()->get('ok'))->background($t->accent);
                    if (!$enabled) { $okButton->disabled(); }
                      $c->text($c->texts()->get('ok'), 13, $t->accentText);
                    $c->end();
                  $c->end();
                $c->end();
                if ($c->clicked($cancel) || (!($s->recording ?? false) && $c->shortcut(Key::ESCAPE))) { $close(); }
                if ($enabled && ($c->clicked($okButton) || (!($s->recording ?? false) && $c->shortcut(Key::RETURN)))) { $ok($s); $close(); }
            })));
    }

    /**
     * IINA's prompt (Utility.quickPromptPanel): alert.$key's title and
     * message over a field; OK while $validate says the text is good
     * (null), else what it says under the field (IINA's "Value cannot be
     * empty."); $done told the text.
     * @param Closure(string): ?string $validate @param Closure(string): void $done
     */
    public static function prompt(Context $c, string $key, string $value, Closure $validate, Closure $done): void
    {
        $s = (object)['text' => new TextState($value)];
        self::open(MoviePlayerSettings::iina($c, "alert.$key.title"), MoviePlayerSettings::iina($c, "alert.$key.message"), $s,
            function (Context $c, object $s) use ($validate): void {
                $k = MoviePlayerSettings::colors($c->theme);
                $c->row()->height(26)->fillWidth()->radius(6)->background($k['field'], $k['fieldAlpha'] / 2)->borderWidth(1)->borderColor($k['line']);
                  $f = $c->input($s->text, '', 0, 13)->height(26);
                  $f->bordered = false;
                  $f->autoFocus();
                $c->end();
                $c->text($validate($s->text->text) ?? '', 11, $c->theme->textMuted);
            },
            fn(object $s) => $validate($s->text->text) === null,
            fn(object $s) => $done($s->text->text));
    }
}
