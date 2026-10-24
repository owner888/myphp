<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** A font family text can use, as Font::families lists it (mygo's FontFamily). */
final class FontFamily
{
    public function __construct(
        /** The family, as fontFamily() and Theme take it. */
        public readonly string $name,
        /** Whether its regular font gives every character the same advance, as a terminal's or a code editor's font setting offers. */
        public readonly bool $monospaced = false,
        /** Whether the app registered it with Font::register, under this name. */
        public readonly bool $registered = false,
    ) {}
}
