<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;

/**
 * Widgets without a look (mygo's bases, base.go): each handles the
 * pointer, the keys and the focus, and tells assistive technology what it
 * is, and leaves every color, size and shape to the nodes it returns. A
 * base that opens a container (checkboxBase, tabsBase's tab…) is built
 * in until end(), as row() is:
 *
 *     $cb = $c->checkboxBase($app->sync)->gap(8);
 *       $box = $c->box(18, 18, $app->sync ? $green : $white)->radius(5);
 *       $c->text('Sync');
 *     $c->end();
 */
trait ContextBase
{
    /** A check box without a look: a row that turns $checked over when clicked, or with Space while it has the focus, which changed() reports, and that assistive technology sees as a check box (mygo's CheckboxBase). */
    public function checkboxBase(bool &$checked): Node
    {
        return $this->toggleOf($checked, Role::CHECK_BOX);
    }

    /** A switch without a look: checkboxBase, which assistive technology sees as a switch (mygo's SwitchBase). */
    public function switchBase(bool &$on): Node
    {
        return $this->toggleOf($on, Role::SWITCH);
    }

    /** A toggle button without a look: a row centering what is in it that turns $on over when clicked, seen as a toggle button, pressed while on (mygo's ToggleBase). */
    public function toggleBase(bool &$on): Node
    {
        return $this->toggleOf($on, Role::TOGGLE_BUTTON)->center();
    }

    private function toggleOf(bool &$on, int $role): Node
    {
        $n = $this->row()->focusable()->shrink(0)->role($role);
        if ($this->clicked($n)) {
            $on = !$on;
            $n->changed = true;
        }
        return $n->checked($on);
    }

    /** A radio button without a look: a row that puts $value in $selected when clicked, or with Space while it has the focus, which changed() reports, seen as a radio button, on while $selected is $value (mygo's RadioBase). */
    public function radioBase(mixed &$selected, mixed $value): Node
    {
        $n = $this->row()->focusable()->shrink(0)->role(Role::RADIO);
        if ($this->clicked($n) && $selected !== $value) {
            $selected = $value;
            $n->changed = true;
        }
        $n->on = $selected === $value; // Tab enters a group at the one on
        return $n->checked($n->on);
    }

    /** A list of $n tabs without a look, of which $selected is the one chosen: a click chooses a tab, as do the arrows, Home and End while one has the focus, which follows the choice; the list's changed() reports a new choice. Build the tabs in $parts->list with tab($i), then end() the list (mygo's TabsBase). */
    public function tabsBase(int &$selected, int $n): TabsParts
    {
        if ($n > 0) { $selected = max(0, min($selected, $n - 1)); }
        $list = $this->row()->shrink(0)->role(Role::TAB_LIST)->focusGroup(Node::HORIZONTAL | Node::VERTICAL, selects: true);
        return new TabsParts($list, $this, $selected, $n);
    }

    /** A segmented control of $n segments without a look, of which $selected is the one chosen: a radio group whose segments segment($i) builds in $parts->track, then end() the track; its changed() reports a new choice (mygo's SegmentedBase). */
    public function segmentedBase(int &$selected, int $n): SegmentedParts
    {
        if ($n > 0) { $selected = max(0, min($selected, $n - 1)); }
        $track = $this->row()->shrink(0)->role(Role::RADIO_GROUP)->focusGroup(Node::HORIZONTAL | Node::VERTICAL, selects: true);
        return new SegmentedParts($track, $this, $selected);
    }

    /** A collapsible without a look, open while $open: its trigger, a row opening and closing it when clicked, or with Enter or Space, which its changed() reports, seen as a disclosure; and below it the panel panel() builds. Build in the trigger, then end() it (mygo's CollapsibleBase). */
    public function collapsibleBase(bool &$open): CollapsibleParts
    {
        $trigger = $this->row()->focusable()->shrink(0)->role(Role::DISCLOSURE);
        $progress = $this->collapsibleToggle($trigger, $open);
        return new CollapsibleParts($trigger, $this, $open, $progress);
    }

