<?php
declare(strict_types=1);

namespace MyPHP\Platform;

/**
 * A web view in a window showing native UI (mygo's platform.WebView,
 * Surface.NewWebView): its page under the native view, shown where the
 * content places it (place), its holes letting it show.
 */
interface WebView
{
    /** Gives the web view the keyboard. */
    public function focus(): void;

    /** Gives it the keyboard as Tab does: at its page's first element, or its last for $back. */
    public function tabInto(bool $back): void;

    /** Whether the native view $view is the web view or inside it. */
    public function holds(mixed $view): bool;

    /** Whether its page has the keyboard. */
    public function hasKeyboard(): bool;

    /** Paints $rgba (0–255) until its page does. @param array{0: int, 1: int, 2: int, 3: int} $rgba */
    public function setBackgroundColor(array $rgba): void;

    /**
     * Shows it at $frame, of which $clip shows, in the points of the
     * window's flipped native view of height $height; null hides it.
     * @param ?array{0: float, 1: float, 2: float, 3: float} $frame
     * @param ?array{0: float, 1: float, 2: float, 3: float} $clip
     */
    public function place(?array $frame, ?array $clip, float $height): void;

    /** Its opacity, 0 to 1. */
    public function setAlpha(float $alpha): void;

    /** The native view the window stacks in paint order. */
    public function clipView(): mixed;

    /** Takes it out of the window. */
    public function close(): void;
}
