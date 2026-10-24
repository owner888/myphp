<?php
declare(strict_types=1);

namespace MyPHP;

/** How the app shows in the Dock and the app switcher (mygo's ActivationPolicy), for App::setActivationPolicy. */
enum ActivationPolicy: string
{
    /** An ordinary app, with a Dock icon and the menu bar. */
    case Regular = 'regular';
    /** No Dock icon; windows can still show: menu bar (tray) apps. */
    case Accessory = 'accessory';
    /** Hidden altogether. */
    case Prohibited = 'prohibited';

    /** @internal NSApplicationActivationPolicy */
    public function native(): int
    {
        return match ($this) { self::Regular => 0, self::Accessory => 1, self::Prohibited => 2 };
    }
}
