<?php
declare(strict_types=1);

namespace MyPHP\Platform\Darwin;

use MyPHP\Native\ObjC;
use MyPHP\Platform\{Menu, MenuItem};
use MyPHP\Native\Mem;

/**
 * NSMenus built of menu snapshots (mygo's darwin menu.go). Native items are
 * tracked per owner (the menu bar, the Dock's, a tray's, a pop-up, a
 * submenu filled as it opens) so that they are updated in place and let
 * go when the owner is built again.
 */
final class Menus
{
    /** NSApp's modifier flags of key equivalents. */
    public const MOD_SHIFT = 1 << 17, MOD_CTRL = 1 << 18, MOD_ALT = 1 << 19, MOD_CMD = 1 << 20;
    /** The owner of the menu bar's items. */
    public const APP_MENU = 0;

    /** The roles AppKit performs, by the action sent down the responder chain (mygo's nativeRoleSel). */
    private const ROLE_SELECTORS = [
        'undo' => 'undo:', 'redo' => 'redo:', 'cut' => 'cut:', 'copy' => 'copy:', 'paste' => 'paste:', 'pasteAndMatchStyle' => 'pasteAsPlainText:',
        'delete' => 'delete:', 'selectAll' => 'selectAll:', 'minimize' => 'performMiniaturize:', 'zoom' => 'performZoom:', 'close' => 'performClose:',
        'front' => 'arrangeInFront:', 'togglefullscreen' => 'toggleFullScreen:', 'about' => 'orderFrontStandardAboutPanel:', 'hide' => 'hide:',
        'hideOthers' => 'hideOtherApplications:', 'unhide' => 'unhideAllApplications:', 'quit' => 'terminate:', 'startSpeaking' => 'startSpeaking:',
        'stopSpeaking' => 'stopSpeaking:',
    ];
    /** The roles of the editing commands, which native UI answers whether it would take (UI\Runtime::commandEnabled), and the speech roles, which it never takes: a menu holding one has AppKit ask the first responder (mygo's validatesItem). */
    private const VALIDATED = ['undo', 'redo', 'cut', 'copy', 'paste', 'pasteAndMatchStyle', 'delete', 'selectAll', 'startSpeaking', 'stopSpeaking'];
    private const KEYS = [
        'enter' => "\r", 'return' => "\r", 'tab' => "\t", 'space' => ' ', 'escape' => "\e", 'esc' => "\e",
        'backspace' => "\x7f", 'delete' => "\u{F728}", 'up' => "\u{F700}", 'down' => "\u{F701}",
        'left' => "\u{F702}", 'right' => "\u{F703}", 'home' => "\u{F729}", 'end' => "\u{F72B}",
        'pageup' => "\u{F72C}", 'pagedown' => "\u{F72D}", 'plus' => '+', 'insert' => "\u{F727}", 'printscreen' => "\u{F72E}",
    ];

    private mixed $target = null;
    private mixed $delegate = null;
    private int $owners = self::APP_MENU;
    /** @var array<int, list<array{0: int, 1: mixed}>> the native items of each item's id: their owner, the item, retained */
    private array $items = [];
    /** @var array<int, int> the owner each owner was made in, which goes with it */
    private array $parents = [];
    /** @var array<int, array{0: int, 1: int}> the submenus calling menuWillOpen as they open, by the NSMenu's address: the item's id, the owner of their items */
    private array $opening = [];
    /** The Dock's menu, owned, which applicationDockMenu: hands AppKit, and the owner of its items. */
    public mixed $dockMenu = null;
    private ?int $dockOwner = null;

    public function __construct(private readonly Backend $b) {}

    /** A new owner of items, gone with $parent's when given. */
    public function newOwner(?int $parent = null): int
    {
        $o = ++$this->owners;
        if ($parent !== null) { $this->parents[$o] = $parent; }
        return $o;
    }

    /** Lets go of the native items of $owner, and of the owners made in it. */
    public function dropOwner(int $owner): void
    {
        foreach ($this->parents as $child => $parent) {
            if ($parent === $owner) {
                unset($this->parents[$child]);
                $this->dropOwner($child);
            }
        }
        foreach ($this->opening as $addr => [, $o]) {
            if ($o === $owner) { unset($this->opening[$addr]); }
        }
        foreach ($this->items as $id => $list) {
            $kept = [];
            foreach ($list as $n) {
                if ($n[0] === $owner) {
                    ObjC::msg('v:', $n[1], 'release');
                } else {
                    $kept[] = $n;
                }
            }
            if ($kept === []) { unset($this->items[$id]); } else { $this->items[$id] = $kept; }
        }
    }