    /** The panel of a collapsible: what $fn builds while it is open, and as it opens and closes, cut to its height; the column holding it, or null while closed. @internal */
    public function collapsiblePanelBase(bool $open, float $progress, Closure $fn): ?Node
    {
        if (!$open && $progress == 0) {
            return null;
        }
        $clip = $this->column()->shrink(0);
          $inner = $this->column()->shrink(0);
            $fn();
          $this->end();
        $this->end();
        if ($progress < 1) { // the height the content had in the last frame, 0 as it starts to open
            $clip->clipY()->size(0, max(1e-3, $progress * ($this->runtime?->rectOf($inner->id)[3] ?? 0.0)));
        }
        return $inner;
    }

    /**
     * A panel without a look below $anchor while $open: $fn($panel) styles
     * it and builds what is in it. A press outside the panel and the
     * anchor, or Escape, sets $open false, which dismissed() reports; the
     * press goes on to what is under it. It starts at the anchor's start,
     * or above it where there is no room below; $fn may attachTo()
     * elsewhere. The panel, or null while closed (mygo's PopoverBase).
     */
    public function popoverBase(Node $anchor, bool &$open, Closure $fn): ?Node
    {
        if (!$open) {
            return null;
        }
        $panel = null;
        $this->overlay(function () use ($anchor, &$open, $fn, &$panel): void {
            $panel = $this->key('popover:' . $anchor->id)->column()->role(Role::POPUP)->attachTo($anchor, Anchor::BottomStart, Anchor::TopStart);
              $fn($panel);
            $this->end();
            if ($this->overlayShortcut($panel, Key::ESCAPE) || $this->pressedOutside($panel)) {
                $open = false;
                $panel->dismissed = true;
            }
        });
        return $panel;
    }

    /** A dialog without a look over the window while $open: $fn($backdrop, $panel) styles the backdrop covering the window, which centers the panel and makes what is behind it inert, and the panel, and builds what is in it. A click on the backdrop or Escape sets $open false, which dismissed() reports. The panel, or null while closed (mygo's DialogBase). */
    public function dialogBase(bool &$open, Closure $fn): ?Node
    {
        if (!$open) {
            return null;
        }
        $panel = null;
        $this->overlay(function () use (&$open, $fn, &$panel): void {
            $back = $this->key('dialog')->column()->absolute()->left(0)->top(0)->right(0)->bottom(0)->center()->block();
              $panel = $this->column()->role(Role::DIALOG);
                $fn($back, $panel);
              $this->end();
            $this->end();
            if (($this->clicked($back) && !$this->hovered($panel)) || $this->overlayShortcut($back, Key::ESCAPE)) {
                $open = false;
                $panel->dismissed = true;
            }
        });
        return $panel;
    }

    /**
     * The tip of $anchor without a look, while the pointer rests on it:
     * $fn($tip) styles it and builds what is in it. It goes above the
     * anchor, centered, or below where there is no room; $fn may
     * attachTo() elsewhere. The pointer goes through it, Escape hides it,
     * and a press on the anchor. The tip, or null while it does not show
     * (mygo's TooltipBase).
     */
    public function tooltipBase(Node $anchor, Closure $fn): ?Node
    {
        $anchor->tooltip ??= ''; // a tip of its own: the painter paints none
        $rt = $this->runtime;
        if ($rt === null || $rt->tip === null || $rt->tipAnchor !== $anchor->id) {
            return null;
        }
        $tip = null;
        $this->overlay(function () use ($anchor, $fn, &$tip, $rt): void {
            $tip = $this->key('tip:' . $anchor->id)->column()->absolute()->passThrough()->role(Role::TOOLTIP);
              $fn($tip);
            $this->end();
            if ($tip->attach === null) { $tip->attachTo($anchor, Anchor::Top, Anchor::Bottom); }
            if ($this->overlayShortcut($tip, Key::ESCAPE)) { $rt->dismissTip(); }
        });
        return $tip;
    }

