<?php
declare(strict_types=1);

namespace MyPHP\Transfer;

/** A transfer dropped (mygo's transfer.Drop): its data, read from the native session already, and the effect chosen. */
final class Drop
{
    public function __construct(public readonly Data $data, public readonly int $operation) {}
}
