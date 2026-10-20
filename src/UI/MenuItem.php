<?php
declare(strict_types=1);

namespace MyPHP\UI;

use MyPHP\MenuItem as NativeItem;

/** An item of a Menu (mygo's MenuItem): its methods set it up and return it, for chaining, and chosen() reports the choice. */
final class MenuItem
{
    public bool $disabled = false, $sub = false;
    /** @internal what the menu shows of it, when it opens */
    public ?NativeItem $p = null;

    /** @internal */
    public function __construct(private MenuRun $run, public readonly int $seq, public readonly string $label) {}

    /** Shows the item grayed out when $d is true: it cannot be chosen. */
    public function disabled(bool $d): static
    {
        $this->disabled = $d;
        if ($this->p !== null) { $this->p->disabled = $d; }
        return $this;
    }

    /** Shows a check mark by the item when $on is true, as for a setting that choosing the item toggles. */
    public function checked(bool $on): static
    {
        if ($this->p !== null && !$this->sub) {
            $this->p->type = NativeItem::CHECKBOX;
            $this->p->checked = $on;
        }
        return $this;
    }

    /** Shows a key with modifiers (Key::COMMAND…, a Key code) by the item, as the shortcut that does the same; the view handles the key itself, as with Context::shortcut. */
    public function shortcut(int $mods, int $key): static
    {
        if ($this->p !== null) { $this->p->accelerator = Runtime::accelerator($mods, $key); }
        return $this;
    }

    /** Whether the item was chosen, in the frame after the user chose it. */
    public function chosen(): bool
    {
        $r = $this->run;
        if ($r->collect || $this->disabled || $this->sub || $r->choice !== $this->seq || $r->label !== $this->label) {
            return false;
        }
        $r->chosen = true;
        return true;
    }
}
