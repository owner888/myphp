<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;
use RuntimeException;

/**
 * An icon in the menu bar, as mygo's Tray. Create it from
 * App::whenReady(). With a menu, clicking shows the menu; without one,
 * clicks reach onClick() and onRightClick().
 */
final class Tray implements Platform\TrayHandler
{
    private ?Platform\Tray $native;
    private ?Menu $menu = null;
    private array $onClick = [];
    private array $onRightClick = [];
    private array $onAppearance = [];

    public function __construct(TrayOptions $o)
    {
        if (!App::isReady()) {
            throw new RuntimeException('create trays from App::whenReady()');
        }
        $this->native = App::backend()->newTray($this);
        if ($o->id !== '') {
            $this->native->setID($o->id);
        }
        if ($o->icon !== null) {
            $this->setIcon($o->icon, $o->iconIsTemplate);
        } elseif ($o->systemIcon !== '') {
            $this->setSystemIcon($o->systemIcon);
        }
        if ($o->topTitle !== '' || $o->bottomTitle !== '') {
            $this->setDualLineTitle($o->topTitle, $o->bottomTitle);
        } elseif ($o->title !== '') {
            $this->setTitle($o->title);
        }
        if ($o->toolTip !== '') {
            $this->setToolTip($o->toolTip);
        }
        if ($o->menu !== null) {
            $this->setMenu($o->menu);
        }
    }

    /** Replaces the icon with a PNG image. */
    public function setIcon(string $png, bool $template = false): void
    {
        $this->native?->setImage($png, $template);
    }

    /** The text shown while the pointer rests on the icon (mygo's SetToolTip). */
    public function setToolTip(string $tip): void
    {
        $this->native?->setToolTip($tip);
    }

    /** The text next to the icon. */
    public function setTitle(string $title): void
    {
        $this->native?->setTitle($title);
    }

    /** Sets a system vector icon (Apple SF Symbols on macOS, e.g. 'dot.radiowaves.left.and.right'). */
    public function setSystemIcon(string $name): void
    {
        $this->native?->setSystemIcon($name);
    }

    /** Sets a compact two-line title on the status item (macOS). */
    public function setDualLineTitle(string $top, string $bottom): void
    {
        $this->native?->setDualLineTitle($top, $bottom);
    }

    /** Sets the menu a click shows; null removes it, so that clicks reach onClick (mygo's SetMenu). */
    public function setMenu(?Menu $menu): void
    {
        $this->menu = $menu;
        $this->native?->setMenu($menu?->snapshot());
    }

    /** Shows the tray's menu, as a click would (mygo's PopUpMenu): the status item places and tracks it, the icon highlighted; nothing without one. */
    public function popUpMenu(): void
    {
        if ($this->menu !== null) {
            $this->native?->popUpMenu();
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
     * Whether the menu bar the icon shows on is dark (mygo's Dark), as the
     * system tints its items: by the system's appearance, or the wallpaper
     * under a translucent bar. A template icon follows the bar by itself;
     * this is for an icon in color, whose ink goes light on a dark bar.
     */
    public function dark(): bool
    {
        return $this->native?->dark() ?? false;
    }

    /** Called when the bar the icon shows on turns dark or light (see dark()): set the icon drawn for it again (mygo's OnAppearanceChange). Returns a function that removes the listener. */
    public function onAppearanceChange(Closure $fn): Closure
    {
        $this->onAppearance[] = $fn;
        return function () use ($fn) { $this->onAppearance = array_values(array_filter($this->onAppearance, fn($f) => $f !== $fn)); };
    }

    /** @internal Window: highlights the icon while a flyout's popover shows from it, as the system's status items are while their menus show. */
    public function highlight(bool $on): void
    {
        $this->native?->highlight($on);
    }

    /** The native object a flyout's popover shows from (NSStatusBarButton). */
    public function nativeHandle()
    {
        return $this->native?->handle();
    }

    /**
     * Where the icon is in the menu bar, on the screens (mygo's Bounds):
     * macOS shows a menu bar item on each display, and this is the copy the
     * user last clicked, so a window placed against it, as a flyout, goes on
     * the display the user clicked; nothing while it shows in none.
     */
    public function bounds(): Rectangle
    {
        return $this->native?->bounds() ?? new Rectangle(0, 0, 0, 0);
    }

    /** Whether the event being handled is a press on the icon (mygo's pressed): a flyout of the icon leaves it to its click, which toggles it. */
    public function pressed(): bool
    {
        return $this->native?->pressed() ?? false;
    }

    /** Takes the icon out of the menu bar (mygo's Destroy). */
    public function destroy(): void
    {
        $this->native?->destroy();
        $this->native = null;
    }

    /** @internal Platform\TrayHandler */
    public function clicked(): void
    {
        foreach ($this->onClick as $fn) { $fn(); }
    }

    /** @internal Platform\TrayHandler */
    public function rightClicked(): void
    {
        foreach ($this->onRightClick as $fn) { $fn(); }
    }

    /** @internal Platform\TrayHandler */
    public function appearanceChanged(): void
    {
        foreach ($this->onAppearance as $fn) { $fn(); }
    }
}