    public function setApplicationMenu(?Menu $m): void
    {
        $this->dropOwner(self::APP_MENU);
        $app = ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication');
        if ($m === null) {
            ObjC::msg('v:p', $app, 'setMainMenu:', null);
            return;
        }
        $menu = $this->build($m, '', self::APP_MENU);
        ObjC::msg('v:p', $app, 'setMainMenu:', $menu);
        ObjC::msg('v:', $menu, 'release');
    }

    public function setDockMenu(?Menu $m): void
    {
        if ($this->dockMenu !== null) {
            ObjC::msg('v:', $this->dockMenu, 'release');
            $this->dockMenu = null;
        }
        if ($this->dockOwner !== null) { $this->dropOwner($this->dockOwner); }
        $this->dockOwner = $this->newOwner();
        if ($m !== null) {
            $this->dockMenu = $this->build($m, '', $this->dockOwner);
        }
    }

    /** Shows $m as a context menu over the content of $window (NSWindow), at $at points from its top-left corner, else at the pointer; it returns once the menu closes. @param ?array{0: float, 1: float} $at */
    public function popup(Menu $m, mixed $window, ?array $at): void
    {
        $owner = $this->newOwner();
        $native = $this->build($m, '', $owner);
        try {
            $view = $window === null ? null : ObjC::msg('p:', $window, 'contentView');
            if ($view === null) {
                $p = ObjC::msg('q:', ObjC::cls('NSEvent'), 'mouseLocation');
            } elseif ($at !== null) {
                $p = ObjC::$c->new('NSPoint');
                $p->x = $at[0]; $p->y = ObjC::msg('r:', $view, 'bounds')->h - $at[1]; // the content view is not flipped: y runs up from its bottom
            } else {
                $p = ObjC::msg('q:qp', $view, 'convertPoint:fromView:', ObjC::msg('q:', $window, 'mouseLocationOutsideOfEventStream'), null);
            }
            $was = null;
            if ($m->rightToLeft) { // NSUserInterfaceLayoutDirectionRightToLeft: the items from the right, submenus to the left; a menu popped up in a view takes the view's direction, which it has only while the menu shows (mygo's popupMenu)
                ObjC::msg('v:l', $native, 'setUserInterfaceLayoutDirection:', 1);
                if ($view !== null) {
                    $was = ObjC::msg('l:', $view, 'userInterfaceLayoutDirection');
                    ObjC::msg('v:l', $view, 'setUserInterfaceLayoutDirection:', 1);
                }
            }
            ObjC::msg('b:pqp', $native, 'popUpMenuPositioningItem:atLocation:inView:', null, $p, $view);
            if ($was !== null) { ObjC::msg('v:l', $view, 'setUserInterfaceLayoutDirection:', $was); }
        } finally {
            $this->dropOwner($owner);
            ObjC::msg('v:', $native, 'release');
        }
    }

    /** The NSMenu of $m titled $title, owned by the caller, its items $owner's. */
    public function build(Menu $m, string $title, int $owner): mixed
    {
        $menu = ObjC::msg('p:p', ObjC::alloc('NSMenu'), 'initWithTitle:', ObjC::str($title));
        $this->fill($menu, $m->items, $owner);
        return $menu;
    }

    /** Adds the NSMenuItems of $items to $menu. @param list<MenuItem> $items */
    private function fill(mixed $menu, array $items, int $owner): void
    {
        // A menu of an editing role's item enables its items as the first responder can act on them, the app's items as the app set them (the target's validateMenuItem:); the others keep theirs.
        ObjC::msg('v:b', $menu, 'setAutoenablesItems:', array_filter($items, fn(MenuItem $it): bool => in_array($it->role, self::VALIDATED, true)) !== []);
        foreach ($items as $it) {
            if ($it->type === MenuItem::HEADER && !ObjC::msg('b:p', ObjC::cls('NSMenuItem'), 'respondsToSelector:', ObjC::sel('sectionHeaderWithTitle:'))) {
                continue; // macOS 14's; before it menus have no headers, and the item is left out, as IINA leaves it
            }
            ObjC::msg('v:p', $menu, 'addItem:', $this->item($it, $owner));
            if ($it->role === 'zoomIn') { // Cmd+= too, without Shift, as other Mac apps take it: a hidden twin
                $twin = $this->item(new MenuItem($it->id, MenuItem::NORMAL, $it->label, $it->role, 'CmdOrCtrl+=', $it->enabled), $owner);
                ObjC::msg('v:b', $twin, 'setHidden:', true);
                if (ObjC::msg('b:p', $twin, 'respondsToSelector:', ObjC::sel('setAllowsKeyEquivalentWhenHidden:'))) {
                    ObjC::msg('v:b', $twin, 'setAllowsKeyEquivalentWhenHidden:', true);
                }
                ObjC::msg('v:p', $menu, 'addItem:', $twin);
            }
        }
    }

