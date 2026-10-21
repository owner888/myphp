<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Sqlite;

/**
 * A BLOB, as a parameter and as a cell (mygo's []byte): PHP's strings are
 * bytes, so a string is TEXT, which must be UTF-8, and its bytes as a BLOB
 * are a Blob. An empty BLOB is not NULL.
 */
final class Blob
{
    public function __construct(public readonly string $bytes) {}
}
