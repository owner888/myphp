<?php
declare(strict_types=1);

namespace MyPHP;

use MyPHP\Native\ObjC;
use RuntimeException;

/**
 * A menu: the application's menu bar (App::setMenu) or a context menu
 * (popup). Items with a role get the system's label, accelerator and
 * action; the *Menu roles expand to the standard menus.
 */
final class Menu
{
    private const MOD_SHIFT = 1 << 17, MOD_CTRL = 1 << 18, MOD_ALT = 1 << 19, MOD_CMD = 1 << 20;

    /** role => [the key of its label in MyPHP's strings (L10n), accelerator, selector sent down the responder chain] */
    public const ROLES = [
        'undo' => ['menuUndo', 'CmdOrCtrl+Z', 'undo:'],
        'redo' => ['menuRedo', 'CmdOrCtrl+Shift+Z', 'redo:'],
        'cut' => ['menuCut', 'CmdOrCtrl+X', 'cut:'],
        'copy' => ['menuCopy', 'CmdOrCtrl+C', 'copy:'],
        'paste' => ['menuPaste', 'CmdOrCtrl+V', 'paste:'],
        'pasteAndMatchStyle' => ['menuPasteAndMatchStyle', 'CmdOrCtrl+Shift+V', 'pasteAsPlainText:'],
        'delete' => ['menuDelete', '', 'delete:'],
        'selectAll' => ['menuSelectAll', 'CmdOrCtrl+A', 'selectAll:'],
        'togglefullscreen' => ['menuToggleFullScreen', 'Ctrl+Cmd+F', 'toggleFullScreen:'],
        'minimize' => ['menuMinimize', 'CmdOrCtrl+M', 'performMiniaturize:'],
        'zoom' => ['menuZoom', '', 'performZoom:'],
        'close' => ['menuCloseWindow', 'CmdOrCtrl+W', 'performClose:'],
        'quit' => ['menuQuitApp', 'CmdOrCtrl+Q', 'terminate:'],
        'about' => ['menuAbout', '', 'orderFrontStandardAboutPanel:'],
        'hide' => ['menuHide', 'CmdOrCtrl+H', 'hide:'],
        'hideOthers' => ['menuHideOthers', 'Cmd+Alt+H', 'hideOtherApplications:'],
        'unhide' => ['menuShowAll', '', 'unhideAllApplications:'],
        'front' => ['menuBringAllToFront', '', 'arrangeInFront:'],
    ];

    private const KEYS = [
        'enter' => "\r", 'return' => "\r", 'tab' => "\t", 'space' => ' ', 'escape' => "\e", 'esc' => "\e",
        'backspace' => "\x7f", 'delete' => "\u{F728}", 'up' => "\u{F700}", 'down' => "\u{F701}",
        'left' => "\u{F702}", 'right' => "\u{F703}", 'home' => "\u{F729}", 'end' => "\u{F72B}",
        'pageup' => "\u{F72C}", 'pagedown' => "\u{F72D}",
    ];

    /** @var array<int, MenuItem> by uid, for clicks */
    private static array $byUid = [];
    private static int $nextUid = 1;
    private static $target = null;

    public $native = null;

    /** @param MenuItem[] $items */
    public function __construct(public array $items = [])
    {
        foreach ($this->items as $i => $it) {
            if (!$it instanceof MenuItem) {
                throw new RuntimeException("Menu: item $i is not a MenuItem");
            }
        }
    }

    public function itemById(string $id): ?MenuItem
    {
        return self::find($this->items, $id);
    }

    private static function find(array $items, string $id): ?MenuItem
    {
        foreach ($items as $it) {
            if ($it->id === $id) {
                return $it;
            }
            if ($found = self::find($it->submenu, $id)) {
                return $found;
            }
        }
        return null;
    }

    /** The standard menu bar: app, File, Edit and Window menus. */
    public static function default(): self
    {
        return new self([
            new MenuItem(role: 'appMenu'),
            new MenuItem(role: 'fileMenu'),
            new MenuItem(role: 'editMenu'),
            new MenuItem(role: 'windowMenu'),
        ]);
    }

    /** Builds the NSMenu; called by App::setMenu and popup(). */
    public function build(string $title = '')
    {
        self::$target ??= self::defineTarget();
        $this->native = ObjC::msg('p:p', ObjC::alloc('NSMenu'), 'initWithTitle:', ObjC::str($title));
        ObjC::msg('v:b', $this->native, 'setAutoenablesItems:', false);
        foreach ($this->items as $it) {
            ObjC::msg('v:p', $this->native, 'addItem:', self::buildItem($it));
        }
        return $this->native;
    }

