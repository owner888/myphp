<?php
declare(strict_types=1);

namespace MyPHP;

/** Why a page failed to load, given to its 'didFailLoad' listeners (mygo's LoadError): WebKit's error code and description. */
final class LoadError extends \RuntimeException
{
    public function __construct(
        public readonly string $url,
        int $code,
        public readonly string $description,
    ) {
        parent::__construct("myphp: failed to load $url: $description ($code)", $code);
    }
}
