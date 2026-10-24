<?php
declare(strict_types=1);

namespace MyPHP;

/** The appearance the app takes (mygo's ThemeSource), for Theme::setSource. */
enum ThemeSource: string
{
    case System = 'system';
    case Light = 'light';
    case Dark = 'dark';
}
