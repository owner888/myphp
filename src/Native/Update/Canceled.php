<?php
declare(strict_types=1);

namespace MyPHP\Native\Update;

/**
 * The work was stopped: its $stop said so (Go's context.Canceled).
 *
 * @internal
 */
final class Canceled extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('context canceled');
    }
}
