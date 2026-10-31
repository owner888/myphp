<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;
use MyPHP\MenuItem as NativeItem;

/**
 * A menu drawn in the window (Node::popoverMenu, popoverMenuButton,
 * mygo's popovermenu.go) that shows, of node $id: what it keeps from
 * frame to frame, and the build of its panels, one for the menu and one
 * for each submenu open from it, in an overlay, as popovers of the node.
 *
 * @internal Runtime
 */
final class DrawnMenu
{
    /**
     * The menu and its submenus that show, outermost first: the place of
     * the item whose submenu it is (0 for the menu), the item highlighted
     * (0 for none, -1 for the first that can be chosen), and whether it
     * scrolls into view, the keys having moved to it.
     * @var list<array{sub: int, hi: int, reveal: bool}>
     */
    public array $levels;
    /** The frame and the pass that last built it. */
    public int $built = -1, $pass = -1;
    /** The level to give the focus to once built, -1 for none. */
    public int $focus = 0;
    /** What was typed at it, for the first letters of an item, and when. */
    public string $typed = '';
    public float $typedAt = 0;

    /** @param array{0: float, 1: float} $pointer where the pointer was when it last moved the highlight: it moves it again once it moves */
    public function __construct(
        public readonly int $id,
        /** A menu button's, below it; else at a point, $dx and $dy from the node's top left. */
        public readonly bool $below,
        public readonly float $dx,
        public readonly float $dy,
        /** The text it opened on, 0 for none. */
        public readonly int $text,
        /** The frame it opened in, whose presses do not close it, as the press opening it. */
        public readonly int $opened,
        bool $key,
        public array $pointer,
    ) {
        $this->levels = [['sub' => 0, 'hi' => $key ? -1 : 0, 'reveal' => false]]; // the keyboard opened it: it starts on the first item
    }

    // The build of a pass.
    private Context $c;
    private Runtime $rt;
    /** The pointer moved since it last moved the highlight. */
    private bool $moved = false;
    /** @var list<Node> the panels, by level */
    private array $panels = [];
    /** @var list<int> the panels' ids, the last build's: Tab while one has the focus closes the menu (Runtime::tabOutOfMenu) */
    public array $panelIds = [];
    private ?NativeItem $chosen = null;
    private bool $closed = false;

    /**
     * Builds the menu of $e, which $build fills, in an overlay of $c
     * (mygo's drawMenu): the items, the keys and the pointer, the item
     * chosen reaching $build. Whether it still shows.
     * @param Closure(Menu): void $build
     */
    public function build(Node $e, Context $c, Runtime $rt, Closure $build): bool
    {
        $this->built = $rt->frameNumber; $this->pass = $rt->pass;
        if ($e->isDisabled()) {
            return false;
        }
        $run = new MenuRun(true);
        $m = new Menu($run, [], $rt, $this->text);
        $build($m);
        $items = $m->items;
        if (array_filter($items, fn(NativeItem $it) => !$it->hidden && $it->type !== NativeItem::SEPARATOR && $it->type !== NativeItem::HEADER) === []) {
            return false;
        }
        [$this->c, $this->rt] = [$c, $rt];
        $this->panels = []; $this->chosen = null; $this->closed = false;
        $p = [$rt->input->mouseX, $rt->input->mouseY];
        $this->moved = $p !== $this->pointer;
        $c->overlay(function () use ($e, $c, $items): void {
            // A menu button's menu goes below it. Another at the point where it opened, which follows the node as it moves: the
            // menu goes after it, or before it where the window has no room, as from a corner.
            $anchor = $e;
            if (!$this->below) {
                $anchor = $c->key('drawn-menu:' . $this->id)->box(0, 0, 0)->attachTo($e, Anchor::TopLeft, Anchor::TopLeft)->margin($this->dy, 0, 0, $this->dx);
                $anchor->color = null;
            }
            for ($level = 0; $level < count($this->levels) && !$this->closed; $level++) {
                [$panel, $rows] = $this->panel($level, $items, $anchor);
                $this->panels[] = $panel;
                if ($level + 1 >= count($this->levels) || $this->closed) {
                    break;
                }
                $sub = $this->levels[$level + 1]['sub'];
                $it = null;
                foreach ($items as $x) { if ((int)$x->id === $sub) { $it = $x; } }
                if ($it === null || $it->type !== NativeItem::SUBMENU || $it->disabled || !isset($rows[$sub])) { // the item is gone, or can no longer be chosen
                    $this->levels = array_slice($this->levels, 0, $level + 1);
                    break;
                }
                [$anchor, $items] = [$rows[$sub], $it->submenu];
            }
        });
        $this->panelIds = array_map(fn(Node $p) => $p->id, $this->panels);
        if (!$this->closed) {
            if ($this->below) { $e->expanded(true); }
            if (($f = $this->focus) >= 0 && isset($this->panels[$f])) { // after the overlay noted the focus it came with, to give it back
                $rt->focusOn($this->panels[$f]->id);
                $this->focus = -1;
            }
        }
        if (($it = $this->chosen) !== null) {
            $name = $run->commands[(int)$it->id - 1] ?? '';
            if ($name !== '') {
                $rt->menuCommand($this->text !== 0 ? $this->text : $this->id, $name);
            } else {
                $rt->menuChoice($build, (int)$it->id, $it->label, $this->text);
            }
        }
        return !$this->closed;
    }

