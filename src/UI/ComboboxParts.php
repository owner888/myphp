<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** The parts of a combobox without a look (mygo's ComboboxParts): $input, the text input whose text filters the options, its popup popup() builds and the options item() makes in it. */
final class ComboboxParts
{
    /** @var list<string> the options this frame's popup built */
    private array $next = [];
    /** @var list<Node> */
    private array $items = [];
    private ?string $chosen = null;

    /** @param object $local what the input keeps across frames: open, typed, highlight, values, pointer, shown */
    public function __construct(public readonly Node $input, private readonly Context $c, private readonly TextState $text, private readonly object $local) {}

    /** The option the user chose this frame, by a click or Enter, or null: ask it after popup(). */
    public function chosen(): ?string
    {
        return $this->chosen;
    }

    /** Whether the popup shows, and opens or closes it. */
    public function open(): bool
    {
        return $this->local->open;
    }

    public function setOpen(bool $open): void
    {
        if ($this->local->open !== $open) {
            $this->local->open = $open; $this->local->typed = false; $this->local->highlight = -1;
            $this->c->runtime?->changed();
        }
    }

    /** Whether the text is what the user typed since the popup opened, which the options should match; not when a click or Down opened it, which shows them all. */
    public function filtering(): bool
    {
        return $this->local->typed;
    }

    /** Chooses $value: the input takes it, the popup closes. @internal */
    public function choose(string $value): void
    {
        $this->chosen = $value;
        $this->text->text = $value; $this->text->setCursor(strlen($value));
        $this->local->seen = $value;
        $this->local->open = false; $this->local->typed = false; $this->local->highlight = -1;
        $this->input->changed = true;
        $this->c->runtime?->changed();
    }

    /** Shows the popup below the input, at least as wide, while it is open: $fn($panel) styles it and builds the options with item(). The panel, or null while closed or without options. */
    public function popup(\Closure $fn): ?Node
    {
        $this->next = []; $this->items = [];
        $l = $this->local;
        if (!$l->open) {
            $l->values = []; $l->shown = null;
            return null;
        }
        $w = $this->c->runtime?->rectOf($this->input->id)[2] ?? 0.0;
        $panel = null;
        $this->c->overlay(function () use (&$panel, $fn, $w): void {
            $panel = $this->c->key('combo:' . $this->input->id)->column()->minWidth($w)->role(Role::LIST)->attachTo($this->input, Anchor::BottomStart, Anchor::TopStart)->keepFocus();
            $panel->choosesItems = true;
              $fn($panel);
            $this->c->end();
        });
        $l->values = $this->next;
        $n = count($this->next);
        if ($l->highlight === -2 || $l->highlight >= $n) { $l->highlight = $n - 1; }
        foreach ($this->items as $i => $item) { $item->setPos = $i + 1; $item->setSize = $n; }
        if ($l->highlight >= 0 && $l->highlight < $n) { $this->input->activeDescendant($this->items[$l->highlight]); }
        if ($n === 0) {
            $panel->invisible(); // nothing to show
            return null;
        }
        return $panel;
    }

    /** An option: a row that is highlighted() while the pointer or the arrows are on it, and chooses $value when clicked; build in it until end(). */
    public function item(string $value): Node
    {
        $c = $this->c; $rt = $c->runtime; $l = $this->local;
        $i = count($this->next);
        $this->next[] = $value;
        $item = $c->row()->role(Role::LIST_ITEM);
        $item->choosable = true;
        $this->items[] = $item;
        $p = [$rt?->input->mouseX ?? 0.0, $rt?->input->mouseY ?? 0.0];
        if ($c->hovered($item) && $p !== $l->pointer) {
            $l->pointer = $p;
            if ($l->highlight !== $i) { $l->highlight = $i; $rt?->changed(); }
        }
        $item->highlighted = $l->highlight === $i;
        if ($item->highlighted && $l->shown !== $value) {
            $l->shown = $value;
            $item->scrollIntoView();
        }
        $item->checked($item->highlighted); // the option the arrows are on is the one assistive technology reads as chosen
        if ($c->clicked($item)) { $this->choose($value); }
        return $item;
    }
}
