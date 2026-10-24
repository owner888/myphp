<?php
declare(strict_types=1);

namespace MyPHP;

/** An argument of a message (mygo's i18n.Arg): its name and its kind, '' for {name}, or "number", "date", "time", "plural", "selectordinal" or "select". */
final class Arg
{
    public function __construct(public readonly string $name, public readonly string $kind = '') {}
}