    /**
     * The panel of the menu at $level, of $items, attached to $anchor: the
     * menu button, the point where the menu opened, or the item opening
     * the submenu; and its items' rows by place.
     * @param list<NativeItem> $items
     * @return array{0: Node, 1: array<int, Node>}
     */
    private function panel(int $level, array $items, Node $anchor): array
    {
        $c = $this->c; $rt = $this->rt; $t = $c->theme;
        $panel = $c->key('drawn-menu:' . $this->id . ':' . $level)->column()->role(Role::MENU)->focusable();
        $panel->ownRing = true;
        $panel->scroll($rt->local($panel->id, fn() => new ScrollState)); // items past the room the window has scroll
        $panel->minWidth($t->space(45))->padding($t->space(1))->radius($t->radius + 2)->background($t->background)->border()->shadow(0, 6, 20, 0, 0x000000, 0.18);
        if ($level === 0 && $this->below) { // below the menu button, or above it where the window has no room, lined up with its start
            $panel->attachTo($anchor, Anchor::BottomStart, Anchor::TopStart)->margin($t->space(1), 0, 0, 0);
        } elseif ($level === 0) {
            $panel->attachTo($anchor, Anchor::TopStart, Anchor::TopStart);
        } else { // after the item opening it, its first item lined up with it
            $panel->attachTo($anchor, Anchor::TopEnd, Anchor::TopStart)->margin(-$t->space(1) - 1, 0, 0, 0);
            $panel->label($anchor->label ?? '');
        }
        if ($this->levels[$level]['hi'] < 0) {
            $this->levels[$level]['hi'] = 0;
            foreach ($items as $it) { if (self::choosable($it)) { $this->levels[$level]['hi'] = (int)$it->id; break; } }
        }
        $marks = array_filter($items, fn(NativeItem $it) => $it->type === NativeItem::CHECKBOX || $it->type === NativeItem::RADIO) !== [];
        $size = count(array_filter($items, fn(NativeItem $it) => !$it->hidden && $it->type !== NativeItem::SEPARATOR && $it->type !== NativeItem::HEADER));
        $rows = []; $choices = []; // those the keys move among
        $pos = 0;
        foreach ($items as $it) {
            if ($it->hidden) { continue; }
            if ($it->type === NativeItem::SEPARATOR) {
                $c->box(0, 1, $t->border)->margin($t->space(1), $t->space(2))->shrink(0);
                continue;
            }
            if ($it->type === NativeItem::HEADER) {
                $c->text($it->label, $t->fontSize - 1, $t->textMuted)->singleLine()->padding($t->space(1), $t->space(2))->role(Role::HEADING);
                continue;
            }
            $row = $this->item($level, $it, $marks);
            $row->setPos = ++$pos; $row->setSize = $size;
            $rows[(int)$it->id] = $row;
            if (self::choosable($it)) { $choices[] = $it; }
        }
        $c->end();
        if (($hi = $rows[$this->levels[$level]['hi']] ?? null) !== null) {
            $panel->activeDescendant($hi);
            if ($this->levels[$level]['reveal']) { $hi->scrollIntoView(); }
        }
        $this->levels[$level]['reveal'] = false;
        if (!$this->closed) {
            $this->keys($level, $panel, $choices);
        }
        if ($level === 0 && $this->opened !== $rt->frameNumber && $c->pressedOutside($panel)) {
            $this->closed = true;
        }
        return [$panel, $rows];
    }

    /** Whether the keys and the pointer can highlight $it. */
    private static function choosable(NativeItem $it): bool
    {
        return !$it->hidden && !$it->disabled && $it->type !== NativeItem::SEPARATOR && $it->type !== NativeItem::HEADER;
    }

