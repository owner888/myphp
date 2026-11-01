<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Loading;

/** How an indicator that turns moves through a turn (mygo's loading.Easing). */
enum Easing
{
    /** At one speed. */
    case Linear;
    /** Starting and ending each turn slowly, as CSS's ease-in-out. */
    case EaseInOut;
    /** A turn at one speed and one easing in and out added: two turns a cycle, fast in its middle. */
    case Stacked;
}
