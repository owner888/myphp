<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;
use MyPHP\Native\{ObjC, Screens};
use RuntimeException;

/**
 * An icon in the menu bar (NSStatusItem), as mygo's Tray. Create it from
 * App::whenReady(). With a menu, clicking shows the menu; without one,
 * clicks reach onClick() and onRightClick().
 */
final class Tray
{
    private const VARIABLE_LENGTH = -1.0;
    private const LEFT_UP = 1 << 2, RIGHT_UP = 1 << 4; // NSEventMask bits
    private const LEFT_MOUSE_UP = 2, RIGHT_MOUSE_UP = 4; // NSEventType
    private const CONTROL = 1 << 18; // NSEventModifierFlagControl
    private static bool $defined = false;
    /** @var array<int, Tray> by status bar button address */
    private static array $byButton = [];
    private $item;
    private $menu = null; // the built NSMenu, kept alive
    private array $onClick = [];
    private array $onRightClick = [];
    private array $onAppearance = [];
    /** Whether the menu bar under the icon was dark, as last told (dark()). */
    private bool $wasDark = false;
    /**
     * Where on screen the copy of the item the user last clicked is, and
     * the frame the item's window had then, in Cocoa's coordinates, as
     * [x, y, w, h]: macOS 26 shows a menu bar item on each display from one
     * window, which is on one of them, or out of sight on a display whose
     * menu bar has no room for it; the copies share its size. Null until a
     * click.
     * @var ?array{0: float, 1: float, 2: float, 3: float}
     */
    private ?array $clicked = null;
    /** @var ?array{0: float, 1: float, 2: float, 3: float} */
    private ?array $of = null;

    public function __construct(TrayOptions $o)
    {
        if (!App::isReady()) {
            throw new RuntimeException('create trays from App::whenReady()');
        }
        $bar = ObjC::msg('p:', ObjC::cls('NSStatusBar'), 'systemStatusBar');
        // The status bar keeps no reference: the item leaves the menu bar when the pool that holds it drains, so the tray owns it (as mygo's retain).
        $this->item = ObjC::msg('p:', ObjC::msg('p:d', $bar, 'statusItemWithLength:', self::VARIABLE_LENGTH), 'retain');
        if ($o->id !== '') {
            ObjC::msg('v:p', $this->item, 'setAutosaveName:', ObjC::str($o->id)); // where the user put it, kept by AppKit under the name
        }
        // The title's digits at one width (mygo's 477eae5), so that a changing number (a rate, a timer) does not resize the
        // item and move the icon and those beside it: the menu bar's font at its size, set on the button rather than in
        // an attributed title, which keeps AppKit's colors for light and dark bars and the highlight.
        $size = ObjC::msg('d:', ObjC::msg('p:d', ObjC::cls('NSFont'), 'menuBarFontOfSize:', 0.0), 'pointSize');
        $font = ObjC::msg('p:dd', ObjC::cls('NSFont'), 'monospacedDigitSystemFontOfSize:weight:', $size, 0.0); // NSFontWeightRegular
        if ($font !== null) { ObjC::msg('v:p', $this->button(), 'setFont:', $font); }
        if ($o->icon !== null) {
            $this->setIcon($o->icon, $o->iconIsTemplate);
        }
        if ($o->title !== '') {
            $this->setTitle($o->title);
        }
        if ($o->toolTip !== '') {
            $this->setToolTip($o->toolTip);
        }
        self::define();
        $button = $this->button();
        self::$byButton[ObjC::addr($button)] = $this;
        ObjC::msg('v:p', $button, 'setTarget:', self::$target); // a menu, while there is one, takes the clicks instead
        ObjC::msg('v:p', $button, 'setAction:', ObjC::sel('trayClicked:'));
        ObjC::msg('l:L', $button, 'sendActionOn:', self::LEFT_UP | self::RIGHT_UP);
        ObjC::msg('v:pppp', $button, 'addObserver:forKeyPath:options:context:', self::$target, ObjC::str('effectiveAppearance'), null, null); // the bar under it turning dark or light
        $this->wasDark = $this->dark();
        if ($o->menu !== null) {
            $this->setMenu($o->menu);
        }
    }