    /** The row of item $it of the menu at $level, with room for the marks of check box and radio items when $marks. */
    private function item(int $level, NativeItem $it, bool $marks): Node
    {
        $c = $this->c; $t = $c->theme; $id = (int)$it->id;
        $row = $c->row()->alignItems(Node::CENTER)->gap($t->space(2))->padding($t->space(1.5), $t->space(2))->radius($t->radius)->shrink(0);
        $row->label($it->label)->disabled($it->disabled);
        $row->role(match ($it->type) { NativeItem::CHECKBOX => Role::MENU_ITEM_CHECK_BOX, NativeItem::RADIO => Role::MENU_ITEM_RADIO, default => Role::MENU_ITEM });
        if ($it->type === NativeItem::CHECKBOX || $it->type === NativeItem::RADIO) { $row->checked($it->checked); }
        $sub = $it->type === NativeItem::SUBMENU;
        $open = $sub && $level + 1 < count($this->levels) && $this->levels[$level + 1]['sub'] === $id;
        if ($sub) { $row->expandable = true; $row->expanded($open); }
        $keys = self::shortcutText($it->accelerator);
        $row->description = $keys !== '' ? $keys : null;
        [$color, $muted] = [$t->text, $t->textMuted];
        $hi = $this->levels[$level]['hi'] === $id;
        if ($it->disabled) {
            $color = $t->textMuted;
        } elseif ($hi && $open && $this->levels[$level + 1]['hi'] !== 0) { // the way to the item highlighted in the submenu
            $row->background($t->surfaceHover);
        } elseif ($hi) {
            [$color, $muted] = [$t->accentText, $t->accentText];
            $row->background($t->accent);
        }
        $rtl = $row->rtl;
          if ($marks) {
              $mark = $c->box($t->space(4), $t->space(4), 0)->shrink(0);
              $mark->color = null;
              if ($it->checked) {
                  $radio = $it->type === NativeItem::RADIO;
                  $mark->draw(function (Painter $p, array $r) use ($radio, $color): void {
                      [$x, $y, $w, $h] = $r;
                      if ($radio) {
                          $d = $w / 4;
                          $p->fill([$x + $w / 2 - $d / 2, $y + $h / 2 - $d / 2, $d, $d], $color, $d / 2);
                      } else {
                          $p->strokePath((new Path)->moveTo($x + $w * 0.25, $y + $h * 0.52)->lineTo($x + $w * 0.43, $y + $h * 0.7)->lineTo($x + $w * 0.76, $y + $h * 0.32), 1.5, $color);
                      }
                  });
              }
          }
          $c->text($it->label, null, $color)->singleLine()->grow = 1;
          if ($keys !== '') { $c->text($keys, null, $muted)->singleLine()->marginStart($t->space(4)); }
          if ($sub) {
              $arrow = $c->box($t->space(4), $t->space(4), 0)->shrink(0);
              $arrow->color = null;
              $arrow->draw(function (Painter $p, array $r) use ($rtl, $muted): void { // a chevron pointing along the menus, the other way right to left
                  [$x, $y, $w, $h] = $r;
                  [$x0, $x1] = $rtl ? [0.625, 0.375] : [0.375, 0.625];
                  $p->strokePath((new Path)->moveTo($x + $w * $x0, $y + $h * 0.25)->lineTo($x + $w * $x1, $y + $h * 0.5)->lineTo($x + $w * $x0, $y + $h * 0.75), 1.5, $muted);
              });
          }
        $c->end();
        if ($it->disabled || $this->closed) {
            return $row;
        }
        if ($this->moved && $c->hovered($row)) { // the pointer moved onto it: it is highlighted, and its submenu opens while those of the others close; the keys go on from it
            $this->moved = false;
            $this->pointer = [$this->rt->input->mouseX, $this->rt->input->mouseY];
            if ($sub && !$open) {
                $this->highlight($level, $id);
                $this->openSubmenu($level, $id, false);
            } elseif (!$sub && ($this->levels[$level]['hi'] !== $id || count($this->levels) > $level + 1)) {
                $this->highlight($level, $id);
            }
            $this->focus = $level;
        }
        if ($c->clicked($row)) {
            if ($sub) {
                $this->highlight($level, $id);
                $this->openSubmenu($level, $id, false);
            } else {
                [$this->chosen, $this->closed] = [$it, true];
            }
        }
        return $row;
    }

    /** Highlights the item at place $id of the menu at $level, closing the submenus open in it. */
    private function highlight(int $level, int $id): void
    {
        $this->levels[$level]['hi'] = $id;
        $this->levels = array_slice($this->levels, 0, $level + 1);
        $this->rt->changed();
    }

    /** Opens the submenu of the item at place $id of the menu at $level; the keyboard opening it, it takes the focus on its first item. */
    private function openSubmenu(int $level, int $id, bool $keyboard): void
    {
        $this->levels = [...array_slice($this->levels, 0, $level + 1), ['sub' => $id, 'hi' => $keyboard ? -1 : 0, 'reveal' => false]];
        if ($keyboard) { $this->focus = $level + 1; }
        $this->rt->changed();
    }

