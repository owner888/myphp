<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Game\Gamepad;

use FFI;
use MyPHP\Native\ObjC;

/**
 * A pad of GameController.framework (mygo's gamepad_darwin.go): a
 * GCController with an extended gamepad profile, which Xbox, PlayStation,
 * Switch Pro and MFi controllers have. The framework finds controllers on
 * the main run loop, which a running app turns.
 *
 * @internal
 */
final class GameController implements Device
{
    /** The selectors of the buttons' inputs, by Button; the d-pad's come from its own. */
    private const BUTTONS = [
        Button::South->value => 'buttonA', Button::East->value => 'buttonB', Button::West->value => 'buttonX', Button::North->value => 'buttonY',
        Button::LeftShoulder->value => 'leftShoulder', Button::RightShoulder->value => 'rightShoulder',
        Button::LeftTrigger->value => 'leftTrigger', Button::RightTrigger->value => 'rightTrigger',
        Button::Back->value => 'buttonOptions', Button::Start->value => 'buttonMenu', Button::Guide->value => 'buttonHome',
        Button::LeftStickButton->value => 'leftThumbstickButton', Button::RightStickButton->value => 'rightThumbstickButton',
    ];

    private static ?bool $loaded = null;
    private static ?FFI $pool = null;
    /** @var ?\Closure(mixed): bool whether a controller is still among the controllers; tests replace it */
    public static ?\Closure $present = null;

    /** @param mixed $ctl the controller, retained */
    private function __construct(public readonly mixed $ctl, private readonly string $title) {}

    /** Loads the framework once: whether there is one. */
    public static function load(): bool
    {
        if (self::$loaded === null) {
            self::$loaded = PHP_OS_FAMILY === 'Darwin';
            if (self::$loaded) {
                ObjC::boot(['Foundation', 'GameController']); // the bridge too, outside an app (a test of the farm)
                self::$pool = FFI::cdef('void* objc_autoreleasePoolPush(void); void objc_autoreleasePoolPop(void*);', '/usr/lib/libobjc.A.dylib');
            }
        }
        return self::$loaded;
    }

    /** Runs $fn in an autorelease pool, for the objects the framework returns autoreleased. @template T @param \Closure(): T $fn @return T */
    public static function withPool(\Closure $fn): mixed
    {
        $p = self::$pool->objc_autoreleasePoolPush();
        try {
            return $fn();
        } finally {
            self::$pool->objc_autoreleasePoolPop($p);
        }
    }

    /** @return list<mixed> the controllers */
    private static function controllers(): array
    {
        $list = ObjC::msg('p:', ObjC::cls('GCController'), 'controllers');
        if ($list === null) {
            return [];
        }
        $ids = [];
        for ($i = 0, $n = ObjC::msg('L:', $list, 'count'); $i < $n; $i++) {
            $ids[] = ObjC::msg('p:L', $list, 'objectAtIndex:', $i);
        }
        return $ids;
    }

    /** The devices connected, the known ones among them as they are. @param array<string, Device> $known @return list<Device> */
    public static function scan(array $known): array
    {
        if (!self::load()) {
            return [];
        }
        return self::withPool(function () use ($known): array {
            $found = [];
            foreach (self::controllers() as $c) {
                if (ObjC::msg('p:', $c, 'extendedGamepad') === null) {
                    continue; // a remote or a micro gamepad
                }
                $k = dechex(ObjC::addr($c));
                if (isset($known[$k])) {
                    $found[] = $known[$k];
                    continue;
                }
                $v = ObjC::msg('p:', $c, 'vendorName');
                $found[] = new self(ObjC::msg('p:', $c, 'retain'), $v === null ? '' : ObjC::phpStr($v));
            }
            return $found;
        });
    }

    /** A controller made for tests, of the extended gamepad profile: retained. @internal */
    public static function forTest(mixed $ctl): self
    {
        return new self(ObjC::msg('p:', $ctl, 'retain'), '');
    }

    public function key(): string
    {
        return dechex(ObjC::addr($this->ctl));
    }

    public function name(): string
    {
        return $this->title;
    }

    public function close(): void
    {
        ObjC::msg('v:', $this->ctl, 'release');
    }

    private static function value(mixed $input): float
    {
        return $input === null ? 0.0 : ObjC::msg('f:', $input, 'value');
    }

    public function read(): ?State
    {
        return self::withPool(function (): ?State {
            $present = self::$present ?? function (mixed $ctl): bool {
                foreach (self::controllers() as $c) {
                    if (ObjC::addr($c) === ObjC::addr($ctl)) {
                        return true;
                    }
                }
                return false;
            };
            if (!$present($this->ctl)) {
                return null;
            }
            $gp = ObjC::msg('p:', $this->ctl, 'extendedGamepad');
            if ($gp === null) {
                return null;
            }
            $s = new State;
            foreach (self::BUTTONS as $b => $sel) {
                // Older systems have no Home or Options button.
                if (ObjC::msg('b:p', $gp, 'respondsToSelector:', ObjC::sel($sel))) {
                    $s->buttons[$b] = self::value(ObjC::msg('p:', $gp, $sel));
                }
            }
            $pad = ObjC::msg('p:', $gp, 'dpad');
            if ($pad !== null) {
                foreach ([Button::Up->value => 'up', Button::Down->value => 'down', Button::Left->value => 'left', Button::Right->value => 'right'] as $b => $sel) {
                    $s->buttons[$b] = self::value(ObjC::msg('p:', $pad, $sel));
                }
            }
            // GameController's y axes go up.
            foreach ([[Axis::LeftX, Axis::LeftY, 'leftThumbstick'], [Axis::RightX, Axis::RightY, 'rightThumbstick']] as [$ax, $ay, $sel]) {
                $st = ObjC::msg('p:', $gp, $sel);
                if ($st !== null) {
                    $s->axes[$ax->value] = self::value(ObjC::msg('p:', $st, 'xAxis'));
                    $s->axes[$ay->value] = -self::value(ObjC::msg('p:', $st, 'yAxis'));
                }
            }
            return $s;
        });
    }
}
