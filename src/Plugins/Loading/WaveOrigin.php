<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Loading;

/** Where wave's bars grow from (mygo's loading.WaveOrigin). */
enum WaveOrigin
{
    /** Up and down from their middle. */
    case WaveCenter;
    /** Up from the bottom. */
    case WaveBottom;
}
