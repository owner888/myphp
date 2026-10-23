<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Game\Gamepad;

use Closure;

/**
 * Game controllers (mygo's plugins/game/gamepad): their buttons, triggers
 * and sticks in the standard layout of an Xbox controller, which the W3C's
 * Gamepad API and SDL's game controller database name, whatever pad it is:
 *
 *     foreach (Gamepad::pads() as $pad) {
 *         $s = $pad->state();
 *         [$x, $y] = $s->leftStick();
 *         if ($s->pressed(Button::South)) { jump(); }
 *     }
 *
 * A game reads the pads' states once a frame, or once a step of its world,
 * and keeps the last to tell a button just pressed from one held
 * (State::justPressed). The pads come from GameController.framework
 * (Xbox, PlayStation, Switch Pro and MFi controllers), whose input reaches
 * the app in front only, as games expect.
 */
final class Gamepad
{
    /** How far from the middle a stick must move to count, as a fraction of its reach: sticks rest a little off their middle. */
    public const DEADZONE = 0.15;

    /** @var list<Pad> */
    private static array $pads = [];
    private static ?float $scanned = null;
    /** How often to look for pads anew, in seconds. @internal */
    public static float $scanEach = 1.0;
    /** @var ?Closure(array<string, Device>): list<Device> finds the devices connected, the known ones among them as they are; tests replace it @internal */
    public static ?Closure $scan = null;

    /** The pads connected, in the order they connected. It looks for pads connected since at most once a second. @return list<Pad> */
    public static function pads(): array
    {
        $now = microtime(true);
        if (self::$scanned === null || $now - self::$scanned >= self::$scanEach) {
            self::$scanned = $now;
            $known = [];
            foreach (self::$pads as $p) {
                $known[$p->dev->key()] = $p->dev;
            }
            self::update((self::$scan ?? GameController::scan(...))($known));
        }
        return self::$pads;
    }

    /** Forgets the pads and when it last looked, for tests. @internal */
    public static function reset(): void
    {
        self::$pads = [];
        self::$scanned = null;
    }

    /** The pad $p is gone: it closes and leaves the pads. @internal */
    public static function disconnect(Pad $p): void
    {
        $p->connected = false;
        $p->dev->close();
        self::$pads = array_values(array_filter(self::$pads, fn(Pad $q): bool => $q !== $p));
    }

    /** Takes the devices a scan found: the pads of devices gone disconnect, and new devices connect as pads. @param list<Device> $found */
    private static function update(array $found): void
    {
        $present = [];
        foreach ($found as $d) {
            $present[$d->key()] = true;
        }
        foreach (self::$pads as $p) {
            if (!isset($present[$p->dev->key()])) {
                self::disconnect($p);
            }
        }
        $have = [];
        foreach (self::$pads as $p) {
            $have[$p->dev->key()] = true;
        }
        foreach ($found as $d) {
            if (!isset($have[$d->key()])) {
                $have[$d->key()] = true;
                self::$pads[] = new Pad($d, $d->name());
            }
        }
    }
}
