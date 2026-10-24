<?php
declare(strict_types=1);

namespace MyPHP\Audio;

/** What decoding data of no format registered throws (mygo's audio.ErrFormat). */
final class ErrFormat extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('audio: unknown format');
    }
}
