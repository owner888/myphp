<?php
declare(strict_types=1);

namespace MyPHP\Native;

use Closure;
use MyPHP\UI\{TextInputRange, Input, Key, TextState};

/**
 * The window's content view: an MTKView subclass whose event methods are
 * PHP closures. Each event lands in the view's Input and asks for a frame.
 * Coordinates are in points, y down (the view is flipped). Native drags
 * over it go to Drag; one starts from its last press.
 *
 * Keys go through interpretKeyEvents:, so the input context (input
 * methods, dead keys, the key bindings) answers with insertText:,
 * setMarkedText: and doCommandBySelector:. The view is an
 * NSTextInputClient over Input::$focus, the field that has the keyboard;
 * those methods edit it at once, since the input method reads back
 * selectedRange/markedRange right after. It contains the elements
 * assistive technology sees of its frames (Access).
 */
final class View
{
    private const TRACKING = 0x01 | 0x02 | 0x80 | 0x200; // enter/exit, moved, active always, visible rect
    private const NOT_FOUND = PHP_INT_MAX; // NSNotFound
    /** @var array<int, Input> by view address */
    private static array $inputs = [];
    private static bool $defined = false;
    /** @var array<int, mixed> the last left press of each view, retained, by view address: a native drag begins with it */
    private static array $lastDown = [];
    /** @var array<int, Closure(float, float): bool> by view address: whether the pointer at a point of the view is a web view's under it (Window's web views) */
    public static array $passThrough = [];

    /** Creates the view through $metal with $draw as its frame callback. */
    public static function create(Metal $metal, $frame, Input $input, Closure $draw)
    {
        self::define();
        $view = $metal->createView($frame, $draw, 'MyPHPView');
        self::$inputs[ObjC::addr($view)] = $input;
        Access::attach($view, $input); // the container of the elements assistive technology sees
        $area = ObjC::msg('p:rLpp', ObjC::alloc('NSTrackingArea'), 'initWithRect:options:owner:userInfo:',
            ObjC::rect(0, 0, 0, 0), self::TRACKING, $view, null);
        ObjC::msg('v:p', $view, 'addTrackingArea:', $area);
        return $view;
    }

    /** The last left press on $view, which a native drag starts from (Drag::start); null before one. */
    public static function lastDown($view)
    {
        return self::$lastDown[ObjC::addr($view)] ?? null;
    }

    /** Lets the last press on $view go, once it closed. */
    public static function forget($view): void
    {
        $key = ObjC::addr($view);
        unset(self::$passThrough[$key]);
        if (isset(self::$lastDown[$key])) { ObjC::msg('v:', self::$lastDown[$key], 'release'); }
        unset(self::$lastDown[$key]);
    }

    /** When $event happened, in hrtime's nanoseconds: NSEvent's timestamp counts seconds of uptime, as mach_absolute_time, which hrtime reads on macOS. A drag's velocity is timed by its events, not by the frames that drain them. */
    private static function time($event): int
    {
        return (int)round(ObjC::msg('d:', $event, 'timestamp') * 1e9);
    }