    /** The NSMenuItem of $it, tracked as $owner's (mygo's buildItem). */
    public function item(MenuItem $it, int $owner): mixed
    {
        if ($it->type === MenuItem::SEPARATOR || $it->type === MenuItem::HEADER) {
            $ns = $it->type === MenuItem::SEPARATOR ? ObjC::msg('p:', ObjC::cls('NSMenuItem'), 'separatorItem')
                : ObjC::msg('p:p', ObjC::cls('NSMenuItem'), 'sectionHeaderWithTitle:', ObjC::str($it->label));
            ObjC::msg('v:b', $ns, 'setHidden:', !$it->visible);
            $this->track($owner, $it->id, ObjC::msg('p:', $ns, 'retain'));
            return $ns;
        }
        $this->target ??= $this->defineTarget();
        $selector = self::ROLE_SELECTORS[$it->role] ?? null;
        $action = $it->submenu !== null ? null : ObjC::sel($selector ?? 'menuClicked:');
        [$key, $mask] = self::keyEquivalent($it->accelerator);
        $ns = ObjC::msg('p:ppp', ObjC::alloc('NSMenuItem'), 'initWithTitle:action:keyEquivalent:', ObjC::str($it->label), $action, ObjC::str($key));
        if ($key !== '') { // also a mask of 0 (F5 alone): NSMenuItem's default is Cmd
            ObjC::msg('v:L', $ns, 'setKeyEquivalentModifierMask:', $mask);
        } elseif ($it->alternate) { // Option shows it, of no key
            ObjC::msg('v:L', $ns, 'setKeyEquivalentModifierMask:', self::MOD_ALT);
        }
        if ($it->alternate) {
            ObjC::msg('v:b', $ns, 'setAlternate:', true);
        }
        if ($action !== null && $selector === null) {
            ObjC::msg('v:p', $ns, 'setTarget:', $this->target);
        }
        ObjC::msg('v:l', $ns, 'setTag:', $it->id);
        $this->apply($ns, $it);
        if ($it->submenu !== null) {
            $sub = $this->build($it->submenu, $it->label, $owner);
            if ($it->opens) {
                $this->delegate ??= $this->defineDelegate();
                ObjC::msg('v:p', $sub, 'setDelegate:', $this->delegate);
                $this->opening[Mem::addr($sub)] = [$it->id, $owner];
            }
            ObjC::msg('v:p', $ns, 'setSubmenu:', $sub);
            ObjC::msg('v:', $sub, 'release');
            $app = ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication');
            match ($it->role) {
                'windowMenu', 'window' => ObjC::msg('v:p', $app, 'setWindowsMenu:', $sub),
                'services' => ObjC::msg('v:p', $app, 'setServicesMenu:', $sub),
                'help' => ObjC::msg('v:p', $app, 'setHelpMenu:', $sub),
                default => null,
            };
        }
        $this->track($owner, $it->id, $ns); // the alloc's reference, released with the owner
        return $ns;
    }

    private function track(int $owner, int $id, mixed $ns): void
    {
        $this->items[$id][] = [$owner, $ns];
    }

    /**
     * Gives $ns $it's state (mygo's applyItemState): its image, enabled as
     * the app set it, a role's item sent down the responder chain, which
     * the first responder would enable in a menu that validates its items,
     * targeting the menu target while disabled, which lacks its action, so
     * that its menu disables it, and nothing again once enabled.
     */
    private function apply(mixed $ns, MenuItem $it): void
    {
        ObjC::msg('v:p', $ns, 'setImage:', $it->icon === null ? null : $this->b->iconImage($it->icon));
        ObjC::msg('v:b', $ns, 'setEnabled:', $it->enabled);
        if (isset(self::ROLE_SELECTORS[$it->role])) {
            ObjC::msg('v:p', $ns, 'setTarget:', $it->enabled ? null : $this->target);
        }
        ObjC::msg('v:b', $ns, 'setHidden:', !$it->visible);
        ObjC::msg('v:l', $ns, 'setState:', $it->checked && in_array($it->type, [MenuItem::CHECKBOX, MenuItem::RADIO], true) ? 1 : 0);
        if ($it->toolTip !== '') {
            ObjC::msg('v:p', $ns, 'setToolTip:', ObjC::str($it->toolTip));
        }
    }

