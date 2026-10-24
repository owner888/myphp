<?php
declare(strict_types=1);

namespace MyPHP;

/** The direction a language is written in (mygo's i18n.Dir); its value is that of HTML's dir. */
enum Dir: string
{
    case LeftToRight = 'ltr';
    case RightToLeft = 'rtl';
}
