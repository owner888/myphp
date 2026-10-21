<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal\Vt;

/** A program's request to read the clipboard (mygo's vt.ClipboardRead). @internal */
final class ClipboardRead
{
    /** @param list<string> $mimes the types asked for, in order of preference */
    public function __construct(
        public array $mimes = [],
        /** Asks for the types available. */
        public bool $list = false,
        /** The selection clipboard of X11. */
        public bool $primary = false,
        /** The program holds the terminal's permission, as when it reads what a paste offered it. */
        public bool $granted = false,
    ) {}
}
