<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

/**
 * Something other than a program a terminal connects to (Options::$conn,
 * mygo's io.ReadWriteCloser), as a remote shell: what the terminal reads
 * from it shows, and what is typed is written to it. Neither blocks: the
 * terminal polls it on the run loop. A Conn with a method resize(int $cols,
 * int $rows) is told the size.
 */
interface Conn
{
    /** What there is to read: '' for nothing yet, null once it ended. */
    public function read(): ?string;

    /** Writes as much of $b as it takes now, and returns how many bytes it took. */
    public function write(string $b): int;

    public function close(): void;
}
