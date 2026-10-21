<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Sqlite;

/** An operation used a closed database (mygo's sqlite.ErrClosed). */
final class ErrClosed extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('sqlite: database is closed');
    }
}
