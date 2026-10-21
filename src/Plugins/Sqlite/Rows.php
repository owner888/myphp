<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Sqlite;

/**
 * Columns and their values in order, preserving duplicate column names
 * (mygo's sqlite.Rows). Values are null, int, float, string (TEXT) or Blob,
 * copied out of SQLite.
 */
final class Rows
{
    /** @param list<string> $columns @param list<list<null|int|float|string|Blob>> $values */
    public function __construct(public readonly array $columns = [], public readonly array $values = []) {}
}
