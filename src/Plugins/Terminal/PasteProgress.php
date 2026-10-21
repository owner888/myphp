<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

/** How a paste a program reads itself goes (Options::$onPasteStatus, mygo's terminal.PasteProgress). */
final class PasteProgress
{
    public function __construct(
        public PasteStatus $status,
        /** The bytes of the reply written to the program, encoded as the protocol sends them, for PasteSending and PasteSent: $total is known once the program reads. */
        public int $sent = 0,
        public int $total = 0,
    ) {}
}
