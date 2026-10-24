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
    private const RIGHT_MOUSE_UP = 4; // NSEventType
    private static bool $defined = false;
    /** @var array<int, Tray> by status bar button address */
    private static array $byButton = [];
    private $item;
    private $menu = null; // the built NSMenu, kept alive
    private array $onClick = [];
    private array $onRightClick = [];

    public function __construct(TrayOptions $o)
    {
        if (!App::isReady()) {
            throw new RuntimeException('create trays from App::whenReady()');
        }
        $bar = ObjC::msg('p:', ObjC::cls('NSStatusBar'), 'systemStatusBar');
        $this->item = ObjC::msg('p:d', $bar, 'statusItemWithLength:', self::VARIABLE_LENGTH);
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
                $right = $event !== null && ObjC::msg('L:', $event, 'type') === self::RIGHT_MOUSE_UP;
                foreach ($right ? $tray->onRightClick : $tray->onClick as $fn) {
                    $fn();
                }
            }],
        ]);
        self::$target = ObjC::msg('p:', ObjC::alloc('MyPHPTrayTarget'), 'init');
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

    public function onRightClick(Closure $fn): Closure
    {
        $this->onRightClick[] = $fn;
        return function () use ($fn) { $this->onRightClick = array_values(array_filter($this->onRightClick, fn($f) => $f !== $fn)); };
    }

    /** The status item's button (NSStatusBarButton), which a flyout's popover shows from. */
    public function nativeHandle()
    {
        return $this->button();
    }

    /** Where the icon is in the menu bar, on the screens (mygo's Bounds): nothing while it shows in none. */
    public function bounds(): Rectangle
    {
        $win = ObjC::msg('p:', $this->button(), 'window');
        return $win === null ? new Rectangle(0, 0, 0, 0) : Screens::fromMac(ObjC::msg('r:', $win, 'frame'));
    }

    /** Whether the event being handled is a press on the icon (mygo's pressed): a flyout of the icon leaves it to its click, which toggles it. */
    public function pressed(): bool
    {
        $ev = ObjC::msg('p:', ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication'), 'currentEvent');
        if ($ev === null || !in_array(ObjC::msg('L:', $ev, 'type'), [1, 3], true)) { // NSEventTypeLeftMouseDown, RightMouseDown
            return false;
        }
        $button = $this->button();
        $win = ObjC::msg('p:', $button, 'window');
        $evWin = ObjC::msg('p:', $ev, 'window');
        if ($win === null || $evWin === null || ObjC::addr($evWin) !== ObjC::addr($win)) {
            return false;
        }
        $p = ObjC::msg('q:qp', $button, 'convertPoint:fromView:', ObjC::msg('q:', $ev, 'locationInWindow'), null);
        $b = ObjC::msg('r:', $button, 'bounds');
        return $p->x >= $b->x && $p->y >= $b->y && $p->x < $b->x + $b->w && $p->y < $b->y + $b->h;
    }

    /** Takes the icon out of the menu bar (mygo's Destroy). */
    public function destroy(): void
    {
        if ($this->item === null) {
            return;
        }
        unset(self::$byButton[ObjC::addr($this->button())]);
        ObjC::msg('v:p', ObjC::msg('p:', ObjC::cls('NSStatusBar'), 'systemStatusBar'), 'removeStatusItem:', $this->item);
        $this->item = null;
    }
}
