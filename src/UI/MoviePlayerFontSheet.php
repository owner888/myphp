<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;
use MyPHP\Window;
use MyPHP\WindowOptions;

/**
 * IINA's sheet that chooses the subtitles' font (FontPickerWindowController,
 * 458 by 524): the families the system has, filtered by what is typed, a
 * click choosing one; a preview of it; a field to type a font's name in;
 * Cancel and OK. A family chosen, not a face: the settings' bold and
 * italic choose the face, as the text's.
 *
 * @internal MoviePlayerSettingsPages
 */
final class MoviePlayerFontSheet
{
    private const WIDTH = 458, HEIGHT = 524, ROW = 18;

    /** @param Closure(string): void $done told the font chosen with OK ('sans-serif' for none, mpv's default) */
    public static function open(string $font, Closure $done): void
    {
        $parent = Window::focused();
        $state = (object)['name' => new TextState($font === 'sans-serif' ? '' : $font), 'filter' => new TextState(), 'list' => new ListState(), 'families' => null];
        $sheet = null;
        $close = function () use (&$sheet, $parent): void {
            \MyPHP\App::later(function () use (&$sheet, $parent): void {
                $sheet?->close();
                $parent?->invalidate();
            });
        };
        $sheet = new Window(new WindowOptions(parent: $parent, modal: true, width: self::WIDTH, height: self::HEIGHT, disableResize: true,
            content: Content::view(function (Context $c) use ($state, $done, $close): void { self::build($c, $state, $done, $close); })));
    }

    private static function build(Context $c, object $s, Closure $done, Closure $close): void
    {
        $t = $c->theme;
        $k = MoviePlayerSettings::colors($t);
        $tx = $c->texts();
        $s->families ??= array_map(fn(FontFamily $f) => $f->name, Font::families());
        $filter = mb_strtolower(trim($s->filter->text));
        $shown = $filter === '' ? $s->families : array_values(array_filter($s->families, fn(string $n) => str_contains(mb_strtolower($n), $filter)));
        $c->column(0, 0, 1)->padding(12, 12, 12, 12)->background($k['page']);
          $c->text($tx->get('fontPickerChoose'), 13, $t->text)->margin(0, 0, 8, 0);
          $c->row()->height(24)->fillWidth()->radius(6)->background($k['field'], $k['fieldAlpha'] / 2)->borderWidth(1)->borderColor($k['line']);
            $f = $c->input($s->filter, $tx->get('fontPickerFilter'), 0, 13)->height(24);
            $f->bordered = false;
          $c->end();
          // The families, in a box (IINA's table, its column's header).
          $c->column()->fillWidth()->height(268)->margin(8, 0, 0, 0)->radius(6)->borderWidth(1)->borderColor($k['line'])->clip()->background($k['card'], $k['cardAlpha']);
            $c->row()->height(24)->fillWidth()->paddingX(8)->alignItems(Node::CENTER)->background($t->dark ? 0xffffff : 0x000000, 0.04);
              $c->text($tx->get('fontPickerFamily'), 11, $t->textMuted, Font::BOLD);
            $c->end();
            $chosen = $s->name->text;
            $c->list($s->list, count($shown), function (int $i) use ($c, $t, $shown, $s, $chosen): void {
                $on = $shown[$i] === $chosen;
                $r = $c->row()->height(self::ROW)->fillWidth()->paddingX(8)->alignItems(Node::CENTER)->role(Role::BUTTON)->label($shown[$i]);
                if ($on) { $r->background($t->accent); }
                  $c->text($shown[$i], 13, $on ? $t->accentText : $t->text)->singleLine();
                $c->end();
                if ($c->clicked($r)) { $s->name->text = $shown[$i]; }
            });
          $c->end();
          $c->text($tx->get('fontPickerPreview'), 11, $t->textMuted)->margin(12, 0, 4, 0);
          $font = trim($s->name->text) === '' ? 'sans-serif' : trim($s->name->text);
          $c->row()->height(36)->fillWidth()->paddingX(8)->alignItems(Node::CENTER)->radius(6)->borderWidth(1)->borderColor($k['line'])->background($k['card'], $k['cardAlpha'])->clip();
            $c->text($tx->get('fontPickerTest'), 20, $t->text)->font($font)->singleLine();
          $c->end();
          $c->text($tx->get('fontPickerOther'), 13, $t->text)->margin(12, 0, 4, 0);
          $c->row()->height(24)->fillWidth()->radius(6)->background($k['field'], $k['fieldAlpha'] / 2)->borderWidth(1)->borderColor($k['line']);
            $o = $c->input($s->name, 'sans-serif', 0, 13)->height(24);
            $o->bordered = false;
          $c->end();
          $c->column()->grow(1);
          $c->end();
          $c->row(0, 12)->fillWidth()->justify(Node::END)->alignItems(Node::CENTER);
            if (MoviePlayerSettings::pushButton($c, $tx->get('fontPickerCancel'), false) || $c->shortcut(Key::ESCAPE)) { $close(); }
            $ok = $c->row()->height(24)->width(80)->radius(8)->center()->role(Role::BUTTON)->label($tx->get('fontPickerOK'))->background($t->accent);
              $c->text($tx->get('fontPickerOK'), 13, $t->accentText);
            $c->end();
            if ($c->clicked($ok) || $c->shortcut(Key::RETURN)) { $done($font); $close(); }
          $c->end();
        $c->end();
    }
}