    /** Closes the submenu at $level, the focus going to the menu holding it. */
    private function closeLevel(int $level): void
    {
        $this->levels = array_slice($this->levels, 0, $level);
        $this->focus = $level - 1;
        $this->rt->changed();
    }

    /**
     * Moves the highlight among $choices, the items of the menu at $level
     * that can be chosen, opens and closes submenus and chooses items, for
     * the keys pressed while $panel has the focus.
     * @param list<NativeItem> $choices
     */
    private function keys(int $level, Node $panel, array $choices): void
    {
        $c = $this->c; $rt = $this->rt;
        $n = count($choices);
        $at = -1;
        foreach ($choices as $i => $it) { if ((int)$it->id === $this->levels[$level]['hi']) { $at = $i; } }
        $move = function (int $i) use ($n, $choices, $level): void {
            if ($n > 0) {
                $this->highlight($level, (int)$choices[($i % $n + $n) % $n]->id);
                $this->levels[$level]['reveal'] = true;
            }
        };
        $submenu = fn(int $i): bool => $i >= 0 && $choices[$i]->type === NativeItem::SUBMENU;
        [$into, $out] = $panel->rtl ? [Key::LEFT, Key::RIGHT] : [Key::RIGHT, Key::LEFT];
        if ($c->focused($panel)) {
            if ($c->shortcut(Key::DOWN)) {
                $move($at + 1); // from none, the first
            } elseif ($c->shortcut(Key::UP)) {
                $move(max($at, 0) - 1);
            } elseif ($c->shortcut(Key::HOME)) {
                $move(0);
            } elseif ($c->shortcut(Key::END)) {
                $move($n - 1);
            } elseif ($c->shortcut($into)) {
                if ($submenu($at)) { $this->openSubmenu($level, (int)$choices[$at]->id, true); }
            } elseif ($c->shortcut($out)) {
                if ($level > 0) { $this->closeLevel($level); }
            } elseif ($rt->activated($panel->id) || $c->shortcut(Key::RETURN) || $c->shortcut(Key::ENTER) || $c->shortcut(Key::SPACE)) { // Space comes as the panel's activation
                if ($submenu($at)) {
                    $this->openSubmenu($level, (int)$choices[$at]->id, true);
                } elseif ($at >= 0) {
                    [$this->chosen, $this->closed] = [$choices[$at], true];
                }
            }
            if (($typed = $rt->typed($panel->id)) !== '' && $n > 0) {
                $now = $rt->now / 1e9;
                $this->typed = $now >= $this->typedAt && $now - $this->typedAt < 1.0 ? $this->typed . $typed : $typed;
                $this->typedAt = $now;
                // From the item after the one highlighted, round to it: the same letter again goes to the next item starting with it.
                $start = mb_strlen($this->typed) > 1 && $at >= 0 ? $at - 1 : $at;
                $low = mb_strtolower($this->typed);
                for ($k = 1; $k <= $n; $k++) {
                    $j = ($start + $k + $n) % $n;
                    if (str_starts_with(mb_strtolower($choices[$j]->label), $low)) { $move($j); break; }
                }
            }
        }
        if ($c->overlayShortcut($panel, Key::ESCAPE)) {
            if ($level === 0) { $this->closed = true; } else { $this->closeLevel($level); }
        }
        if ($this->closed) {
            $rt->changed();
        }
    }

    /** An accelerator ("Super+Shift+z", Runtime::accelerator) as a menu shows it by its item, with macOS's symbols, as "⇧⌘Z" (mygo's shortcutText). */
    public static function shortcutText(string $a): string
    {
        if ($a === '') {
            return '';
        }
        $parts = explode('+', $a);
        $key = array_pop($parts);
        if ($key === '') { $key = '+'; array_pop($parts); } // "Super++"
        if (mb_strlen($key) === 1) { $key = mb_strtoupper($key); }
        $out = '';
        foreach ([['Ctrl', '⌃'], ['Alt', '⌥'], ['Shift', '⇧'], ['Super', '⌘']] as [$m, $s]) { // in the order of macOS's menus
            if (in_array($m, $parts, true)) { $out .= $s; }
        }
        return $out . match ($key) {
            'Enter' => '↩', 'Escape' => '⎋', 'Backspace' => '⌫', 'Delete' => '⌦', 'Tab' => '⇥',
            'Left' => '←', 'Right' => '→', 'Up' => '↑', 'Down' => '↓', default => $key,
        };
    }
}
