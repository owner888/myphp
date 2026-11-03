<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use MyPHP\Platform;

final class WebView implements Platform\WebView
{
    private bool $shown = false;
    private bool $closed = false;

    public function __construct(
        private readonly Window $host,
        private readonly Platform\Page $page,
        bool $scrollToContainer,
        bool $transparent
    ) {
    }

    public function focus(): void
    {
    }

    public function tabInto(bool $back): void
    {
    }

    public function place(int $x, int $y, int $w, int $h, int $cx, int $cy, int $cw, int $ch): void
    {
    }

    public function hide(): void
    {
        $this->shown = false;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