    /** A select without a look: a trigger opening a popup of options, which choose $selected; with the focus on the trigger, the arrows open it and move among its options, Enter and Space choose one, Escape closes it; the trigger's changed() reports a new choice. Build in $parts->trigger, end() it, then $parts->popup() with item() (mygo's SelectBase). */
    public function selectBase(mixed &$selected): SelectParts
    {
        $rt = $this->runtime;
        $b = $this->buttonBase()->role(Role::POP_UP_BUTTON);
        $b->description = is_scalar($selected) ? (string)$selected : '';
        $local = $rt?->local($b->id, fn() => (object)['open' => false, 'highlight' => -1, 'values' => [], 'pointer' => [0.0, 0.0], 'shown' => null]) ?? (object)['open' => false, 'highlight' => -1, 'values' => [], 'pointer' => [0.0, 0.0], 'shown' => null];
        $parts = new SelectParts($b, $this, $selected, $local);
        $n = count($local->values);
        $open = function (bool $o) use ($local, $rt): void {
            $local->open = $o; $local->highlight = -1; $local->shown = null;
            $local->pointer = [$rt?->input->mouseX ?? 0.0, $rt?->input->mouseY ?? 0.0];
            $rt?->changed();
        };
        $keyed = $rt !== null && $rt->activating($b->id); // Enter or Space on the trigger, rather than the pointer
        if ($local->open && $keyed && $this->clicked($b)) {
            if ($local->highlight >= 0 && $local->highlight < $n) { $parts->choose($local->values[$local->highlight]); } else { $open(false); }
        } elseif ($this->clicked($b)) {
            $open(!$local->open);
        }
        if ($this->focused($b)) {
            if (!$local->open && ($this->shortcut(Key::DOWN) || $this->shortcut(Key::UP))) {
                $open(true);
            } elseif ($local->open && $n > 0) {
                $move = fn(int $i) => $local->highlight = max(0, min($i, $n - 1));
                if ($this->shortcut(Key::DOWN)) { $move($local->highlight + 1); }
                if ($this->shortcut(Key::UP)) { $move($local->highlight - 1); }
                if ($this->shortcut(Key::HOME)) { $move(0); }
                if ($this->shortcut(Key::END)) { $move($n - 1); }
            }
        }
        $b->expanded($local->open);
        return $parts;
    }

    /** A combobox without a look: a text input editing $text, whose popup of options, built with popup() and item(), opens below it as the user types, clicks it or presses Down; Up and Down move among the options, Enter or a click chooses one, which chosen() reports, and Escape, or the input losing the focus, closes it (mygo's ComboboxBase). */
    public function comboboxBase(TextState $text): ComboboxParts
    {
        $rt = $this->runtime;
        $in = $this->textInputBase($text)->role(Role::COMBO_BOX);
        $make = fn() => (object)['open' => false, 'typed' => false, 'highlight' => -1, 'values' => [], 'pointer' => [0.0, 0.0], 'shown' => null, 'seen' => $text->text];
        $local = $rt?->local($in->id, $make) ?? $make();
        $parts = new ComboboxParts($in, $this, $text, $local);
        $focused = $rt !== null && $rt->focus === $in->id;
        $set = function (bool $o, bool $typed, int $highlight) use ($local, $rt): void {
            if ($local->open !== $o || $local->typed !== $typed || $local->highlight !== $highlight) { $rt?->changed(); }
            $local->open = $o; $local->typed = $typed; $local->highlight = $highlight;
            $local->pointer = [$rt?->input->mouseX ?? 0.0, $rt?->input->mouseY ?? 0.0];
        };
        $typed = $local->seen !== $text->text;
        $local->seen = $text->text;
        $n = count($local->values);
        if (!$focused) {
            if ($local->open) { $set(false, false, -1); }
        } elseif ($typed) {
            $set(true, true, 0);
        } elseif ($this->clicked($in) && !$local->open) {
            $set(true, false, -1);
        }
        if ($focused) {
            if ($this->shortcut(Key::DOWN)) { if (!$local->open) { $set(true, $local->typed, 0); } elseif ($n > 0) { $local->highlight = min($local->highlight + 1, $n - 1); $rt->changed(); } }
            if ($this->shortcut(Key::UP)) { if (!$local->open) { $set(true, $local->typed, -2); } elseif ($n > 0) { $local->highlight = max($local->highlight - 1, 0); $rt->changed(); } } // -2: the last, once known
            if ($local->open && $this->shortcut(Key::ESCAPE)) { $set(false, false, -1); } // closed, Escape is the dialog's
            if ($text->submitted && $local->open && $local->highlight >= 0 && $local->highlight < $n) {
                $text->submitted = false;
                $parts->choose($local->values[$local->highlight]);
            }
        }
        $in->expanded($local->open);
        return $parts;
    }