    /** @var array<int, bool> the views whose primary button is down for a Control-click */
    private static array $ctrlClick = [];
    /** @var array<int, bool> whether a view takes the click that brings its window forward, by its address: true unless set */
    private static array $firstMouse = [];

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
            $input->mods = ObjC::msg('L:', $event, 'modifierFlags') & Key::MODIFIERS;
            if ($kind === 'move') {
                $input->push(['move', $x, $y]); // to a node taking its input as it comes, under the pointer
            } else {
                $button = ObjC::msg('l:', $event, 'buttonNumber');
                $mods = ObjC::msg('L:', $event, 'modifierFlags');
                if ($button === 0 && $kind === 'down') {
                    self::$ctrlClick[ObjC::addr($self)] = ($mods & Key::CONTROL) !== 0; // a Control-click is a secondary click, until its release (mygo's ctrlClick)
                }
                $secondary = $button === 0 && (self::$ctrlClick[ObjC::addr($self)] ?? false);
                if ($kind === 'up' && $button === 0) {
                    unset(self::$ctrlClick[ObjC::addr($self)]);
                }
                $input->push([$kind, $x, $y, $secondary ? 1 : $button, ObjC::msg('l:', $event, 'clickCount'), $mods, self::time($event)]);
            }
            Metal::requestFrame($self);
        };
        ObjC::defineClass('MyPHPView', 'MTKView', [
            'isFlipped' => ['B@:', 'b(pp)', fn($self, $cmd) => true],
            // Where a web view under the content takes the pointer, the view is not hit: AppKit looks under it, at the web view (mygo's surface hitTest:).
            'hitTest:' => ['@@:{CGPoint=dd}', 'p(ppq)', function ($self, $cmd, $point) {
                $pass = self::$passThrough[ObjC::addr($self)] ?? null;
                if ($pass !== null) {
                    $p = ObjC::msg('q:qp', $self, 'convertPoint:fromView:', $point, ObjC::msg('p:', $self, 'superview'));
                    if ($pass($p->x, $p->y)) {
                        return null;
                    }
                }
                return ObjC::msgSuper('p:q', $self, 'MTKView', 'hitTest:', $point);
            }],
            'acceptsFirstResponder' => ['B@:', 'b(pp)', fn($self, $cmd) => true],
            'acceptsFirstMouse:' => ['B@:@', 'b(ppp)', fn($self, $cmd, $event) => self::$firstMouse[ObjC::addr($self)] ?? true], // a click on the window behind clicks too, unless its content says not (setAcceptsFirstMouse)
            // A press in the title bar region of a hidden title bar reaches the view, not AppKit's window move and double-click zoom: Node::dragWindow handles do those.
            'mouseDownCanMoveWindow' => ['B@:', 'b(pp)', fn($self, $cmd) => false],
            // The layer's changes are not animated, as layers do by default (mygo's fit): a resize would show the last frame stretched to the new size, catching up over the animation.
            'actionForLayer:forKey:' => ['@@:@@', 'p(pppp)', fn($self, $cmd, $layer, $key) => ObjC::msg('p:', ObjC::cls('NSNull'), 'null')],
            'mouseDown:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $event) use ($mouse) {
                $key = ObjC::addr($self);
                if (isset(self::$lastDown[$key])) { ObjC::msg('v:', self::$lastDown[$key], 'release'); }
                self::$lastDown[$key] = ObjC::msg('p:', $event, 'retain');
                $mouse('down')($self, $cmd, $event);
            }],
            'mouseUp:' => ['v@:@', 'v(ppp)', $mouse('up')],
            'rightMouseDown:' => ['v@:@', 'v(ppp)', $mouse('down')],
            'rightMouseUp:' => ['v@:@', 'v(ppp)', $mouse('up')],
            'mouseMoved:' => ['v@:@', 'v(ppp)', $mouse('move')],
            // A mouse's back and forward buttons (3 and 4) are keys, as mygo's KeyBack and KeyForward: a router goes back and forward on them.
            // The middle button (2) presses and clicks as the others do.
            'otherMouseUp:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $event) use ($mouse) {
                if (ObjC::msg('l:', $event, 'buttonNumber') === 2) { $mouse('up')($self, $cmd, $event); }
            }],
            // A trackpad's pinch (its magnification since the last, and the gesture's phase) and a Force Touch's press (its stage), as they come.
            'magnifyWithEvent:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $event) use ($point) {
                [$x, $y] = $point($self, $event);
                self::$inputs[ObjC::addr($self)]->push(['magnify', $x, $y, ObjC::msg('d:', $event, 'magnification'), ObjC::msg('L:', $event, 'phase')]);
                Metal::requestFrame($self);
            }],
            'pressureChangeWithEvent:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $event) use ($point) {
                [$x, $y] = $point($self, $event);
                self::$inputs[ObjC::addr($self)]->push(['pressure', $x, $y, ObjC::msg('l:', $event, 'stage')]);
                Metal::requestFrame($self);
            }],
            'otherMouseDown:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $event) use ($mouse) {
                if (ObjC::msg('l:', $event, 'buttonNumber') === 2) { $mouse('down')($self, $cmd, $event); return; }
                $key = match (ObjC::msg('l:', $event, 'buttonNumber')) { 3 => Key::BACK, 4 => Key::FORWARD, default => null };
                if ($key !== null) {
                    self::$inputs[ObjC::addr($self)]->push(['key', '', $key, ObjC::msg('L:', $event, 'modifierFlags')]);
                    Metal::requestFrame($self);
                }
            }],
            'mouseDragged:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $event) use ($point) {
                $input = self::$inputs[ObjC::addr($self)];
                [$x, $y] = $point($self, $event);
                $input->mouse($x, $y);
                $input->mods = ObjC::msg('L:', $event, 'modifierFlags') & Key::MODIFIERS;
                $input->push(['drag', $x, $y, self::time($event)]);
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
                $input->mods = ObjC::msg('L:', $event, 'modifierFlags') & Key::MODIFIERS;
                $input->push(['scroll', $x, $y, $dx, $dy, ObjC::msg('b:', $event, 'hasPreciseScrollingDeltas')]);
                Metal::requestFrame($self);
            }],
            'keyDown:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $event) {
                $input = self::$inputs[ObjC::addr($self)];
                $chars = ObjC::phpStr(ObjC::msg('p:', $event, 'characters')); $code = ObjC::msg('S:', $event, 'keyCode'); $mods = ObjC::msg('L:', $event, 'modifierFlags');
                if ($input->textKey($code, $chars, $mods)) { // the app took the key: the field and the view see nothing of it
                    Metal::requestFrame($self);
                    return;
                }
                $input->mods = $mods & Key::MODIFIERS;
                $plain = ObjC::msg('p:', $event, 'charactersIgnoringModifiers');
                if ($input->push(['key', $chars, $code, $mods, ObjC::msg('b:', $event, 'isARepeat'), $plain === null ? '' : ObjC::phpStr($plain)])) { // a node taking its input as it comes took the key: the text system sees nothing of it
                } elseif ($input->client !== null && !$input->inputMethod) { // input methods off: the key types the characters of the layout
                    if ($chars !== '' && ord($chars[0]) >= 0x20 && ord($chars[0]) !== 0x7f && preg_match('/[\x{F700}-\x{F8FF}]/u', $chars) !== 1) { $input->client->replaceText(null, $chars); }
                } else {
                    ObjC::msg('v:p', $self, 'interpretKeyEvents:', ObjC::msg('p:p', ObjC::cls('NSArray'), 'arrayWithObject:', $event));
                }
                Metal::requestFrame($self);
            }],
            // The release of a key, for a node taking its input as it comes (mygo's KeyReleased).
            'keyUp:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $event) {
                $input = self::$inputs[ObjC::addr($self)];
                $mods = ObjC::msg('L:', $event, 'modifierFlags');
                $input->mods = $mods & Key::MODIFIERS;
                if ($input->push(['keyup', ObjC::phpStr(ObjC::msg('p:', $event, 'characters')), ObjC::msg('S:', $event, 'keyCode'), $mods])) {
                    Metal::requestFrame($self);
                }
            }],
            // The modifiers held, as Context::modifiers() reports them with the pointer still (mygo's ModifiersChanged).
            'flagsChanged:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $event) {
                self::$inputs[ObjC::addr($self)]->mods = ObjC::msg('L:', $event, 'modifierFlags') & Key::MODIFIERS;
                Metal::requestFrame($self);
            }],
            // Edit menu roles reach the first responder: as commands, the node taking its input, else the focused field or the selectable text (mygo's SurfaceCommand).
            'copy:' => ['v@:@', 'v(ppp)', fn($self, $cmd, $sender) => self::command($self, 'copy')],
            'cut:' => ['v@:@', 'v(ppp)', fn($self, $cmd, $sender) => self::command($self, 'cut')],
            'paste:' => ['v@:@', 'v(ppp)', fn($self, $cmd, $sender) => self::command($self, 'paste')],
            'undo:' => ['v@:@', 'v(ppp)', fn($self, $cmd, $sender) => self::command($self, 'undo')],
            'redo:' => ['v@:@', 'v(ppp)', fn($self, $cmd, $sender) => self::command($self, 'redo')],
            'delete:' => ['v@:@', 'v(ppp)', fn($self, $cmd, $sender) => self::command($self, 'delete')],
            // A frame some time from now (caret blink): see wake().
            'wake:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $sender) { Metal::requestFrame($self); }],
            'selectAll:' => ['v@:@', 'v(ppp)', fn($self, $cmd, $sender) => self::command($self, 'selectAll')],
            'validateMenuItem:' => ['B@:@', 'b(ppp)', fn($self, $cmd, $item) => true],
            // NSTextInputClient
            'hasMarkedText' => ['B@:', 'b(pp)', function ($self, $cmd) {
                $input = self::$inputs[ObjC::addr($self)];
                if ($input->client !== null) { return $input->client->markedRange() !== null; }
                if ($input->focus === null) { return $input->composing !== ''; } // a node taking text (Node::textCaret)
                return $input->focus->marked !== null;
            }],
            'markedRange' => ['{_NSRange=QQ}@:', 'n(pp)', function ($self, $cmd) {
                $input = self::$inputs[ObjC::addr($self)];
                if ($input->client !== null) {
                    $r = $input->client->markedRange()?->normalized();
                    return $r === null ? ObjC::range(self::NOT_FOUND, 0) : ObjC::range($r->start, $r->end - $r->start);
                }
                $f = $input->focus;
                if ($f === null && $input->composing !== '') {
                    return ObjC::range(0, TextState::utf16Index($input->composing, strlen($input->composing))); // a node taking text: the composition is all it shows the input method
                }
                if ($f === null || $f->marked === null) {
                    return ObjC::range(self::NOT_FOUND, 0);
                }
                // A password's composition is all the input method knows of it (mygo's MarkedRange).
                return ObjC::range($f->password ? 0 : TextState::utf16Index($f->text, $f->cursor), TextState::utf16Index($f->marked, strlen($f->marked)));
            }],
            'selectedRange' => ['{_NSRange=QQ}@:', 'n(pp)', function ($self, $cmd) {
                $input = self::$inputs[ObjC::addr($self)];
                if ($input->client !== null) {
                    $r = $input->client->selection()->range->normalized();
                    return ObjC::range($r->start, $r->end - $r->start);
                }
                $f = $input->focus;
                if ($f === null) {
                    return ObjC::range(self::NOT_FOUND, 0);
                }
                if ($f->marked !== null) {
                    return ObjC::range($f->password ? TextState::utf16Index($f->marked, $f->markedCaret) : TextState::utf16Index($f->display(), $f->displayCaret()), 0);
                }
                if ($f->password) {
                    return ObjC::range(0, 0); // nothing of a password
                }
                [$a, $b] = $f->selection();
                $ua = TextState::utf16Index($f->text, $a);
                return ObjC::range($ua, TextState::utf16Index($f->text, $b) - $ua);
            }],
            'setMarkedText:selectedRange:replacementRange:' => ['v@:@{_NSRange=QQ}{_NSRange=QQ}', 'v(pppnn)', function ($self, $cmd, $text, $selected, $replacement) {
                $input = self::$inputs[ObjC::addr($self)];
                $s = self::plainString($text);
                if ($input->client !== null) {
                    $input->client->setMarkedText(self::clientRange($replacement), $s, new TextInputRange((int)$selected->location, (int)$selected->location + (int)$selected->length));
                    Metal::requestFrame($self);
                    return;
                }
                $f = $input->focus;
                if ($f === null) {
                    if ($input->textCaret !== null) { // a node taking text hears the composition, its caret in characters
                        $input->composing = $s;
                        $input->push(['compose', $s, mb_strlen(substr($s, 0, TextState::byteIndex($s, (int)$selected->location)), 'UTF-8')]);
                        Metal::requestFrame($self);
                    }
                    return;
                }
                $f->setMarked($s, TextState::byteIndex($s, (int)$selected->location));
                Metal::requestFrame($self);
            }],
            'unmarkText' => ['v@:', 'v(pp)', function ($self, $cmd) {
                $input = self::$inputs[ObjC::addr($self)];
                if ($input->client !== null) { $input->client->unmarkText(); Metal::requestFrame($self); return; }
                if ($input->focus === null && $input->composing !== '') { // what a node taking text had composed is committed
                    $s = $input->composing; $input->composing = '';
                    $input->push(['insert', $s]); Metal::requestFrame($self); return;
                }
                $f = $input->focus;
                if ($f !== null && $f->marked !== null) { $f->insert($f->marked); Metal::requestFrame($self); }
            }],
            'insertText:replacementRange:' => ['v@:@{_NSRange=QQ}', 'v(pppn)', function ($self, $cmd, $text, $replacement) {
                self::insertText($self, $text, (int)$replacement->location, (int)$replacement->length);
            }],
            // NSResponder's one-argument form, what interpretKeyEvents: uses without an input context.
            'insertText:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $text) {
                self::insertText($self, $text, self::NOT_FOUND, 0);
            }],
            'attributedSubstringForProposedRange:actualRange:' => ['@@:{_NSRange=QQ}^{_NSRange=QQ}', 'p(ppnp)', function ($self, $cmd, $range, $actual) {
                $client = self::$inputs[ObjC::addr($self)]->client;
                if ($client === null) { return null; }
                [$text, $used] = $client->textForRange(new TextInputRange(min((int)$range->location, 1 << 31), min((int)$range->location + (int)$range->length, 1 << 31)));
                self::setRange($actual, $used);
                return ObjC::msg('p:p', ObjC::alloc('NSAttributedString'), 'initWithString:', ObjC::str($text));
            }],
            'validAttributesForMarkedText' => ['@@:', 'p(pp)', fn($self, $cmd) => ObjC::msg('p:', ObjC::cls('NSArray'), 'array')],
            'firstRectForCharacterRange:actualRange:' => ['{CGRect={CGPoint=dd}{CGSize=dd}}@:{_NSRange=QQ}^{_NSRange=QQ}', 'r(ppnp)', function ($self, $cmd, $range, $actual) {
                $input = self::$inputs[ObjC::addr($self)];
                if ($input->client !== null) {
                    [[$x, $y, $w, $h], $used] = $input->clientRect(new TextInputRange((int)$range->location, (int)$range->location + (int)$range->length));
                    if ($used !== null) { self::setRange($actual, $used); }
                } else {
                    [$x, $y, $w, $h] = $input->caretRect((int)$range->location);
                }
                $inWindow = ObjC::msg('r:rp', $self, 'convertRect:toView:', ObjC::rect($x, $y, $w, $h), null);
                return ObjC::msg('r:r', ObjC::msg('p:', $self, 'window'), 'convertRectToScreen:', $inWindow);
            }],
            'characterIndexForPoint:' => ['Q@:{CGPoint=dd}', 'L(ppq)', function ($self, $cmd, $p) { // a point of the screen
                $inWindow = ObjC::msg('r:r', ObjC::msg('p:', $self, 'window'), 'convertRectFromScreen:', ObjC::rect($p->x, $p->y, 0, 0));
                $at = ObjC::msg('r:rp', $self, 'convertRect:fromView:', $inWindow, null);
                return self::$inputs[ObjC::addr($self)]->clientIndex($at->x, $at->y) ?? self::NOT_FOUND;
            }],
            'doCommandBySelector:' => ['v@::', 'v(ppp)', function ($self, $cmd, $selector) {
                $input = self::$inputs[ObjC::addr($self)];
                if ($input->client !== null) { return; } // the keys go to the node taking its input, as mygo's surface leaves them
                $name = ObjC::selName($selector);
                if ($input->focus === null || !$input->focus->command($name)) {
                    $input->push(['command', $name]);
                }
                Metal::requestFrame($self);
            }],
        ] + Drag::methods() + Access::viewMethods(), ['NSTextInputClient', 'NSDraggingDestination']);
    }

    /** Asks for a frame $seconds from now; a later call replaces an earlier one. */
    /** @internal Window: whether a click on $view's window while another is in front clicks it too, or only brings the window forward (NSView's acceptsFirstMouse:). */
    public static function setAcceptsFirstMouse($view, bool $on): void
    {
        self::$firstMouse[ObjC::addr($view)] = $on;
    }

    public static function wake($view, float $seconds): void
    {
        ObjC::msg('v:ppp', ObjC::cls('NSObject'), 'cancelPreviousPerformRequestsWithTarget:selector:object:', $view, ObjC::sel('wake:'), null);
        ObjC::msg('v:ppd', $view, 'performSelector:withObject:afterDelay:', ObjC::sel('wake:'), null, $seconds);
    }

    /** A command of a menu role (Copy, Paste, Undo…, or the Format menu's for a rich text editor) to the runtime: the node taking the view's input, else the focused field or the selectable text with the focus (mygo's SurfaceCommand and contentCommand). */
    public static function command($view, string $command): void
    {
        self::$inputs[ObjC::addr($view)]->push(['edit', $command]);
        Metal::requestFrame($view);
    }

    /** A selector of the text system for the content, as Tab leaving a web view's page moves the focus on (mygo's WebViewTabOut). */
    public static function keyCommand($view, string $selector): void
    {
        self::$inputs[ObjC::addr($view)]->push(['command', $selector]);
        Metal::requestFrame($view);
    }

    /** Writes a range to an NSRange the text system passed a pointer to (an actualRange:), when it passed one. */
    private static function setRange($pointer, TextInputRange $r): void
    {
        if ($pointer === null) { return; }
        $out = ObjC::$c->cast('NSRange*', $pointer);
        $out->location = $r->start; $out->length = max(0, $r->end - $r->start);
    }

    /** A replacement range of the text input client, or null for NSNotFound: the marked range, else the selection. */
    private static function clientRange($range): ?TextInputRange
    {
        if ((int)$range->location === self::NOT_FOUND) { return null; }
        return new TextInputRange(min((int)$range->location, 1 << 31), min((int)$range->location + (int)$range->length, 1 << 31));
    }

    private static function insertText($self, $text, int $location, int $length): void
    {
        $input = self::$inputs[ObjC::addr($self)];
        $s = self::plainString($text);
        if ($input->client !== null) {
            $input->client->replaceText($location === self::NOT_FOUND ? null : new TextInputRange($location, $location + $length), $s);
            Metal::requestFrame($self);
            return;
        }
        $f = $input->focus;
        if ($f === null) {
            $input->composing = ''; // committed
            $input->push(['insert', $s]); // a focused button takes Space from here, a node taking text the text
            Metal::requestFrame($self);
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
