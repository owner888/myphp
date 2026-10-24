<?php
declare(strict_types=1);

namespace MyPHP;

/** A display, as Screen lists it (mygo's Display). */
final class Display
{
    public function __construct(
        /** The display's CGDirectDisplayID. */
        public readonly int $id = 0,
        /** Its name, as System Settings shows it. */
        public readonly string $label = '',
        /** Its area on the screens. */
        public readonly Rectangle $bounds = new Rectangle(0, 0, 0, 0),
        /** Its area without the menu bar and the Dock. */
        public readonly Rectangle $workArea = new Rectangle(0, 0, 0, 0),
        public readonly float $scaleFactor = 0.0,
        /** Its rotation, in degrees. */
        public readonly int $rotation = 0,
        /** Whether it is the Mac's own. */
        public readonly bool $internal = false,
        /** Whether it has the menu bar. */
        public readonly bool $primary = false,
    ) {}
}