    /** A single-line text input without a look, editing $state: input() without its padding, face, border and corners (mygo's TextInputBase). */
    public function textInputBase(TextState $state): Node
    {
        $t = $this->theme;
        [, $h] = $this->metrics->measure('X', $t->fontSize);
        $n = $this->add(new Node(Node::INPUT, 0, 0, 0, 0, $h, 0, '', null, $t->fontSize, $t->text));
        $n->edit = $state; $n->bare = true; $n->changed = $state->editedSinceBuilt();
        $n->metrics = $this->metrics; // a style of its own (fontSize()) measures its line again in layout
        $n->inset(-Runtime::FIELD_INSET, -Runtime::FIELD_INSET)->minWidth($t->space(20))->minHeight(0);
        $n->focused = $this->runtime?->focused($n->id) ?? false;
        return $n;
    }

    /** A multi-line text input without a look, editing $state: textarea() without its padding, face, border and corners (mygo's TextAreaBase). */
    public function textAreaBase(TextState $state): Node
    {
        $t = $this->theme;
        $state->multiline = true;
        $n = $this->add(new Node(Node::TEXTAREA, 0, 0, 0, 0, 0, 1, '', null, $t->fontSize, $t->text));
        $n->edit = $state; $n->bare = true; $n->changed = $state->editedSinceBuilt();
        $n->metrics = $this->metrics; // a style of its own (fontSize()) measures its line again in layout
        $n->focused = $this->runtime?->focused($n->id) ?? false;
        return $n;
    }

    /** textInputBase() editing a TextBuffer: what the user types replaces what changed in it, a change of the program's shows (mygo's TextInputBufferBase). */
    public function textInputBufferBase(TextBuffer $buffer): Node
    {
        return $this->bufferControl($buffer, $this->textInputBase(...), Node::INPUT);
    }

    /** textAreaBase() editing a TextBuffer (mygo's TextAreaBufferBase). */
    public function textAreaBufferBase(TextBuffer $buffer): Node
    {
        return $this->bufferControl($buffer, $this->textAreaBase(...), Node::TEXTAREA);
    }

    /** The control $control makes of a TextState, a node of $kind, editing $buffer: what the user types replaces what changed in it, a change of the program's shows. @param \Closure(TextState): Node $control */
    protected function bufferControl(TextBuffer $buffer, \Closure $control, int $kind): Node
    {
        $id = $this->nextId($kind);
        $make = fn() => (object)['state' => new TextState($buffer->string()), 'version' => $buffer->version()];
        $local = $this->runtime?->local($id, $make) ?? $make();
        $s = $local->state;
        if ($local->version !== $buffer->version()) { // the program changed the buffer: the input shows it
            $s->text = $buffer->string(); $s->setCursor(min($s->cursor, strlen($s->text)));
            $local->version = $buffer->version();
        }
        $n = $control($s);
        $old = $buffer->string();
        if ($s->text !== $old) { // what was typed: the edit of what lies between the start and the end the texts share
            $new = $s->text; $lo = strlen($old); $ln = strlen($new);
            $p = 0;
            while ($p < min($lo, $ln) && $old[$p] === $new[$p]) { $p++; }
            while ($p > 0 && $p < $lo && (ord($old[$p]) & 0xC0) === 0x80) { $p--; } // on a rune's start
            $q = 0;
            while ($q < min($lo, $ln) - $p && $old[$lo - 1 - $q] === $new[$ln - 1 - $q]) { $q++; }
            while ($q > 0 && (ord($old[$lo - $q]) & 0xC0) === 0x80) { $q--; }
            $a = mb_strlen(substr($old, 0, $p), 'UTF-8');
            $buffer->replace($a, $a + mb_strlen(substr($old, $p, $lo - $p - $q), 'UTF-8'), substr($new, $p, $ln - $p - $q));
            $local->version = $buffer->version();
            $n->changed = true;
        }
        return $n;
    }
}
