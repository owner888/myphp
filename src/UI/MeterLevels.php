<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** The levels from which a meter's value shows it is worse: the theme's warning color from $warning, its danger color from $critical; a critical below the warning makes low values the bad ones, as a battery's. Equal, the success color throughout (mygo's MeterLevels). */
final class MeterLevels
{
    public function __construct(public float $warning = 0.0, public float $critical = 0.0) {}
}
