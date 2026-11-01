<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Loading;

/** Where ripple's rings go (mygo's loading.RippleDirection). */
enum RippleDirection
{
    /** Out from the center. */
    case RippleOut;
    /** In to the center. */
    case RippleIn;
}