    private static function buildItem(MenuItem $it)
    {
        if ($it->type === MenuItem::SEPARATOR) {
            return $it->native = ObjC::msg('p:', ObjC::cls('NSMenuItem'), 'separatorItem');
        }
        $label = $it->label; $accel = $it->accelerator; $selector = null;
        if ($it->role !== '') { // a role's label is in the language the app shows, so that relabelMenus can say it again
            [$label, $it->submenu] = self::expandRole($it->role, $it->label, $it->submenu);
            if (isset(self::ROLES[$it->role])) {
                [$key, $ra, $selector] = self::ROLES[$it->role];
                $label = $label !== '' ? $label : L10n::in(App::locale())->get($key, ['app' => App::name()]);
                $accel = $accel !== '' ? $accel : $ra;
            }
        }
        [$key, $mask] = self::accelerator($accel);
        $sel = $selector !== null ? ObjC::sel($selector) : ($it->click !== null ? ObjC::sel('menuClicked:') : null);
        $ns = ObjC::msg('p:ppp', ObjC::alloc('NSMenuItem'), 'initWithTitle:action:keyEquivalent:', ObjC::str($label), $sel, ObjC::str($key));
        if ($mask !== 0) {
            ObjC::msg('v:L', $ns, 'setKeyEquivalentModifierMask:', $mask);
        }
        if ($selector === null && $it->click !== null) {
            $it->uid = self::$nextUid++;
            self::$byUid[$it->uid] = $it;
            ObjC::msg('v:p', $ns, 'setTarget:', self::$target);
            ObjC::msg('v:l', $ns, 'setTag:', $it->uid);
        }
        ObjC::msg('v:b', $ns, 'setEnabled:', !$it->disabled);
        if ($it->checked) {
            ObjC::msg('v:l', $ns, 'setState:', 1);
        }
        if ($it->submenu !== []) {
            $sub = new self($it->submenu);
            ObjC::msg('v:p', $ns, 'setSubmenu:', $sub->build($label));
            if ($it->role === 'windowMenu') {
                ObjC::msg('v:p', ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication'), 'setWindowsMenu:', $sub->native);
            }
        }
        return $it->native = $ns;
    }

    /** @return array{0: string, 1: MenuItem[]} label and submenu for the standard menus */
    private static function expandRole(string $role, string $label, array $submenu): array
    {
        $r = fn(string $role) => new MenuItem(role: $role);
        $t = L10n::in(App::locale());
        return match ($role) {
            'appMenu' => [$label !== '' ? $label : App::name(), $submenu ?: [
                $r('about'), MenuItem::separator(), $r('hide'), $r('hideOthers'), $r('unhide'), MenuItem::separator(), $r('quit')]],
            'fileMenu' => [$label !== '' ? $label : $t->get('menuFile'), $submenu ?: [$r('close')]],
            'editMenu' => [$label !== '' ? $label : $t->get('menuEdit'), $submenu ?: [
                $r('undo'), $r('redo'), MenuItem::separator(), $r('cut'), $r('copy'), $r('paste'), $r('pasteAndMatchStyle'), $r('delete'), $r('selectAll')]],
            'windowMenu' => [$label !== '' ? $label : $t->get('menuWindow'), $submenu ?: [$r('minimize'), $r('zoom'), MenuItem::separator(), $r('front')]],
            default => [$label, $submenu],
        };
    }

    /** "CmdOrCtrl+Shift+Z" -> key equivalent and modifier mask. */
    public static function accelerator(string $accel): array
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

    private static function defineTarget()
    {
        ObjC::defineClass('MyPHPMenuTarget', 'NSObject', [
            'menuClicked:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $sender) {
                $it = self::$byUid[ObjC::msg('l:', $sender, 'tag')] ?? null;
                if ($it === null) {
                    return;
                }
                if ($it->type === MenuItem::CHECKBOX) {
                    $it->setChecked(!$it->checked);
                }
                ($it->click)($it, Window::key());
            }],
        ]);
        return ObjC::msg('p:', ObjC::alloc('MyPHPMenuTarget'), 'init');
    }

    /** Shows the menu as a context menu at the mouse position in $window. */
    /** Shows the menu under the node $id had last frame (a select), in $window. */
    public function popupAt(Window $window, int $id, \MyPHP\UI\Runtime $runtime): void
    {
        $r = $runtime->rectOf($id);
        if ($r === null) {
            $this->popup($window);
            return;
        }
        $native = $this->build();
        $view = ObjC::msg('p:', $window->native(), 'contentView');
        $p = ObjC::$c->new('NSPoint');
        $p->x = $r[0]; $p->y = $r[1] + $r[3] + 2; // the content view is flipped: y runs down
        ObjC::msg('b:pqp', $native, 'popUpMenuPositioningItem:atLocation:inView:', null, $p, $view);
    }

    public function popup(Window $window): void
    {
        $native = $this->build();
        $view = ObjC::msg('p:', $window->native(), 'contentView');
        $p = ObjC::msg('q:qp', $view, 'convertPoint:fromView:', ObjC::msg('q:', $window->native(), 'mouseLocationOutsideOfEventStream'), null);
        ObjC::msg('b:pqp', $native, 'popUpMenuPositioningItem:atLocation:inView:', null, $p, $view);
    }
}
