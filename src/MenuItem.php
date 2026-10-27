<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;
use MyPHP\Native\ObjC;

/**
 * An entry of a Menu, as mygo's MenuItem. Fill the fields to build a menu;
 * once the menu is installed, change state through the set methods so the
 * native item follows. A role gives an item the system's behavior, label
 * and accelerator ('copy', 'quit', 'editMenu', ...), see Menu::ROLES. The
 * type is inferred when empty: a submenu for an item with a submenu, else
 * a normal item. Choosing a checkbox item toggles it, and a radio item
 * checks it and unchecks the radio items next to it, before $click runs.
 */
final class MenuItem
{
    /** HEADER: the title of the group of items under it (NSMenuItem's section header, macOS 14), chosen by none; left out before macOS 14, which has none. */
    public const SEPARATOR = 'separator', CHECKBOX = 'checkbox', RADIO = 'radio', NORMAL = 'normal', SUBMENU = 'submenu', HEADER = 'header';

    /** Set when the item is built; the native NSMenuItem. */
    public $native = null;
    public int $uid = 0;
    /** @internal what holds the item in its list: a Menu, or the item whose submenu it is */
    public Menu|MenuItem|null $parent = null;

    /**
     * @param MenuItem[] $submenu
     * @param null|Closure(MenuItem, ?Window): void $click
     */
    public function __construct(
        public string $label = '',
        public ?Closure $click = null,
        /** "CmdOrCtrl+Shift+Z", "Cmd+,", "F5", "Ctrl+Enter"... */
        public string $accelerator = '',
        public string $role = '',
        public string $type = '',
        public bool $disabled = false,
        /** The state of checkbox and radio items. */
        public bool $checked = false,
        public array $submenu = [],
        /** Identifies the item for Menu::itemById. */
        public string $id = '',
        public bool $hidden = false,
        public string $toolTip = '',
    ) {
        foreach ($this->submenu as $child) {
            $child->parent = $this;
        }
    }

    public static function separator(): self
    {
        return new self(type: self::SEPARATOR);
    }

    /** The title $label of the group of items under it (HEADER). */
    public static function header(string $label): self
    {
        return new self($label, type: self::HEADER);
    }

    public function setEnabled(bool $enabled): void
    {
        $this->disabled = !$enabled;
        if ($this->native !== null) {
            ObjC::msg('v:b', $this->native, 'setEnabled:', $enabled);
        }
    }

    /** Whether the item is enabled (mygo's IsEnabled). */
    public function isEnabled(): bool
    {
        return !$this->disabled;
    }

    /** Shows or hides the item (mygo's SetVisible). */
    public function setVisible(bool $visible): void
    {
        $this->hidden = !$visible;
        if ($this->native !== null) {
            ObjC::msg('v:b', $this->native, 'setHidden:', !$visible);
        }
    }

    /** Whether the item shows (mygo's IsVisible). */
    public function isVisible(): bool
    {
        return !$this->hidden;
    }

    /** Checks or unchecks a checkbox or radio item; checking a radio item unchecks the radio items next to it (mygo's SetChecked). */
    public function setChecked(bool $checked): void
    {
        if ($checked && $this->type === self::RADIO) {
            foreach ($this->radioGroup() as $s) {
                if ($s !== $this) { $s->check(false); }
            }
        }
        $this->check($checked);
    }

    /** Whether a checkbox or radio item is checked (mygo's IsChecked). */
    public function isChecked(): bool
    {
        return $this->checked;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
        if ($this->native !== null) {
            ObjC::msg('v:p', $this->native, 'setTitle:', ObjC::str($label));
            if (($sub = ObjC::msg('p:', $this->native, 'submenu')) !== null) {
                ObjC::msg('v:p', $sub, 'setTitle:', ObjC::str($label));
            }
        }
    }

    /** Changes the item's keyboard shortcut (mygo's SetAccelerator). */
    public function setAccelerator(string $accelerator): void
    {
        $this->accelerator = $accelerator;
        if ($this->native !== null) {
            [$key, $mask] = Menu::accelerator($accelerator);
            ObjC::msg('v:p', $this->native, 'setKeyEquivalent:', ObjC::str($key));
            ObjC::msg('v:L', $this->native, 'setKeyEquivalentModifierMask:', $mask);
        }
    }

    /** @internal the kind the item is, its type or the one inferred */
    public function kind(): string
    {
        return $this->type !== '' ? $this->type : ($this->submenu !== [] ? self::SUBMENU : self::NORMAL);
    }

    private function check(bool $checked): void
    {
        $this->checked = $checked;
        if ($this->native !== null) {
            ObjC::msg('v:l', $this->native, 'setState:', $checked && in_array($this->type, [self::CHECKBOX, self::RADIO], true) ? 1 : 0);
        }
    }

    /** The run of adjacent radio items the item is in (mygo's radioGroup). @return list<MenuItem> */
    private function radioGroup(): array
    {
        $items = $this->parent instanceof Menu ? $this->parent->items : ($this->parent?->submenu ?? [$this]);
        $items = array_values($items);
        $i = array_search($this, $items, true);
        if ($i === false) {
            return [$this];
        }
        [$a, $z] = [$i, $i];
        while ($a > 0 && $items[$a - 1]->type === self::RADIO) { $a--; }
        while ($z < count($items) - 1 && $items[$z + 1]->type === self::RADIO) { $z++; }
        return array_slice($items, $a, $z - $a + 1);
    }
}
