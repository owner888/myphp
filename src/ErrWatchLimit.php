<?php
declare(strict_types=1);

namespace MyPHP;

/** What a watch stops with when its directory has more than FileWatchOptions::$maxEntries files and directories (mygo's ErrWatchLimit). */
final class ErrWatchLimit extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('myphp: file watch entry limit exceeded');
    }
}
