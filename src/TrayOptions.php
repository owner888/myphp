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
        /** Shown next to the icon. */
        public string $title = '',
        public string $toolTip = '',
        /** Shown when the icon is clicked; without one, clicks reach onClick/onRightClick. */
        public ?Menu $menu = null,
    ) {}
}
