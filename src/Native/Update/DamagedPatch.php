<?php
declare(strict_types=1);

namespace MyPHP\Native\Update;

/**
 * A patch or delta that does not hold together (mygo's errDamagedPatch).
 *
 * @internal
 */
final class DamagedPatch extends \RuntimeException
{
    public function __construct(string $message = 'damaged patch')
    {
        parent::__construct($message);
    }
}
