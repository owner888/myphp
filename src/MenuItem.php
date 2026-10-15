<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;
use MyPHP\Native\ObjC;

/**
 * An entry of a Menu, as mygo's MenuItem. Fill the fields to build a menu;
 * once the menu is installed, change state through the set methods so the
 * native item follows. A role gives an item the system's behavior, label
 * and accelerator ('copy', 'quit', 'editMenu', ...), see Menu::ROLES.
 */
final class MenuItem
{
    public const SEPARATOR = 'separator', CHECKBOX = 'checkbox', NORMAL = 'normal', SUBMENU = 'submenu';

    /** Set when the item is built; the native NSMenuItem. */
    public $native = null;
    public int $uid = 0;

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
        public bool $checked = false,
        public array $submenu = [],
        public string $id = '',
    ) {}

    public static function separator(): self
    {
        return new self(type: self::SEPARATOR);
    }

    public function setEnabled(bool $enabled): void
    {
        $this->disabled = !$enabled;
        if ($this->native !== null) {
            ObjC::msg('v:b', $this->native, 'setEnabled:', $enabled);
        }
    }

    public function setChecked(bool $checked): void
    {
        $this->checked = $checked;
        if ($this->native !== null) {
            ObjC::msg('v:l', $this->native, 'setState:', $checked ? 1 : 0);
        }
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
        if ($this->native !== null) {
            ObjC::msg('v:p', $this->native, 'setTitle:', ObjC::str($label));
        }
    }
}
