<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal\Vt;

/** The colors of a frame, 0xRRGGBB (mygo's vt.Colors): those programs set, else the defaults. @internal */
final class Colors
{
    /** @param list<int> $palette */
    public function __construct(
        public int $background = 0,
        public int $foreground = 0,
        /** The cursor's color when $hasCursor; it takes the text's otherwise. */
        public int $cursor = 0,
        public bool $hasCursor = false,
        public array $palette = [],
    ) {}

    public function equals(Colors $o): bool
    {
        return $this->background === $o->background && $this->foreground === $o->foreground && $this->cursor === $o->cursor
            && $this->hasCursor === $o->hasCursor && $this->palette === $o->palette;
    }
}
