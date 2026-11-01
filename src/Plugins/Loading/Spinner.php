<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Loading;

use Closure;

/** One of the indicators, by name, as a gallery lists them (mygo's loading.Spinner). */
final class Spinner
{
    public function __construct(
        /** The indicator's name, as loading.dev's: "Arc", "Bouncing dots". */
        public readonly string $name,
        /** @var Closure(\MyPHP\UI\Context, ?Options=): \MyPHP\UI\Node creates the indicator, as the function of its name does */
        public readonly Closure $new,
    ) {}
}
