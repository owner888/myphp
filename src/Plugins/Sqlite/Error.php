<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Sqlite;

/**
 * An SQLite error (mygo's sqlite.Error): getCode() is the primary result
 * code; $extendedCode distinguishes such errors as unique, foreign-key and
 * not-null constraints; $sqliteMessage is SQLite's own message.
 */
final class Error extends \RuntimeException
{
    public function __construct(int $code, public readonly int $extendedCode, public readonly string $sqliteMessage)
    {
        parent::__construct("sqlite: $sqliteMessage (code $extendedCode)", $code);
    }
}
