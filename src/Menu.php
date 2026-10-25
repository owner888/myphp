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

    /** role => [the key of its label in MyPHP's strings (L10n), accelerator, selector sent down the responder chain, or '' for the roles PHP does: documents' roles, which act on the document of the window that has the keyboard (Documents), and toggleDevTools, on its developer tools (Window::toggleDevTools)] */
    public const ROLES = [
        'undo' => ['menuUndo', 'CmdOrCtrl+Z', 'undo:'],
        'redo' => ['menuRedo', 'CmdOrCtrl+Shift+Z', 'redo:'],
        'cut' => ['menuCut', 'CmdOrCtrl+X', 'cut:'],
        'copy' => ['menuCopy', 'CmdOrCtrl+C', 'copy:'],
        'paste' => ['menuPaste', 'CmdOrCtrl+V', 'paste:'],
        'pasteAndMatchStyle' => ['menuPasteAndMatchStyle', 'Shift+Alt+CmdOrCtrl+V', 'pasteAsPlainText:'],
        'delete' => ['menuDelete', '', 'delete:'],
        'selectAll' => ['menuSelectAll', 'CmdOrCtrl+A', 'selectAll:'],
        'reload' => ['menuReload', 'CmdOrCtrl+R', ''],
        'forceReload' => ['menuForceReload', 'Shift+CmdOrCtrl+R', ''],
        'resetZoom' => ['menuActualSize', 'CmdOrCtrl+0', ''],
        'zoomIn' => ['menuZoomIn', 'CmdOrCtrl+Plus', ''],
        'zoomOut' => ['menuZoomOut', 'CmdOrCtrl+-', ''],
        'togglefullscreen' => ['menuToggleFullScreen', 'Ctrl+Cmd+F', 'toggleFullScreen:'],
        'toggleDevTools' => ['menuToggleDevTools', 'Alt+Cmd+I', ''],
        'minimize' => ['menuMinimize', 'CmdOrCtrl+M', 'performMiniaturize:'],
        'zoom' => ['menuZoom', '', 'performZoom:'],
        'close' => ['menuCloseWindow', 'CmdOrCtrl+W', 'performClose:'],
        'quit' => ['menuQuitApp', 'Cmd+Q', 'terminate:'],
        'about' => ['menuAbout', '', 'orderFrontStandardAboutPanel:'],
        'hide' => ['menuHide', 'Cmd+H', 'hide:'],
        'hideOthers' => ['menuHideOthers', 'Alt+Cmd+H', 'hideOtherApplications:'],
        'unhide' => ['menuShowAll', '', 'unhideAllApplications:'],
        'front' => ['menuBringAllToFront', '', 'arrangeInFront:'],
        'startSpeaking' => ['menuStartSpeaking', '', 'startSpeaking:'],
        'stopSpeaking' => ['menuStopSpeaking', '', 'stopSpeaking:'],
        'bold' => ['menuBold', 'CmdOrCtrl+B', ''],
        'italic' => ['menuItalic', 'CmdOrCtrl+I', ''],
        'underline' => ['menuUnderline', 'CmdOrCtrl+U', ''],
        'strikethrough' => ['menuStrikethrough', 'Shift+CmdOrCtrl+X', ''],
        'alignLeft' => ['menuAlignLeft', 'Cmd+{', ''],
        'alignCenter' => ['menuCenter', 'Cmd+|', ''],
        'alignRight' => ['menuAlignRight', 'Cmd+}', ''],
        'new' => ['menuNew', 'CmdOrCtrl+N', ''],
        'open' => ['menuOpen', 'CmdOrCtrl+O', ''],
        'save' => ['menuSave', 'CmdOrCtrl+S', ''],
        'saveAs' => ['menuSaveAs', 'Alt+Shift+Cmd+S', ''],
        'duplicate' => ['menuDuplicate', 'Shift+Cmd+S', ''],
        'rename' => ['menuRename', '', ''],
        'moveTo' => ['menuMoveTo', '', ''],
        'revert' => ['menuRevertToSaved', '', ''],
    ];

    private const KEYS = [
        'enter' => "\r", 'return' => "\r", 'tab' => "\t", 'space' => ' ', 'escape' => "\e", 'esc' => "\e",
        'backspace' => "\x7f", 'delete' => "\u{F728}", 'up' => "\u{F700}", 'down' => "\u{F701}",
        'left' => "\u{F702}", 'right' => "\u{F703}", 'home' => "\u{F729}", 'end' => "\u{F72B}",
        'pageup' => "\u{F72C}", 'pagedown' => "\u{F72D}", 'plus' => '+', 'insert' => "\u{F727}", 'printscreen' => "\u{F72E}",
    ];

    /** The page's zoom steps the zoom roles go through (mygo's zoomSteps). */
    private const ZOOM_STEPS = [0.25, 0.33, 0.5, 0.67, 0.75, 0.8, 0.9, 1, 1.1, 1.25, 1.5, 1.75, 2, 2.5, 3, 4, 5];

    /** @var array<int, array{0: MenuItem, 1: \Closure}> items and what their clicks do, by uid */
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
            $it->parent = $this;
        }
    }

    /** The menu's items (mygo's Items). @return list<MenuItem> */
    public function items(): array
    {
        return array_values($this->items);
    }

    /** Adds items at the end of the menu, which the menu bar and the Dock show at once (mygo's Append). */
    public function append(MenuItem ...$items): void
    {
        foreach ($items as $it) {
            $it->parent = $this;
            $this->items[] = $it;
        }
        App::refreshMenus();
    }

    /** Adds $item at $pos, kept within the menu (mygo's Insert). */
    public function insert(int $pos, MenuItem $item): void
    {
        $item->parent = $this;
        $items = array_values($this->items);
        array_splice($items, min(max($pos, 0), count($items)), 0, [$item]);
        $this->items = $items;
        App::refreshMenus();
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
            new MenuItem(role: 'viewMenu'),
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
            if ($it->role === 'zoomIn' && $it->accelerator === '') { // Cmd+= too, without Shift, as other Mac apps take it: a hidden twin
                $twin = new MenuItem($it->label, $it->click, 'CmdOrCtrl+=', 'zoomIn', disabled: $it->disabled);
                $ns = self::buildItem($twin);
                ObjC::msg('v:b', $ns, 'setHidden:', true);
                if (ObjC::msg('b:p', $ns, 'respondsToSelector:', ObjC::sel('setAllowsKeyEquivalentWhenHidden:'))) {
                    ObjC::msg('v:b', $ns, 'setAllowsKeyEquivalentWhenHidden:', true);
                }
                ObjC::msg('v:p', $this->native, 'addItem:', $ns);
            }
        }
        return $this->native;
    }

    private static function buildItem(MenuItem $it)
    {
        if ($it->type === MenuItem::SEPARATOR) {
            $it->native = ObjC::msg('p:', ObjC::cls('NSMenuItem'), 'separatorItem');
            ObjC::msg('v:b', $it->native, 'setHidden:', $it->hidden);
            return $it->native;
        }
        $label = $it->label; $accel = $it->accelerator; $selector = null; $click = $it->click;
        if ($it->role !== '') { // a role's label is in the language the app shows, so that relabelMenus can say it again
            [$label, $it->submenu] = self::expandRole($it->role, $it->label, $it->submenu);
            if (isset(self::ROLES[$it->role])) {
                [$key, $ra, $selector] = self::ROLES[$it->role];
                $key = $it->role === 'close' && Native\Docs\Registry::has() ? 'menuClose' : $key; // a document's window closes the document
                $label = $label !== '' ? $label : L10n::in(App::locale())->get($key, ['app' => App::name()]);
                $accel = $accel !== '' ? $accel : $ra;
                if ($selector === '') { // a role PHP performs, before the item's own click (mygo's performRole)
                    $role = $it->role; $own = $click;
                    [$selector, $click] = [null, function (MenuItem $m, ?Window $w) use ($role, $own): void {
                        self::performRole($role, $w);
                        if ($own !== null) { $own($m, $w); }
                    }];
                }
            }
        }
        [$key, $mask] = self::accelerator($accel);
        $sel = $selector !== null ? ObjC::sel($selector) : ($click !== null ? ObjC::sel('menuClicked:') : null);
        $ns = ObjC::msg('p:ppp', ObjC::alloc('NSMenuItem'), 'initWithTitle:action:keyEquivalent:', ObjC::str($label), $sel, ObjC::str($key));
        if ($key !== '') { // also a mask of 0 (F5 alone): NSMenuItem's default is Cmd
            ObjC::msg('v:L', $ns, 'setKeyEquivalentModifierMask:', $mask);
        }
        if ($selector === null && $click !== null) {
            $it->uid = self::$nextUid++;
            self::$byUid[$it->uid] = [$it, $click];
            ObjC::msg('v:p', $ns, 'setTarget:', self::$target);
            ObjC::msg('v:l', $ns, 'setTag:', $it->uid);
        }
        ObjC::msg('v:b', $ns, 'setEnabled:', !$it->disabled);
        ObjC::msg('v:b', $ns, 'setHidden:', $it->hidden);
        if ($it->checked && in_array($it->type, [MenuItem::CHECKBOX, MenuItem::RADIO], true)) {
            ObjC::msg('v:l', $ns, 'setState:', 1);
        }
        if ($it->toolTip !== '') {
            ObjC::msg('v:p', $ns, 'setToolTip:', ObjC::str($it->toolTip));
        }
        if ($it->submenu !== [] || in_array($it->role, ['services', 'window', 'help'], true)) {
            $sub = new self($it->submenu);
            foreach ($it->submenu as $child) { $child->parent = $it; } // the item's list, not the menu built of it
            ObjC::msg('v:p', $ns, 'setSubmenu:', $sub->build($label));
            $app = ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication');
            match ($it->role) {
                'windowMenu', 'window' => ObjC::msg('v:p', $app, 'setWindowsMenu:', $sub->native),
                'services' => ObjC::msg('v:p', $app, 'setServicesMenu:', $sub->native),
                'help' => ObjC::msg('v:p', $app, 'setHelpMenu:', $sub->native),
                default => null,
            };
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
                $r('about'), MenuItem::separator(), $r('services'), MenuItem::separator(), $r('hide'), $r('hideOthers'), $r('unhide'), MenuItem::separator(), $r('quit')]],
            'services' => [$label !== '' ? $label : $t->get('menuServices'), $submenu],
            'window' => [$label !== '' ? $label : $t->get('menuWindow'), $submenu],
            'help' => [$label !== '' ? $label : $t->get('menuHelp'), $submenu],
            'viewMenu' => [$label !== '' ? $label : $t->get('menuView'), $submenu ?: [
                $r('reload'), $r('forceReload'), $r('toggleDevTools'), MenuItem::separator(), $r('resetZoom'), $r('zoomIn'), $r('zoomOut'), MenuItem::separator(), $r('togglefullscreen')]],
            'formatMenu' => [$label !== '' ? $label : $t->get('menuFormat'), $submenu ?: [
                $r('bold'), $r('italic'), $r('underline'), $r('strikethrough'), MenuItem::separator(), $r('alignLeft'), $r('alignCenter'), $r('alignRight')]],
            'fileMenu' => [$label !== '' ? $label : $t->get('menuFile'), $submenu ?: (Native\Docs\Registry::has() ? Native\Docs\Registry::fileMenu() : [$r('close')])],
            'openRecent' => [$label !== '' ? $label : $t->get('menuOpenRecent'), Native\Docs\Registry::recentItems()], // the recent documents as the menu is built
            'editMenu' => [$label !== '' ? $label : $t->get('menuEdit'), $submenu ?: [
                $r('undo'), $r('redo'), MenuItem::separator(), $r('cut'), $r('copy'), $r('paste'), $r('pasteAndMatchStyle'), $r('delete'), $r('selectAll'),
                MenuItem::separator(), new MenuItem($t->get('menuSpeech'), submenu: [$r('startSpeaking'), $r('stopSpeaking')])]],
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

    /**
     * Performs a role PHP does for the window $w (mygo's performRole): the
     * documents' roles, the page's reload and zoom, the developer tools,
     * and the Format menu's on the selection of a rich text editor of
     * native UI, or of an editable page.
     */
    private static function performRole(string $role, ?Window $w): void
    {
        if (in_array($role, ['new', 'open', 'save', 'saveAs', 'duplicate', 'rename', 'moveTo', 'revert'], true)) {
            Native\Docs\Registry::role($role, $w);
            return;
        }
        if ($w === null) {
            return;
        }
        if ($role === 'toggleDevTools') {
            $w->toggleDevTools();
            return;
        }
        if (in_array($role, ['bold', 'italic', 'underline', 'strikethrough', 'alignLeft', 'alignCenter', 'alignRight'], true)) {
            $w->contentCommand($role);
            return;
        }
        $page = $w->page(); // a window showing native UI has no page to reload or zoom
        match ($role) {
            'reload' => $page?->reload(),
            'forceReload' => $page?->reloadIgnoringCache(),
            'resetZoom' => $page?->setZoomFactor(1.0),
            'zoomIn' => $page?->setZoomFactor(self::nextZoom($page->zoomFactor(), 1)),
            'zoomOut' => $page?->setZoomFactor(self::nextZoom($page->zoomFactor(), -1)),
            default => null,
        };
    }

    /** The zoom step after $cur, up ($dir 1) or down (mygo's nextZoom). */
    public static function nextZoom(float $cur, int $dir): float
    {
        if ($dir > 0) {
            foreach (self::ZOOM_STEPS as $s) {
                if ($s > $cur + 0.001) { return (float)$s; }
            }
            return (float)self::ZOOM_STEPS[count(self::ZOOM_STEPS) - 1];
        }
        foreach (array_reverse(self::ZOOM_STEPS) as $s) {
            if ($s < $cur - 0.001) { return (float)$s; }
        }
        return (float)self::ZOOM_STEPS[0];
    }

    private static function defineTarget()
    {
        ObjC::defineClass('MyPHPMenuTarget', 'NSObject', [
            'menuClicked:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $sender) {
                [$it, $click] = self::$byUid[ObjC::msg('l:', $sender, 'tag')] ?? [null, null];
                if ($it === null) {
                    return;
                }
                if ($it->type === MenuItem::CHECKBOX) {
                    $it->setChecked(!$it->checked);
                } elseif ($it->type === MenuItem::RADIO) {
                    $it->setChecked(true);
                }
                $click($it, Window::focused());
            }],
        ]);
        return ObjC::msg('p:', ObjC::alloc('MyPHPMenuTarget'), 'init');
    }

    /** @internal shows the menu under the node $id had last frame (a select's), in $window */
    public function popupUnder(Window $window, int $id, \MyPHP\UI\Runtime $runtime): void
    {
        $r = $runtime->rectOf($id);
        if ($r === null) {
            $this->popup($window);
            return;
        }
        $native = $this->build();
        $view = ObjC::msg('p:', $window->nativeHandle(), 'contentView');
        $p = ObjC::$c->new('NSPoint');
        $p->x = $r[0]; $p->y = ObjC::msg('r:', $view, 'bounds')->h - ($r[1] + $r[3] + 2); // the content view (the window's container) is not flipped: y runs up from its bottom
        ObjC::msg('b:pqp', $native, 'popUpMenuPositioningItem:atLocation:inView:', null, $p, $view);
    }

    /** Shows the menu as a context menu with its top left at ($x, $y), points from the top-left corner of the window's content (mygo's PopupAt); right to left ($rtl), its top right, its items from the right and its submenus opening to the left. */
    public function popupAt(Window $window, float $x, float $y, bool $rtl = false): void
    {
        $native = $this->build();
        $view = ObjC::msg('p:', $window->nativeHandle(), 'contentView');
        $p = ObjC::$c->new('NSPoint');
        $p->x = $x; $p->y = ObjC::msg('r:', $view, 'bounds')->h - $y; // the content view is not flipped: y runs up from its bottom
        $was = null;
        if ($rtl) { // NSUserInterfaceLayoutDirectionRightToLeft: the items from the right, submenus to the left; a menu popped up in a view takes the view's direction, which it has only while the menu shows (mygo's popupMenu)
            ObjC::msg('v:l', $native, 'setUserInterfaceLayoutDirection:', 1);
            $was = ObjC::msg('l:', $view, 'userInterfaceLayoutDirection');
            ObjC::msg('v:l', $view, 'setUserInterfaceLayoutDirection:', 1);
        }
        ObjC::msg('b:pqp', $native, 'popUpMenuPositioningItem:atLocation:inView:', null, $p, $view);
        if ($was !== null) { ObjC::msg('v:l', $view, 'setUserInterfaceLayoutDirection:', $was); }
    }

    /** Shows the menu as a context menu at the pointer over $window (mygo's Popup). */
    public function popup(Window $window): void
    {
        $native = $this->build();
        $view = ObjC::msg('p:', $window->nativeHandle(), 'contentView');
        $p = ObjC::msg('q:qp', $view, 'convertPoint:fromView:', ObjC::msg('q:', $window->nativeHandle(), 'mouseLocationOutsideOfEventStream'), null);
        ObjC::msg('b:pqp', $native, 'popUpMenuPositioningItem:atLocation:inView:', null, $p, $view);
    }
}
