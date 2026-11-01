<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;

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

    /** @internal identifies the item to the backend, which reports its clicks by it (mygo's uid) */
    public readonly int $uid;
    private static int $nextUid = 0;
    /** Whether a menu showed the item, whose native items follow its changes. */
    private bool $shown = false;
    /** @internal the number of a web view's own item of a page's context menu (ContextMenuEvent), 0 for the app's items */
    public int $webKit = 0;
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
        /** An image shown beside the label (MenuIcon::of), null for none. */
        public ?MenuIcon $icon = null,
        /**
         * Shown in place of the item before it while Option is held
         * (NSMenuItem's alternate): its accelerator that one's with Alt,
         * none the menu takes alone.
         */
        public bool $alternate = false,
        /**
         * Called as the item's submenu is about to show (NSMenuDelegate's
         * menuWillOpen:), to bring its items up to date: their set methods,
         * or setSubmenu for others; the item has a submenu, empty till then.
         * @var null|Closure(MenuItem): void
         */
        public ?Closure $onOpen = null,
    ) {
        $this->uid = ++self::$nextUid;
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
        $this->update();
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
        $this->update();
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
        $this->update();
    }

    /** Replaces the image beside the label; null removes it (mygo's SetIcon). */
    public function setIcon(?MenuIcon $icon): void
    {
        $this->icon = $icon;
        $this->update();
    }

    /**
     * Replaces the items of the item's submenu, which the menu shows at once
     * (an onOpen's list of what there is now: a playlist, the tracks).
     * @param list<MenuItem> $items
     */
    public function setSubmenu(array $items): void
    {
        $this->submenu = $items;
        foreach ($items as $child) {
            $child->parent = $this;
        }
        if ($this->shown && App::running()) {
            App::backend()->replaceSubmenu($this->uid, Menu::snapshotItem($this)->submenu ?? new Platform\Menu);
        }
    }

    /** Changes the item's keyboard shortcut (mygo's SetAccelerator). */
    public function setAccelerator(string $accelerator): void
    {
        $this->accelerator = $accelerator;
        $this->update();
    }

    /** @internal the kind the item is, its type or the one inferred */
    public function kind(): string
    {
        return $this->type !== '' ? $this->type : ($this->submenu !== [] ? self::SUBMENU : self::NORMAL);
    }

    private function check(bool $checked): void
    {
        $this->checked = $checked;
        $this->update();
    }

    /** @internal Menu::snapshotItem: a menu shows the item */
    public function shown(): void
    {
        $this->shown = true;
    }

    /** Has the native items of the item follow its state (mygo's update). */
    private function update(): void
    {
        if ($this->shown && App::running()) {
            App::backend()->updateMenuItem(Menu::snapshotItem($this, false));
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
