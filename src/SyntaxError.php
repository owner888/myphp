<?php
declare(strict_types=1);

namespace MyPHP;

/** The error of a message that does not parse (mygo's i18n.SyntaxError): the message, the byte offset in it of the error, and what is wrong. */
final class SyntaxError extends \InvalidArgumentException
{
    public function __construct(public readonly string $source, public readonly int $offset, public readonly string $error)
    {
        parent::__construct(sprintf('i18n: %s at offset %d of %s', $error, $offset, json_encode($source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }
}
