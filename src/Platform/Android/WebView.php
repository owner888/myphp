<?php
declare(strict_types=1);

namespace MyPHP\Platform\Android;

use MyPHP\Native\Android;
use MyPHP\Platform;

/**
 * Embedded WebView in native UI on Android, overlaying the SurfaceView.
 */
final class WebView implements Platform\WebView
{
    private bool $closed = false;

    public function __construct(
        public readonly Window $host,
        public readonly Page $page,
        bool $scrollToContainer,
        bool $transparent
    ) {}

    public function focus(): void {}
    public function tabInto(bool $back): void {}
    public function holds(mixed $view): bool { return false; }
    public function hasKeyboard(): bool { return false; }
    public function setBackgroundColor(array $rgba): void {}

    public function place(?array $frame, ?array $clip, float $height): void
    {
        if ($this->closed) {
            return;
        }

        $f = Android::ffi();
        if ($frame === null || $frame[2] <= 0 || $frame[3] <= 0) {
            $f->myphp_place_web_view($this->page->id, 0, 0, 0, 0, 0);
            return;
        }

        $density = max(1.0, $f->myphp_get_density());
        $x = (int)round($frame[0] * $density);
        $y = (int)round($frame[1] * $density);
        $w = (int)round($frame[2] * $density);
        $h = (int)round($frame[3] * $density);

        $f->myphp_place_web_view($this->page->id, $x, $y, $w, $h, 1);
    }

    public function setAlpha(float $alpha): void {}
    public function clipView(): mixed { return null; }

    public function close(): void
    {
        if (!$this->closed) {
            $this->closed = true;
            $this->page->close();
        }
    }
}
