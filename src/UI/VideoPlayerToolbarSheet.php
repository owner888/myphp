<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;
use MyPHP\Window;
use MyPHP\WindowOptions;

/**
 * IINA's sheet that chooses the control bar's toolbar buttons
 * (PrefOSCToolbarSettingsSheetController): the current buttons in a box, a
 * button dragged out of it taken out, dragged along it moved; the buttons
 * there are under it, one dragged into the box added where it is dropped;
 * Restore Default, Cancel, Done. A sheet of the window in front.
 *
 * @internal VideoPlayerSettings
 */
final class VideoPlayerToolbarSheet
{
    /** The sheet's size, a button's in the box, a row's of the buttons there are (IINA's). */
    private const WIDTH = 424, HEIGHT = 470, BUTTON = 24, ROW = 26;

    /** @param list<int> $buttons the current ones @param Closure(list<int>): void $done told the buttons chosen with Done */
    public static function open(Context $c, array $buttons, Closure $done): void
    {
        $parent = Window::focused();
        $state = (object)['buttons' => $buttons];
        $sheet = null;
        $close = function () use (&$sheet, $parent): void {
            \MyPHP\App::later(function () use (&$sheet, $parent): void {
                $sheet?->close();
                $parent?->invalidate(); // its view takes what was chosen
            });
        };
        $sheet = new Window(new WindowOptions(parent: $parent, modal: true, width: self::WIDTH, height: self::HEIGHT, disableResize: true,
            content: Content::view(function (Context $c) use ($state, $done, $close): void { self::build($c, $state, $done, $close); }))); // shown as a sheet of the window in front
    }

    private static function build(Context $c, object $s, Closure $done, Closure $close): void
    {
        $t = $c->theme;
        $root = $c->column(0, 0, 1)->padding(20)->background(VideoPlayerSettings::colors($t)['page']);
          $c->text($c->texts()->get('toolbarCurrentItems'), 13, $t->text, Font::BOLD);
          $c->text($c->texts()->get('toolbarDragOut'), 11, $t->textMuted)->margin(4, 0, 0, 0);
          // The box of the current buttons: each dragged out to take it out, along to move it; one there is dropped in, added where it falls.
          $box = $c->row(0, 0)->size(self::BUTTON * max(4, count($s->buttons)) + 24, 26)->round(13)->alignSelf(Node::CENTER)->justify(Node::END)->align(Node::CENTER)->paddingX(4)->margin(14, 0, 0, 0)
              ->background($t->dark ? 0xffffff : 0x000000, 0.05);
            $at = [];
            foreach ($s->buttons as $i => $b) {
                $n = $c->key("current:$b")->column()->size(self::BUTTON, self::BUTTON)->center()->role(Role::BUTTON)->label($c->texts()->get('iinaOscToolbar' . self::name($b)));
                  VideoPlayerSettings::glyph($c, VideoPlayerSettings::TOOLBAR[$b][0], $t->text);
                $c->end();
                $c->drag($n, ['current', $b]);
                $at[$i] = $n;
            }
          $c->end();
          $c->text($c->texts()->get('toolbarAvailableItems'), 13, $t->text, Font::BOLD)->margin(22, 0, 0, 0);
          $c->text($c->texts()->get('toolbarDragIn'), 11, $t->textMuted)->margin(4, 0, 0, 0);
          $c->column(0, 4)->margin(12, 0, 0, 0)->alignSelf(Node::CENTER)->width(240);
            foreach (VideoPlayerSettings::TOOLBAR as $b => [$symbols]) {
                $row = $c->key("available:$b")->row(0, 4)->height(self::ROW)->round(13)->paddingX(2)->align(Node::CENTER)->fillWidth()->background($t->dark ? 0xffffff : 0x000000, 0.05);
                  $c->column()->size(self::ROW, self::ROW)->center();
                    VideoPlayerSettings::glyph($c, $symbols, $t->text);
                  $c->end();
                  $c->text($c->texts()->get('iinaOscToolbar' . self::name($b)), 13, $t->text)->singleLine();
                $c->end();
                if (!in_array($b, $s->buttons, true)) { $c->drag($row, ['add', $b]); }
                else { $row->opacity(0.5); } // in the box already
            }
          $c->end();
          $c->column()->grow(1);
          $c->end();
          $c->row(0, 12)->fillWidth()->align(Node::CENTER);
            if (VideoPlayerSettings::pushButton($c, $c->texts()->get('toolbarRestoreDefault'), false)) { $s->buttons = VideoPlayerSettings::TOOLBAR_DEFAULT; }
            $c->column()->grow(1);
            $c->end();
            if (VideoPlayerSettings::pushButton($c, $c->texts()->get('toolbarCancel'), false) || $c->shortcut(Key::ESCAPE)) { $close(); }
            $ok = $c->row()->height(24)->paddingX(12)->round(8)->align(Node::CENTER)->role(Role::BUTTON)->label($c->texts()->get('toolbarDone'))->background($t->accent);
              $c->text($c->texts()->get('toolbarDone'), 13, $t->accentText);
            $c->end();
            if ($c->clicked($ok) || $c->shortcut(Key::RETURN)) { $done($s->buttons); $close(); }
          $c->end();
        $c->end();
        if ($c->drop($box, 'array', $value)) { // in the box: where it falls among the buttons
            [$x] = $c->pointerPosition($box);
            [, , $w] = $c->layoutBounds($box);
            $index = count($s->buttons);
            foreach ($at as $i => $n) {
                [$nx] = $c->layoutBounds($n);
                [$bx] = $c->layoutBounds($box);
                if ($x < $nx - $bx + self::BUTTON / 2) { $index = $i; break; }
            }
            $rest = array_values(array_filter($s->buttons, fn(int $b) => $b !== $value[1]));
            if ($value[0] === 'current') { $index -= (int)(array_search($value[1], $s->buttons, true) < $index); }
            array_splice($rest, max(0, min($index, count($rest))), 0, [$value[1]]);
            $s->buttons = $rest;
        } elseif ($c->drop($root, 'array', $value) && $value[0] === 'current') { // out of the box: taken out
            $s->buttons = array_values(array_filter($s->buttons, fn(int $b) => $b !== $value[1]));
        }
    }

    /** The key of IINA's name of toolbar button $b: osc_toolbar.<key>, as the strings name it (OscToolbarFullScreen). */
    private static function name(int $b): string
    {
        return implode('', array_map('ucfirst', explode('_', VideoPlayerSettings::TOOLBAR[$b][2])));
    }
}
