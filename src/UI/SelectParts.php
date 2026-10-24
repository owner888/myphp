<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** The parts of a select without a look (mygo's SelectParts): $trigger, built in until end(), its popup popup() builds, and the options item() makes in it. */
final class SelectParts
{
    /** @var list<mixed> the options this frame's popup built */
    private array $next = [];

    /** @param object $local what the trigger keeps across frames: open, highlight, values, pointer, shown */
    public function __construct(public readonly Node $trigger, private readonly Context $c, private mixed &$selected, private readonly object $local) {}

    /** Whether the popup shows. */
    public function open(): bool
    {
        return $this->local->open;
    }

    /** Moves the highlight to the option of $value while the popup shows, as typing its first letters does; Enter then chooses it. */
    public function highlight(mixed $value): void
    {
        $i = array_search($value, $this->local->values, true);
        if ($i !== false && $this->local->highlight !== $i) {
            $this->local->highlight = $i;
            $this->c->runtime?->changed();
        }
    }

    /** Chooses $value and closes the popup. @internal */
    public function choose(mixed $value): void
    {
        if ($this->selected !== $value) {
            $this->selected = $value;
            $this->trigger->changed = true;
        }
        $this->local->open = false;
        $this->c->runtime?->changed();
    }

    /** Shows the popup below the trigger, at least as wide, while it is open: $fn($panel) styles it and builds the options with item(). A press outside or Escape closes it. The panel, or null while closed. */
    public function popup(\Closure $fn): ?Node
    {
        $open = $this->local->open;
        $w = $this->c->runtime?->rectOf($this->trigger->id)[2] ?? 0.0;
        $panel = $this->c->popoverBase($this->trigger, $open, function (Node $panel) use ($fn, $w): void {
            $panel->minWidth($w);
            $this->next = [];
            $fn($panel);
            $this->local->values = $this->next;
        });
        if (!$open && $this->local->open) { $this->local->open = false; } // dismissed
        return $panel;
    }

    /** An option choosing $value: a row that is highlighted() while the pointer or the arrows are on it and chooses $value when clicked; build in it until end(). The option the highlight moves to scrolls into view, once. */
    public function item(mixed $value): Node
    {
        $c = $this->c; $rt = $c->runtime; $l = $this->local;
        $i = count($this->next);
        $this->next[] = $value;
        $item = $c->row()->role(Role::MENU_ITEM);
        if ($l->highlight < 0 && $value === $this->selected) { $l->highlight = $i; }
        $p = [$rt?->input->mouseX ?? 0.0, $rt?->input->mouseY ?? 0.0];
        if ($c->hovered($item) && $p !== $l->pointer) { // the pointer moved onto it
            $l->pointer = $p;
            if ($l->highlight !== $i) { $l->highlight = $i; $rt?->changed(); }
        }
        $item->highlighted = $l->highlight === $i;
        if ($item->highlighted && $l->shown !== $value) {
            $l->shown = $value;
            $item->scrollIntoView();
        }
        $item->checked($value === $this->selected);
        if ($c->clicked($item)) { $this->choose($value); }
        return $item;
    }
}
