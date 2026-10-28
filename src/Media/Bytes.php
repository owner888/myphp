<?php
declare(strict_types=1);

namespace MyPHP\Media;

/**
 * The bytes a demuxer reads (Matroska): a file's (FileBytes), or a film's
 * over HTTP, as they come into its cache (HttpBytes). read() waits for
 * what is not there yet; has() says whether it is, without waiting, and
 * asks for it when not.
 */
interface Bytes
{
    /** How many there are. */
    public function size(): int;

    /** The $n at $pos (fewer past the end), waiting for them if they are not here yet. */
    public function read(int $pos, int $n): string;

    /** Whether the $n at $pos (up to the end) are here, so that read() waits for none; when not, they are asked for. */
    public function has(int $pos, int $n): bool;
}
