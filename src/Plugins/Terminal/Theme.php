<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

use MyPHP\Plugins\Terminal\Vt\Terminal as VtTerminal;

/**
 * The colors of a terminal, 0xRRGGBB (mygo's terminal.Theme), which
 * programs may change (OSC 4, 10, 11, 12) until the terminal resets.
 * Terminal::ghosttyTheme() returns Ghostty's themes.
 */
final class Theme
{
    /** @param list<int> $palette */
    public function __construct(
        public int $foreground = 0,
        public int $background = 0,
        /** Colors the cursor: the foreground when null. $cursorText colors the text under a block cursor: the background when null. */
        public ?int $cursor = null,
        public ?int $cursorText = null,
        /** Highlights the selected text: the foreground, a third opaque, when null. $selectionText colors the selected text: its own colors when null. */
        public ?int $selection = null,
        public ?int $selectionText = null,
        /** The 16 colors of programs: black, red, green, yellow, blue, magenta, cyan and white, then their bright kinds. */
        public array $palette = [],
    ) {}

    /** @internal the default theme of a window's appearance, one object each */
    public static function default(bool $dark): self
    {
        static $themes = null;
        $themes ??= [Terminal::lightTheme(), Terminal::darkTheme()];
        return $themes[$dark ? 1 : 0];
    }

    /** @internal whether the background is dark */
    public function isDark(): bool
    {
        $b = $this->background;
        return 299 * (($b >> 16) & 0xff) + 587 * (($b >> 8) & 0xff) + 114 * ($b & 0xff) < 128 * 1000;
    }

    /** @internal the 256 colors of the theme: its 16, the 6×6×6 cube and the 24 grays of xterm @return list<int> */
    public function palette256(): array
    {
        $p = [];
        for ($i = 0; $i < 16; $i++) { $p[] = $this->palette[$i] ?? 0; }
        $levels = [0, 95, 135, 175, 215, 255];
        for ($i = 0; $i < 216; $i++) { $p[] = ($levels[intdiv($i, 36)] << 16) | ($levels[intdiv($i, 6) % 6] << 8) | $levels[$i % 6]; }
        for ($i = 0; $i < 24; $i++) { $v = 8 + 10 * $i; $p[] = ($v << 16) | ($v << 8) | $v; }
        return $p;
    }

    /** @internal makes the theme the terminal's defaults */
    public function apply(VtTerminal $t): void
    {
        $t->setColors($this->foreground, $this->background, $this->cursor, $this->palette256());
    }
}
