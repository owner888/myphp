<?php
declare(strict_types=1);

namespace MyPHP;

/** A download that ended, given to a window's 'downloadDone' listeners (mygo's Download): $error is why it failed, null when it did not. */
final class Download
{
    public function __construct(
        public readonly string $url,
        public readonly string $path,
        public readonly ?string $error = null,
    ) {}
}
