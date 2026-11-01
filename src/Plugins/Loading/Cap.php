<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Loading;

/** How a stroke ends (mygo's loading.Cap). */
enum Cap
{
    /** In a half circle. */
    case RoundCap;
    /** Square, where it ends. */
    case FlatCap;
}
