<?php
declare(strict_types=1);

namespace MyPHP\Platform;

/**
 * A snapshot of a menu item (mygo's platform.MenuItem). $id identifies the
 * public item it was built from, which AppHandler::menuItemClicked reports;
 * the core expanded its role into a label, an accelerator and a submenu.
 */
final class MenuItem
{
    /** The kinds, as \MyPHP\MenuItem's. */
    public const NORMAL = 'normal', SEPARATOR = 'separator', SUBMENU = 'submenu', CHECKBOX = 'checkbox', RADIO = 'radio', HEADER = 'header';

    public function __construct(
        public int $id = 0,
        public string $type = self::NORMAL,
        public string $label = '',
        /** The role a backend may perform itself, as macOS sends "copy:" down the responder chain; the core performs the others when told the item was clicked. */
        public string $role = '',
        public string $accelerator = '',
        public bool $enabled = true,
        public bool $visible = true,
        public bool $checked = false,
        public string $toolTip = '',
        public ?\MyPHP\MenuIcon $icon = null,
        public ?Menu $submenu = null,
        /**
         * Shown in place of the item before it while Option is held
         * (macOS's alternate items; myphp's).
         */
        public bool $alternate = false,
        /** The core wants AppHandler::menuWillOpen($id) as the item's submenu is about to show (myphp's). */
        public bool $opens = false,
        /**
         * Set on the web view's own items of a page's context menu: the
         * backend's number for the item, from 1, which it shows doing what
         * the web view does; $name is then its name.
         */
        public int $native = 0,
        public string $name = '',
    ) {}
}
