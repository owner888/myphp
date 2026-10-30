<?php
declare(strict_types=1);

namespace MyPHP;

/** Whether a page slows down while it cannot be seen (PageOptions::$backgroundThrottling, mygo's BackgroundThrottling). */
enum BackgroundThrottling
{
    /** The default: the web view slows down a page it cannot see: its timers fire once a second at most, requestAnimationFrame waits, and macOS may suspend the page of a window behind others. */
    case On;
    /**
     * Keeps the page's timers at their rate while its window is hidden,
     * minimized or covered, and its process at the priority of a visible
     * one, as Electron's backgroundThrottling: false: WKPreferences'
     * inactiveSchedulingPolicy none (macOS 14) and WebKit's private
     * preferences that throttle a hidden page's timers, suspend its CSS
     * animations and let App Nap slow its process turned off, and window
     * occlusion detection off, so that a covered window counts as visible.
     * The page of a hidden or minimized window still goes hidden
     * (document.visibilityState): requestAnimationFrame waits there; run
     * what must go on with timers.
     */
    case Off;
}
