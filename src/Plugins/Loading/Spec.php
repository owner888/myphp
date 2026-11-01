<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Loading;

use Closure;

/**
 * What an indicator is: how long its cycle is in seconds, how wide it is
 * for its height, and how it draws (mygo's loading spec).
 *
 * @internal Loading
 */
final class Spec
{
    /** @param Closure(Drawing): void $draw */
    public function __construct(public readonly float $cycle, public readonly float $aspect, public readonly Closure $draw) {}
}
