<?php
declare(strict_types=1);

namespace MyPHP;

use MyPHP\UI\Content;

/** What Window::flyout creates a flyout with (mygo's FlyoutOptions); a flyout has a parent or a tray. */
final class FlyoutOptions
{
    /** Where a flyout goes from its anchor: the side, and how it lines up with the anchor along that side. */
    public const BOTTOM_START = 'bottom-start', BOTTOM = 'bottom', BOTTOM_END = 'bottom-end';
    public const TOP_START = 'top-start', TOP = 'top', TOP_END = 'top-end';
    public const RIGHT_START = 'right-start', RIGHT = 'right', RIGHT_END = 'right-end';
    public const LEFT_START = 'left-start', LEFT = 'left', LEFT_END = 'left-end';

    public function __construct(
        /** Owns the flyout, which stays above it, follows it as it moves and closes with it. */
        public ?Window $parent = null,
        /** Anchors the flyout to a tray icon instead, as the panel of a menu bar app: next to the icon, above the windows of other apps; $anchor does not apply. */
        public ?Tray $tray = null,
        /** The rectangle the flyout is placed against, in points from the top-left of the parent's content: the box of what opens it (Context::bounds), or a point. */
        public ?Rectangle $anchor = null,
        /** Where it goes from the anchor (BOTTOM_START by default); where the display has no room it flips to the other side or end, then slides back into the work area, then shrinks to it. */
        public string $placement = self::BOTTOM_START,
        /** The distance between the anchor and the flyout. */
        public int $gap = 0,
        public int $width = 200,
        public int $height = 200,
        /** Takes the keyboard as it shows, and closes as a menu does when the user presses outside it, another window takes the keyboard or the app is deactivated ('close' listeners may keep it open); a press on the anchor only closes it. Without it, it never takes the keyboard and stays until closed: tooltips, hover cards, a field's suggestions. Either closes on an Escape its content leaves. */
        public bool $focusable = false,
        public bool $hidden = false,
        /** Gives the flyout the system's window shadow, which follows what its content draws. */
        public bool $shadow = false,
        /** Shows the flyout in an NSPopover, which draws the system's material, rounded corners, shadow and an arrow pointing at the middle of the anchor, and animates as it opens and closes; AppKit places it on the side $placement names, or the opposite one where the screen has no room: the rest of $placement, and $gap, do not apply. It has the keyboard while it shows, focusable or not, which makes it close as the user clicks elsewhere; its content draws no background (Context::vibrancy says the material shows). */
        public bool $popover = false,
        /** Puts a translucent, blurred material behind the flyout's content, as WindowOptions::$vibrancy does behind a window's: the content draws no background where it shows (Context::vibrancy says so). A popover has its own. */
        public string $vibrancy = '',
        /** Rounds the flyout's corners, in points: its material and content are cut to them, and its shadow follows them. */
        public int $cornerRadius = 0,
        /** Native UI in it (Content::view), or a page: HTML, or a URL, as WindowOptions'. */
        public ?Content $content = null,
        public ?string $html = null,
        public ?string $url = null,
        /** The flyout's page, as WindowOptions' (mygo's Page). */
        public PageOptions $page = new PageOptions(),
    ) {}
}
