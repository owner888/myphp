<?php
declare(strict_types=1);

namespace MyPHP;

/** A window.open() call, or a click on a link with target="_blank", given to Page::setWindowOpenHandler's handler (mygo's WindowOpenRequest). */
final class WindowOpenRequest
{
    public function __construct(
        public readonly string $url,
        public readonly string $frameName = '',
        /** The size window.open() asked for, 0 when it said none. */
        public readonly int $width = 0,
        public readonly int $height = 0,
    ) {}
}