    /** Gives every native item of $it's id its state (mygo's UpdateMenuItem). */
    public function update(MenuItem $it): void
    {
        [$key, $mask] = self::keyEquivalent($it->accelerator);
        foreach ($this->items[$it->id] ?? [] as [, $ns]) {
            // The hidden Cmd+= twin of Zoom In only follows the enabled state.
            if ($it->role === 'zoomIn' && ObjC::msg('b:', $ns, 'isHidden') && ObjC::msg('b:p', $ns, 'respondsToSelector:', ObjC::sel('allowsKeyEquivalentWhenHidden'))
                && ObjC::msg('b:', $ns, 'allowsKeyEquivalentWhenHidden')) {
                ObjC::msg('v:b', $ns, 'setEnabled:', $it->enabled);
                continue;
            }
            if ($it->type === MenuItem::SEPARATOR || $it->type === MenuItem::HEADER) {
                ObjC::msg('v:b', $ns, 'setHidden:', !$it->visible);
                continue;
            }
            ObjC::msg('v:p', $ns, 'setTitle:', ObjC::str($it->label));
            if (($sub = ObjC::msg('p:', $ns, 'submenu')) !== null) {
                ObjC::msg('v:p', $sub, 'setTitle:', ObjC::str($it->label));
            }
            ObjC::msg('v:p', $ns, 'setKeyEquivalent:', ObjC::str($key));
            ObjC::msg('v:L', $ns, 'setKeyEquivalentModifierMask:', $key === '' && $it->alternate ? self::MOD_ALT : $mask);
            $this->apply($ns, $it);
        }
    }

    /** Fills the submenus of the native items of $id with $m's items anew, those they had let go (myphp's, for a submenu brought up to date as it opens). */
    public function replaceSubmenu(int $id, Menu $m): void
    {
        foreach ($this->items[$id] ?? [] as [$owner, $ns]) {
            $sub = ObjC::msg('p:', $ns, 'submenu');
            if ($sub === null) {
                continue;
            }
            $addr = Mem::addr($sub);
            $was = $this->opening[$addr] ?? null;
            $prev = $this->subOwners[$addr] ?? null;
            if ($prev !== null) { $this->dropOwner($prev); }
            $this->subOwners[$addr] = $o = $this->newOwner($owner);
            ObjC::msg('v:', $sub, 'removeAllItems');
            $this->fill($sub, $m->items, $o);
            if ($was !== null) { $this->opening[$addr] = $was; }
        }
    }

    /** @var array<int, int> the owner of the items a submenu was filled with anew, by the NSMenu's address */
    private array $subOwners = [];

    /** "CmdOrCtrl+Shift+Z" as a key equivalent and a modifier mask. @return array{0: string, 1: int} */
    public static function keyEquivalent(string $accel): array
    {
        if ($accel === '') {
            return ['', 0];
        }
        $mask = 0; $key = '';
        foreach (explode('+', $accel) as $part) {
            $p = strtolower(trim($part));
            switch ($p) {
                case 'cmd': case 'command': case 'super': case 'meta': case 'cmdorctrl': case 'commandorcontrol': $mask |= self::MOD_CMD; break;
                case 'ctrl': case 'control': $mask |= self::MOD_CTRL; break;
                case 'alt': case 'option': $mask |= self::MOD_ALT; break;
                case 'shift': $mask |= self::MOD_SHIFT; break;
                default:
                    if (isset(self::KEYS[$p])) {
                        $key = self::KEYS[$p];
                    } elseif (preg_match('/^f(\d{1,2})$/', $p, $m)) {
                        $key = mb_chr(0xF704 + (int)$m[1] - 1, 'UTF-8');
                    } else {
                        $key = $p;
                    }
            }
        }
        return [$key, $mask];
    }

    private function defineTarget(): mixed
    {
        $h = $this->b->h;
        ObjC::defineClass('MyPHPMenuTarget', 'NSObject', [
            // The app's items keep the state it gave them in a menu that validates its items.
            'validateMenuItem:' => ['B@:@', 'b(ppp)', fn($self, $cmd, $item) => ObjC::msg('b:', $item, 'isEnabled')],
            'menuClicked:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $sender) use ($h) {
                $h->menuItemClicked(ObjC::msg('l:', $sender, 'tag'));
            }],
        ]);
        return ObjC::msg('p:', ObjC::alloc('MyPHPMenuTarget'), 'init');
    }

    private function defineDelegate(): mixed
    {
        $h = $this->b->h;
        ObjC::defineClass('MyPHPMenuDelegate', 'NSObject', [
            'menuWillOpen:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $menu) use ($h) {
                $o = $this->opening[Mem::addr($menu)] ?? null;
                if ($o !== null) { $h->menuWillOpen($o[0]); }
            }],
        ]);
        return ObjC::msg('p:', ObjC::alloc('MyPHPMenuDelegate'), 'init');
    }
}
