<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use MyPHP\Native\iOS;
use MyPHP\Platform;

/**
 * Embedded WKWebView in native UI on iOS, overlaying the Metal view.
 */
final class WebView implements Platform\WebView
{
    private bool $closed = false;

    public function __construct(
        public readonly Window $host,
        public readonly Page $page,
        bool $scrollToContainer,
        bool $transparent
    ) {
    }

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

        try {
            $f = iOS::ffi();
            if ($frame === null || $frame[2] <= 0 || $frame[3] <= 0) {
                $f->myphp_ios_place_web_view($this->page->id, 0.0, 0.0, 0.0, 0.0, 0);
                return;
            }

            $x = (float)$frame[0];
            $y = (float)$frame[1];
            $w = (float)$frame[2];
            $h = (float)$frame[3];

            $f->myphp_ios_place_web_view($this->page->id, $x, $y, $w, $h, 1);
        } catch (\Throwable $e) {}
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
