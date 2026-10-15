<?php
declare(strict_types=1);

namespace MyPHP\Native;

use Closure;
use MyPHP\UI\{Input, TextState};

/**
 * The window's content view: an MTKView subclass whose event methods are
 * PHP closures. Each event lands in the view's Input and asks for a frame.
 * Coordinates are in points, y down (the view is flipped).
 *
 * Keys go through interpretKeyEvents:, so the input context (input
 * methods, dead keys, the key bindings) answers with insertText:,
 * setMarkedText: and doCommandBySelector:. The view is an
 * NSTextInputClient over Input::$focus, the field that has the keyboard;
 * those methods edit it at once, since the input method reads back
 * selectedRange/markedRange right after.
 */
final class View
{
    private const TRACKING = 0x01 | 0x02 | 0x80 | 0x200; // enter/exit, moved, active always, visible rect
    private const NOT_FOUND = PHP_INT_MAX; // NSNotFound
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
            'mouseDragged:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $event) use ($point) {
                $input = self::$inputs[ObjC::addr($self)];
                [$x, $y] = $point($self, $event);
                $input->mouse($x, $y);
                $input->push(['drag', $x, $y]);
                Metal::requestFrame($self);
            }],
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
                ObjC::msg('v:p', $self, 'interpretKeyEvents:', ObjC::msg('p:p', ObjC::cls('NSArray'), 'arrayWithObject:', $event));
                Metal::requestFrame($self);
            }],
            // Edit menu roles reach the first responder: the focused field.
            'copy:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $sender) {
                $f = self::$inputs[ObjC::addr($self)]->focus;
                if ($f !== null && $f->hasSelection()) { Pasteboard::setText($f->selectedText()); }
            }],
            'cut:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $sender) {
                $f = self::$inputs[ObjC::addr($self)]->focus;
                if ($f !== null && $f->hasSelection()) { Pasteboard::setText($f->selectedText()); $f->insert(''); Metal::requestFrame($self); }
            }],
            'paste:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $sender) {
                $f = self::$inputs[ObjC::addr($self)]->focus;
                if ($f !== null) { $f->insert(str_replace(["\r\n", "\n", "\r"], ' ', Pasteboard::text())); Metal::requestFrame($self); }
            }],
            'selectAll:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $sender) {
                $f = self::$inputs[ObjC::addr($self)]->focus;
                if ($f !== null) { $f->selectAll(); Metal::requestFrame($self); }
            }],
            'validateMenuItem:' => ['B@:@', 'b(ppp)', fn($self, $cmd, $item) => true],
            // NSTextInputClient
            'hasMarkedText' => ['B@:', 'b(pp)', fn($self, $cmd) => (self::$inputs[ObjC::addr($self)]->focus?->marked ?? null) !== null],
            'markedRange' => ['{_NSRange=QQ}@:', 'n(pp)', function ($self, $cmd) {
                $f = self::$inputs[ObjC::addr($self)]->focus;
                if ($f === null || $f->marked === null) {
                    return ObjC::range(self::NOT_FOUND, 0);
                }
                return ObjC::range(TextState::utf16Index($f->text, $f->cursor), TextState::utf16Index($f->marked, strlen($f->marked)));
            }],
            'selectedRange' => ['{_NSRange=QQ}@:', 'n(pp)', function ($self, $cmd) {
                $f = self::$inputs[ObjC::addr($self)]->focus;
                if ($f === null) {
                    return ObjC::range(self::NOT_FOUND, 0);
                }
                if ($f->marked !== null) {
                    return ObjC::range(TextState::utf16Index($f->display(), $f->displayCaret()), 0);
                }
                [$a, $b] = $f->selection();
                $ua = TextState::utf16Index($f->text, $a);
                return ObjC::range($ua, TextState::utf16Index($f->text, $b) - $ua);
            }],
            'setMarkedText:selectedRange:replacementRange:' => ['v@:@{_NSRange=QQ}{_NSRange=QQ}', 'v(pppnn)', function ($self, $cmd, $text, $selected, $replacement) {
                $f = self::$inputs[ObjC::addr($self)]->focus;
                if ($f === null) {
                    return;
                }
                $s = self::plainString($text);
                $f->setMarked($s, TextState::byteIndex($s, (int)$selected->location));
                Metal::requestFrame($self);
            }],
            'unmarkText' => ['v@:', 'v(pp)', function ($self, $cmd) {
                $f = self::$inputs[ObjC::addr($self)]->focus;
                if ($f !== null && $f->marked !== null) { $f->insert($f->marked); Metal::requestFrame($self); }
            }],
            'insertText:replacementRange:' => ['v@:@{_NSRange=QQ}', 'v(pppn)', function ($self, $cmd, $text, $replacement) {
                self::insertText($self, $text, (int)$replacement->location, (int)$replacement->length);
            }],
            // NSResponder's one-argument form, what interpretKeyEvents: uses without an input context.
            'insertText:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $text) {
                self::insertText($self, $text, self::NOT_FOUND, 0);
            }],
            'attributedSubstringForProposedRange:actualRange:' => ['@@:{_NSRange=QQ}^{_NSRange=QQ}', 'p(ppnp)', fn($self, $cmd, $range, $actual) => null],
            'validAttributesForMarkedText' => ['@@:', 'p(pp)', fn($self, $cmd) => ObjC::msg('p:', ObjC::cls('NSArray'), 'array')],
            'firstRectForCharacterRange:actualRange:' => ['{CGRect={CGPoint=dd}{CGSize=dd}}@:{_NSRange=QQ}^{_NSRange=QQ}', 'r(ppnp)', function ($self, $cmd, $range, $actual) {
                $input = self::$inputs[ObjC::addr($self)];
                [$x, $y, $w, $h] = $input->caretRect((int)$range->location);
                $inWindow = ObjC::msg('r:rp', $self, 'convertRect:toView:', ObjC::rect($x, $y, $w, $h), null);
                return ObjC::msg('r:r', ObjC::msg('p:', $self, 'window'), 'convertRectToScreen:', $inWindow);
            }],
            'characterIndexForPoint:' => ['Q@:{CGPoint=dd}', 'L(ppq)', fn($self, $cmd, $p) => self::NOT_FOUND],
            'doCommandBySelector:' => ['v@::', 'v(ppp)', function ($self, $cmd, $selector) {
                $input = self::$inputs[ObjC::addr($self)];
                $name = ObjC::selName($selector);
                if ($input->focus === null || !$input->focus->command($name)) {
                    $input->push(['command', $name]);
                }
                Metal::requestFrame($self);
            }],
        ], ['NSTextInputClient']);
    }

    private static function insertText($self, $text, int $location, int $length): void
    {
        $input = self::$inputs[ObjC::addr($self)];
        $s = self::plainString($text);
        $f = $input->focus;
        if ($f === null) {
            $input->push(['insert', $s]); // a focused button takes Space from here
            return;
        }
        if ($location !== self::NOT_FOUND) {
            $f->anchor = TextState::byteIndex($f->text, $location);
            $f->cursor = TextState::byteIndex($f->text, $location + $length);
        }
        $f->insert($s);
        Metal::requestFrame($self);
    }

    /** The string of an NSString or NSAttributedString. */
    private static function plainString($text): string
    {
        if (ObjC::msg('b:p', $text, 'isKindOfClass:', ObjC::cls('NSAttributedString'))) {
            $text = ObjC::msg('p:', $text, 'string');
        }
        return ObjC::phpStr($text);
    }
}
