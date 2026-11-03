<?php
declare(strict_types=1);

namespace MyPHP;

/** What a Tray is created with, as mygo's TrayOptions. */
final class TrayOptions
{
    public function __construct(
        /** PNG bytes, ideally 16×16 points (32×32 pixels for Retina). */
        public ?string $icon = null,
        /** Lets macOS tint a black-and-transparent icon to match the menu bar. */
        public bool $iconIsTemplate = false,
        /** System vector icon name (Apple SF Symbols on macOS, e.g. 'dot.radiowaves.left.and.right'). */
        public string $systemIcon = '',
        /** Shown next to the icon. */
        public string $title = '',
        /** Top line of a compact dual-line title (macOS). */
        public string $topTitle = '',
        /** Bottom line of a compact dual-line title (macOS). */
        public string $bottomTitle = '',
        public string $toolTip = '',
        /** Shown when the icon is clicked; without one, clicks reach onClick/onRightClick. */
        public ?Menu $menu = null,
        /**
         * Names the icon across launches, unique among the app's icons
         * (mygo's ID): the status item's autosave name, under which macOS
         * keeps where the user dragged it with ⌘ held, and puts it back
         * there. Empty, a new icon goes left of those already there.
         */
        public string $id = '',
    ) {}
}