    private static $target = null;

    private static function define(): void
    {
        if (self::$defined) {
            return;
        }
        self::$defined = true;
        ObjC::defineClass('MyPHPTrayTarget', 'NSObject', [
            'trayClicked:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $sender) {
                $tray = self::$byButton[ObjC::addr($sender)] ?? null;
                if ($tray === null) {
                    return;
                }
                $event = ObjC::msg('p:', ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication'), 'currentEvent');
                $tray->noteClick($event);
                $right = $event !== null && self::secondaryClick(ObjC::msg('L:', $event, 'type'), ObjC::msg('L:', $event, 'modifierFlags'));
                foreach ($right ? $tray->onRightClick : $tray->onClick as $fn) {
                    $fn();
                }
            }],
            // The menu bar under the button turned dark or light: with the system's appearance, or the wallpaper under a translucent bar.
            'observeValueForKeyPath:ofObject:change:context:' => ['v@:@@@^v', 'v(ppppppp)', function ($self, $cmd, $path, $object, $change, $context) {
                $tray = self::$byButton[ObjC::addr($object)] ?? null;
                if ($tray === null || ($dark = $tray->dark()) === $tray->wasDark) {
                    return;
                }
                $tray->wasDark = $dark;
                foreach ($tray->onAppearance as $fn) { $fn(); }
            }],
        ]);
        self::$target = ObjC::msg('p:', ObjC::alloc('MyPHPTrayTarget'), 'init');
    }

    /**
     * Whether a click on the icon ending with an event of type $type and
     * modifier flags $flags is a right-click (mygo's secondaryClick): the
     * right button's, or the primary button's with Control held, as a
     * Control-click is a secondary click across macOS.
     * @internal Tray's, and its test's
     */
    public static function secondaryClick(int $type, int $flags): bool
    {
        return $type === self::RIGHT_MOUSE_UP || ($type === self::LEFT_MOUSE_UP && ($flags & self::CONTROL) !== 0);
    }

    private function button()
    {
        return ObjC::msg('p:', $this->item, 'button');
    }

    /** Replaces the icon with a PNG image. */
    public function setIcon(string $png, bool $template = false): void
    {
        $data = ObjC::msg('p:sL', ObjC::cls('NSData'), 'dataWithBytes:length:', $png, strlen($png));
        $image = ObjC::msg('p:p', ObjC::alloc('NSImage'), 'initWithData:', $data);
        if ($image === null) {
            throw new RuntimeException('the tray icon is not an image');
        }
        $size = ObjC::msg('z:', $image, 'size');
        if ($size->h > 0) { // menu bar icons are 18 points tall
            $s = ObjC::$c->new('CGSize');
            [$s->w, $s->h] = [$size->w * 18 / $size->h, 18.0];
            ObjC::msg('v:z', $image, 'setSize:', $s);
        }
        ObjC::msg('v:b', $image, 'setTemplate:', $template);
        ObjC::msg('v:p', $this->button(), 'setImage:', $image);
        ObjC::msg('v:', $image, 'release');
    }

    /** The text shown while the pointer rests on the icon (mygo's SetToolTip). */
    public function setToolTip(string $tip): void
    {
        ObjC::msg('v:p', $this->button(), 'setToolTip:', ObjC::str($tip));
    }

    /** The text next to the icon. */
    public function setTitle(string $title): void
    {
        ObjC::msg('v:p', $this->button(), 'setTitle:', ObjC::str($title));
    }

    /** Sets the menu a click shows; null removes it, so that clicks reach onClick (mygo's SetMenu). */
    public function setMenu(?Menu $menu): void
    {
        $old = $this->menu;
        $this->menu = $menu?->build();
        ObjC::msg('v:p', $this->item, 'setMenu:', $this->menu);
        if ($old !== null) { ObjC::msg('v:', $old, 'release'); }
    }

    /** Shows the tray's menu, as a click would (mygo's PopUpMenu): the status item places and tracks it, the icon highlighted; nothing without one. */
    public function popUpMenu(): void
    {
        if ($this->menu !== null && $this->item !== null) {
            ObjC::msg('v:p', $this->button(), 'performClick:', null);
        }
    }

    /** Called on a left click, when the tray has no menu. Returns a function that removes the listener. */
    public function onClick(Closure $fn): Closure
    {
        $this->onClick[] = $fn;
        return function () use ($fn) { $this->onClick = array_values(array_filter($this->onClick, fn($f) => $f !== $fn)); };
    }

    /** Called on a right-click, or a Control-click, when the tray has no menu. Returns a function that removes the listener. */
    public function onRightClick(Closure $fn): Closure
    {
        $this->onRightClick[] = $fn;
        return function () use ($fn) { $this->onRightClick = array_values(array_filter($this->onRightClick, fn($f) => $f !== $fn)); };
    }

    /**
     * Whether the menu bar the icon shows on is dark (mygo's Dark), as
     * AppKit tints its items: by the system's appearance, or the wallpaper
     * under a translucent bar. A template icon follows the bar by itself;
     * this is for an icon in color, whose ink goes light on a dark bar.
     */
    public function dark(): bool
    {
        if ($this->item === null) { return false; }
        $names = ObjC::msg('p:p', ObjC::msg('p:', ObjC::cls('NSMutableArray'), 'array'), 'arrayByAddingObject:', ObjC::str('NSAppearanceNameAqua'));
        $names = ObjC::msg('p:p', $names, 'arrayByAddingObject:', ObjC::str('NSAppearanceNameDarkAqua'));
        $match = ObjC::msg('p:p', ObjC::msg('p:', $this->button(), 'effectiveAppearance'), 'bestMatchFromAppearancesWithNames:', $names);
        return $match !== null && ObjC::phpStr($match) === 'NSAppearanceNameDarkAqua';
    }

    /** Called when the bar the icon shows on turns dark or light (see dark()): set the icon drawn for it again (mygo's OnAppearanceChange). Returns a function that removes the listener. */
    public function onAppearanceChange(Closure $fn): Closure
    {
        $this->onAppearance[] = $fn;
        return function () use ($fn) { $this->onAppearance = array_values(array_filter($this->onAppearance, fn($f) => $f !== $fn)); };
    }

    /** @internal Window: highlights the icon while a flyout's popover shows from it, as AppKit's status items are while their menus show. */
    public function highlight(bool $on): void
    {
        if ($this->item !== null) { ObjC::msg('v:b', $this->button(), 'highlight:', $on); }
    }

    /** The status item's button (NSStatusBarButton), which a flyout's popover shows from. */
    public function nativeHandle()
    {
        return $this->button();
    }

    /**
     * Where the icon is in the menu bar, on the screens (mygo's Bounds):
     * macOS shows a menu bar item on each display, and this is the copy the
     * user last clicked, so a window placed against it, as a flyout, goes on
     * the display the user clicked; nothing while it shows in none.
     */
    public function bounds(): Rectangle
    {
        [$x, $y, $w, $h] = $this->frame();
        return $w <= 0 ? new Rectangle(0, 0, 0, 0) : Screens::fromMac((object)['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h]);
    }

    /**
     * Keeps where on screen the copy of the item that the click $ev was on
     * is: the pointer's place, less the click's place in the item's window,
     * which the copies share. An event in another window, which an action
     * that no click sent leaves current, changes nothing.
     */
    private function noteClick($ev): void
    {
        $win = ObjC::msg('p:', $this->button(), 'window');
        $evWin = $ev === null ? null : ObjC::msg('p:', $ev, 'window');
        if ($win === null || $evWin === null || ObjC::addr($evWin) !== ObjC::addr($win)) { return; }
        $f = ObjC::msg('r:', $win, 'frame');
        $at = ObjC::msg('q:', ObjC::cls('NSEvent'), 'mouseLocation');
        $in = ObjC::msg('q:', $ev, 'locationInWindow');
        $this->clicked = self::clickedCopy([$at->x, $at->y], [$in->x, $in->y], [$f->w, $f->h]);
        $this->of = [$f->x, $f->y, $f->w, $f->h];
    }

    /**
     * @internal the frame on screen of the copy of a menu bar item of $size
     * that a click hit at $at, a point on screen, and at $in, its place in
     * the item's window (mygo's clickedCopy).
     * @param array{0: float, 1: float} $at @param array{0: float, 1: float} $in @param array{0: float, 1: float} $size
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public static function clickedCopy(array $at, array $in, array $size): array
    {
        return [$at[0] - $in[0], $at[1] - $in[1], $size[0], $size[1]];
    }

    /**
     * @internal where a menu bar item shows, given its $window's frame: the
     * copy the user last clicked, while the window keeps the frame it had
     * then, or else the window's frame. A window that moved, as the menu
     * bar was laid out again, may have moved its copies too (mygo's
     * itemFrame).
     * @param array{0: float, 1: float, 2: float, 3: float} $window
     * @param ?array{0: float, 1: float, 2: float, 3: float} $clicked
     * @param ?array{0: float, 1: float, 2: float, 3: float} $of
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public static function itemFrame(array $window, ?array $clicked, ?array $of): array
    {
        return $clicked !== null && $clicked[2] > 0 && $window == $of ? $clicked : $window;
    }

    /** Where the item shows on screen (itemFrame), in Cocoa's coordinates; nothing without a window. @return array{0: float, 1: float, 2: float, 3: float} */
    private function frame(): array
    {
        $win = ObjC::msg('p:', $this->button(), 'window');
        if ($win === null) { return [0.0, 0.0, 0.0, 0.0]; }
        $f = ObjC::msg('r:', $win, 'frame');
        return self::itemFrame([$f->x, $f->y, $f->w, $f->h], $this->clicked, $this->of);
    }

    /** Whether the event being handled is a press on the icon (mygo's pressed): a flyout of the icon leaves it to its click, which toggles it. */
    public function pressed(): bool
    {
        if ($this->item === null) { return false; }
        $button = $this->button();
        $win = ObjC::msg('p:', $button, 'window');
        if ($win === null) { return false; }
        $ev = ObjC::msg('p:', ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication'), 'currentEvent');
        $evWin = $ev === null ? null : ObjC::msg('p:', $ev, 'window');
        if ($evWin !== null && ObjC::addr($evWin) === ObjC::addr($win) && in_array(ObjC::msg('L:', $ev, 'type'), [1, 3], true)) { // NSEventTypeLeftMouseDown, RightMouseDown
            $p = ObjC::msg('q:qp', $button, 'convertPoint:fromView:', ObjC::msg('q:', $ev, 'locationInWindow'), null);
            return self::inRect($p, ObjC::msg('r:', $button, 'bounds'));
        }
        // A button down with the pointer over it, or over the copy of it the user last clicked (mygo's 5a4e4b8, b89df06): the app
        // hears of the press only after it made the app resign, or in a monitor of other apps' events, which the menu bar's are on recent macOS.
        if (ObjC::msg('L:', ObjC::cls('NSEvent'), 'pressedMouseButtons') === 0) { return false; }
        [$x, $y, $w, $h] = $this->frame();
        return self::inRect(ObjC::msg('q:', ObjC::cls('NSEvent'), 'mouseLocation'), (object)['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h]);
    }

    /** Whether point $p is in rect $r, its far edges out. */
    private static function inRect($p, $r): bool
    {
        return $p->x >= $r->x && $p->y >= $r->y && $p->x < $r->x + $r->w && $p->y < $r->y + $r->h;
    }

    /** Takes the icon out of the menu bar (mygo's Destroy). */
    public function destroy(): void
    {
        if ($this->item === null) {
            return;
        }
        ObjC::msg('v:pp', $this->button(), 'removeObserver:forKeyPath:', self::$target, ObjC::str('effectiveAppearance'));
        unset(self::$byButton[ObjC::addr($this->button())]);
        ObjC::msg('v:p', ObjC::msg('p:', ObjC::cls('NSStatusBar'), 'systemStatusBar'), 'removeStatusItem:', $this->item);
        ObjC::msg('v:', $this->item, 'release');
        $this->item = null;
    }
}
