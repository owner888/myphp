<?php
declare(strict_types=1);

namespace MyPHP\Native;

use Closure;
use MyPHP\UI\Input;

/**
 * The window's content view: an MTKView subclass whose event methods are
 * PHP closures. Each event lands in the view's Input and asks for a frame.
 * Coordinates are in points, y down (the view is flipped).
 */
final class View
{
    private const TRACKING = 0x01 | 0x02 | 0x80 | 0x200; // enter/exit, moved, active always, visible rect
    /** @var array<int, Input> by view address */
    private static array $inputs = [];
    private static bool $defined = false;

    /** Creates the view through $metal with $draw as its frame callback. */
    public static function create(Metal $metal, $frame, Input $input, Closure $draw)
    {
        self::define();
        $view = $metal->createView($frame, $draw, true, 'MyPHPView');
        self::$inputs[ObjC::addr($view)] = $input;
        $area = ObjC::msg('p:rLpp', ObjC::alloc('NSTrackingArea'), 'initWithRect:options:owner:userInfo:',
            ObjC::rect(0, 0, 0, 0), self::TRACKING, $view, null);
        ObjC::msg('v:p', $view, 'addTrackingArea:', $area);
        return $view;
    }

    private static function define(): void
    {
        if (self::$defined) {
            return;
        }
        self::$defined = true;
        $point = function ($view, $event): array {
            $p = ObjC::msg('q:qp', $view, 'convertPoint:fromView:', ObjC::msg('q:', $event, 'locationInWindow'), null);
            return [$p->x, $p->y];
        };
        $mouse = fn(string $kind) => function ($self, $cmd, $event) use ($kind, $point) {
            $input = self::$inputs[ObjC::addr($self)];
            [$x, $y] = $point($self, $event);
            $input->mouse($x, $y);
            if ($kind !== 'move') {
                $input->push([$kind, $x, $y, ObjC::msg('l:', $event, 'buttonNumber')]);
            }
            Metal::requestFrame($self);
        };
        ObjC::defineClass('MyPHPView', 'MTKView', [
            'isFlipped' => ['B@:', 'b(pp)', fn($self, $cmd) => true],
            'acceptsFirstResponder' => ['B@:', 'b(pp)', fn($self, $cmd) => true],
            'mouseDown:' => ['v@:@', 'v(ppp)', $mouse('down')],
            'mouseUp:' => ['v@:@', 'v(ppp)', $mouse('up')],
            'rightMouseDown:' => ['v@:@', 'v(ppp)', $mouse('down')],
            'rightMouseUp:' => ['v@:@', 'v(ppp)', $mouse('up')],
            'mouseMoved:' => ['v@:@', 'v(ppp)', $mouse('move')],
            'mouseDragged:' => ['v@:@', 'v(ppp)', $mouse('move')],
            'mouseExited:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $event) {
                self::$inputs[ObjC::addr($self)]->mouseInside = false;
                Metal::requestFrame($self);
            }],
            'scrollWheel:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $event) use ($point) {
                $input = self::$inputs[ObjC::addr($self)];
                [$x, $y] = $point($self, $event);
                $dx = ObjC::msg('d:', $event, 'scrollingDeltaX');
                $dy = ObjC::msg('d:', $event, 'scrollingDeltaY');
                if (!ObjC::msg('b:', $event, 'hasPreciseScrollingDeltas')) {
                    $dx *= 10; $dy *= 10; // mouse wheel steps are in lines
                }
                $input->mouse($x, $y);
                $input->push(['scroll', $x, $y, $dx, $dy]);
                Metal::requestFrame($self);
            }],
            'keyDown:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $event) {
                $input = self::$inputs[ObjC::addr($self)];
                $input->push(['key', ObjC::phpStr(ObjC::msg('p:', $event, 'characters')),
                    ObjC::msg('S:', $event, 'keyCode'), ObjC::msg('L:', $event, 'modifierFlags')]);
                Metal::requestFrame($self);
            }],
        ]);
    }
}
