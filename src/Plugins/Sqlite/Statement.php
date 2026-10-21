<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Sqlite;

/** One parameterized statement of a transaction (mygo's sqlite.Statement). */
final class Statement
{
    /** @param list<mixed> $args */
    public function __construct(public readonly string $sql, public readonly array $args = []) {}
}
