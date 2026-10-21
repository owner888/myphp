<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Sqlite;

/** What a statement did (mygo's sqlite.Result): $changes counts directly affected rows; $lastInsertId is SQLite's last inserted rowid on this connection. */
final class Result
{
    public function __construct(public readonly int $changes = 0, public readonly int $lastInsertId = 0) {}
}
