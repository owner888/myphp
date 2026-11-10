<?php
declare(strict_types=1);

namespace MyPHP;

use RuntimeException;

/**
 * A menu: the application's menu bar (App::setMenu) or a context menu
 * (popup). Items with a role get the system's label, accelerator and
 * action; the *Menu roles expand to the standard menus.
 */
final class Menu
{
    /** role => [the key of its label in MyPHP's strings (L10n), accelerator] (mygo's roleDefaults on macOS) */
    public const ROLES = [
        'undo' => ['menuUndo', 'CmdOrCtrl+Z'],
        'redo' => ['menuRedo', 'CmdOrCtrl+Shift+Z'],
        'cut' => ['menuCut', 'CmdOrCtrl+X'],
        'copy' => ['menuCopy', 'CmdOrCtrl+C'],
        'paste' => ['menuPaste', 'CmdOrCtrl+V'],
        'pasteAndMatchStyle' => ['menuPasteAndMatchStyle', 'Shift+Alt+CmdOrCtrl+V'],
        'delete' => ['menuDelete', ''],
        'selectAll' => ['menuSelectAll', 'CmdOrCtrl+A'],
        'reload' => ['menuReload', 'CmdOrCtrl+R'],
        'forceReload' => ['menuForceReload', 'Shift+CmdOrCtrl+R'],
        'resetZoom' => ['menuActualSize', 'CmdOrCtrl+0'],
        'zoomIn' => ['menuZoomIn', 'CmdOrCtrl+Plus'],
        'zoomOut' => ['menuZoomOut', 'CmdOrCtrl+-'],
        'togglefullscreen' => ['menuToggleFullScreen', 'Ctrl+Cmd+F'],
        'toggleDevTools' => ['menuToggleDevTools', 'Alt+Cmd+I'],
        'minimize' => ['menuMinimize', 'CmdOrCtrl+M'],
        'zoom' => ['menuZoom', ''],
        'close' => ['menuCloseWindow', 'CmdOrCtrl+W'],
        'quit' => ['menuQuitApp', 'Cmd+Q'],
        'about' => ['menuAbout', ''],
        'hide' => ['menuHide', 'Cmd+H'],
        'hideOthers' => ['menuHideOthers', 'Alt+Cmd+H'],
        'unhide' => ['menuShowAll', ''],
        'front' => ['menuBringAllToFront', ''],
        'startSpeaking' => ['menuStartSpeaking', ''],
        'stopSpeaking' => ['menuStopSpeaking', ''],
        'bold' => ['menuBold', 'CmdOrCtrl+B'],
        'italic' => ['menuItalic', 'CmdOrCtrl+I'],
        'underline' => ['menuUnderline', 'CmdOrCtrl+U'],
        'strikethrough' => ['menuStrikethrough', 'Shift+CmdOrCtrl+X'],
        'alignLeft' => ['menuAlignLeft', 'Cmd+{'],
        'alignCenter' => ['menuCenter', 'Cmd+|'],
        'alignRight' => ['menuAlignRight', 'Cmd+}'],
        'new' => ['menuNew', 'CmdOrCtrl+N'],
        'open' => ['menuOpen', 'CmdOrCtrl+O'],
        'save' => ['menuSave', 'CmdOrCtrl+S'],
        'saveAs' => ['menuSaveAs', 'Alt+Shift+Cmd+S'],
        'duplicate' => ['menuDuplicate', 'Shift+Cmd+S'],
        'rename' => ['menuRename', ''],
        'moveTo' => ['menuMoveTo', ''],
        'revert' => ['menuRevertToSaved', ''],
    ];

    /** The roles' defaults off macOS where they differ from ROLES' (mygo's roleDefaults): role => [label key, accelerator] */
    private const OTHER_ROLES = [
        'togglefullscreen' => ['menuToggleFullScreen', 'F11'],
        'toggleDevTools' => ['menuToggleDevTools', 'Ctrl+Shift+I'],
        'quit' => ['menuQuit', 'Ctrl+Q'],
        'saveAs' => ['menuSaveAs', 'Ctrl+Shift+S'],
        'duplicate' => ['menuDuplicate', ''],
        'revert' => ['menuRevert', ''],
        'alignLeft' => ['menuAlignLeft', 'Ctrl+L'],
        'alignCenter' => ['menuCenter', 'Ctrl+E'],
        'alignRight' => ['menuAlignRight', 'Ctrl+R'],
    ];

    /** The roles only macOS has, whose items are hidden elsewhere (mygo's macOnlyRoles). */
    private const MAC_ONLY_ROLES = ['about', 'hide', 'hideOthers', 'unhide', 'front', 'services', 'startSpeaking', 'stopSpeaking', 'appMenu', 'pasteAndMatchStyle', 'window', 'help'];

    /** The page's zoom steps the zoom roles go through (mygo's zoomSteps). */
    private const ZOOM_STEPS = [0.25, 0.33, 0.5, 0.67, 0.75, 0.8, 0.9, 1, 1.1, 1.25, 1.5, 1.75, 2, 2.5, 3, 4, 5];

    /** @var array<int, \WeakReference<MenuItem>> the items snapshotted, by uid, which clicks find (mygo's menuItems) */
    private static array $byUid = [];

    /** @internal the menu of a ContextMenuEvent, which shows once as it is when the listeners return: changing it re-installs no menus */
    public bool $transient = false;

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
        $this->refresh();
    }

    /** Adds $item at $pos, kept within the menu (mygo's Insert). */
    public function insert(int $pos, MenuItem $item): void
    {
        $item->parent = $this;
        $items = array_values($this->items);
        array_splice($items, min(max($pos, 0), count($items)), 0, [$item]);
        $this->items = $items;
        $this->refresh();
    }

    /**
     * Removes the items with these ids from the menu and its submenus, as
     * the web view's items of a page's context menu (mygo's Remove):
     *
     *     $e->menu->remove(ContextMenuEvent::RELOAD, ContextMenuEvent::INSPECT);
     */
    public function remove(string ...$ids): void
    {
        $this->items = self::without(array_values($this->items), $ids);
        $this->refresh();
    }

    /** @param list<MenuItem> $items @param list<string> $ids @return list<MenuItem> */
    private static function without(array $items, array $ids): array
    {
        $kept = [];
        foreach ($items as $it) {
            if ($it->id !== '' && in_array($it->id, $ids, true)) { continue; }
            $it->submenu = self::without(array_values($it->submenu), $ids);
            $kept[] = $it;
        }
        return $kept;
    }

    /** Re-installs the menus in use after the items changed, but for a context menu's, which shows once. */
    private function refresh(): void
    {
        if (!$this->transient) {
            App::refreshMenus();
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

    /**
     * The menu bar installed when the app sets none (mygo's defaultMenu):
     * on macOS the app, File, Edit, View and Window menus, which host the
     * standard shortcuts; elsewhere File and Edit for an app with
     * documents, whose File menu saves them, and none otherwise.
     */
    public static function default(): ?self
    {
        if (App::isIOS() || App::isAndroid()) {
            return null;
        }
        if (PHP_OS_FAMILY !== 'Darwin') {
            return Native\Docs\Registry::has() ? new self([new MenuItem(role: 'fileMenu'), new MenuItem(role: 'editMenu')]) : null;
        }
        return new self([
            new MenuItem(role: 'appMenu'),
            new MenuItem(role: 'fileMenu'),
            new MenuItem(role: 'editMenu'),
            new MenuItem(role: 'viewMenu'),
            new MenuItem(role: 'windowMenu'),
        ]);
    }

    /**
     * @internal the snapshot of the menu the backend builds its native menu
     * of (mygo's snapshot): roles expanded into their labels, in the
     * language the app shows, so that relabelMenus says them again, their
     * accelerators and their standard submenus.
     */
    public function snapshot(bool $rightToLeft = false): Platform\Menu
    {
        return new Platform\Menu(array_map(self::snapshotItem(...), array_values($this->items)), $rightToLeft);
    }

    /** @internal the snapshot of $it (mygo's snapshotLocked), its role expanded; without $deep, of its own state alone, as updateMenuItem takes it, its submenu left as it is */
    public static function snapshotItem(MenuItem $it, bool $deep = true): Platform\MenuItem
    {
        self::$byUid[$it->uid] = \WeakReference::create($it);
        $it->shown();
        if (count(self::$byUid) > 256 && (count(self::$byUid) & 255) === 0) {
            self::$byUid = array_filter(self::$byUid, fn(\WeakReference $w) => $w->get() !== null);
        }
        $label = $it->label; $accel = $it->accelerator;
        if ($it->role !== '') {
            [$label, $submenu] = self::expandRole($it->role, $it->label, $it->submenu);
            if ($deep) {
                $it->submenu = $submenu;
                foreach ($it->submenu as $child) { $child->parent = $it; }
            }
            if (isset(self::ROLES[$it->role])) {
                [$key, $ra] = PHP_OS_FAMILY !== 'Darwin' ? self::OTHER_ROLES[$it->role] ?? self::ROLES[$it->role] : self::ROLES[$it->role];
                $key = $it->role === 'close' && Native\Docs\Registry::has() ? 'menuClose' : $key; // a document's window closes the document
                $label = $label !== '' ? $label : L10n::in(App::locale())->get($key, ['app' => App::name()]);
                $accel = $accel !== '' ? $accel : $ra;
            }
        }
        $hidden = $it->hidden || (PHP_OS_FAMILY !== 'Darwin' && in_array($it->role, self::MAC_ONLY_ROLES, true));
        $p = new Platform\MenuItem($it->uid, $it->kind(), $label, $it->role, $accel, !$it->disabled, !$hidden, $it->checked, $it->toolTip,
            $it->type === MenuItem::SEPARATOR ? null : $it->icon, alternate: $it->alternate, opens: $it->onOpen !== null);
        if ($it->webKit !== 0 && $it->click === null) { // a web view's item does what the web view does, unless the app gave it a click of its own
            [$p->native, $p->name] = [$it->webKit, $it->id];
        }
        if ($deep && ($it->submenu !== [] || $it->onOpen !== null || in_array($it->role, ['services', 'window', 'help'], true))) {
            $p->submenu = new Platform\Menu(array_map(self::snapshotItem(...), array_values($it->submenu)));
        }
        return $p;
    }

    /** @return array{0: string, 1: MenuItem[]} label and submenu for the standard menus */
    private static function expandRole(string $role, string $label, array $submenu): array
    {
        $r = fn(string $role) => new MenuItem(role: $role);
        $t = L10n::in(App::locale());
        $mac = PHP_OS_FAMILY === 'Darwin';
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
            'fileMenu' => [$label !== '' ? $label : $t->get('menuFile'), $submenu ?: (Native\Docs\Registry::has() ? Native\Docs\Registry::fileMenu() : [$r($mac ? 'close' : 'quit')])],
            'openRecent' => [$label !== '' ? $label : $t->get('menuOpenRecent'), Native\Docs\Registry::recentItems()], // the recent documents as the menu is built
            'editMenu' => [$label !== '' ? $label : $t->get('menuEdit'), $submenu ?: (!$mac ? [
                $r('undo'), $r('redo'), MenuItem::separator(), $r('cut'), $r('copy'), $r('paste'), $r('delete'), MenuItem::separator(), $r('selectAll')] : [
                $r('undo'), $r('redo'), MenuItem::separator(), $r('cut'), $r('copy'), $r('paste'), $r('pasteAndMatchStyle'), $r('delete'), $r('selectAll'),
                MenuItem::separator(), new MenuItem($t->get('menuSpeech'), submenu: [$r('startSpeaking'), $r('stopSpeaking')])])],
            'windowMenu' => [$label !== '' ? $label : $t->get('menuWindow'), $submenu ?: ($mac ? [$r('minimize'), $r('zoom'), MenuItem::separator(), $r('front')] : [$r('minimize'), $r('close')])],
            default => [$label, $submenu],
        };
    }

    /** @internal the item of $uid a menu showed, null once it is gone (mygo's lookupMenuItem) */
    public static function lookup(int $uid): ?MenuItem
    {
        return (self::$byUid[$uid] ?? null)?->get();
    }

    /**
     * @internal the backend reports a click on the item of $uid, in the
     * window that has the keyboard (mygo's menuItemClicked): a checkbox
     * toggles, a radio item checks, then its role and its click run.
     */
    public static function clicked(int $uid, ?Window $w): void
    {
        $it = self::lookup($uid);
        if ($it === null) {
            return;
        }
        if ($it->type === MenuItem::CHECKBOX) {
            $it->setChecked(!$it->checked);
        } elseif ($it->type === MenuItem::RADIO) {
            $it->setChecked(true);
        }
        self::performRole($it->role, $w);
        if ($it->click !== null) {
            ($it->click)($it, $w);
        }
    }

    /** @internal the submenu of the item of $uid is about to show: its onOpen brings its items up to date */
    public static function willOpen(int $uid): void
    {
        $it = self::lookup($uid);
        if ($it?->onOpen !== null) {
            ($it->onOpen)($it);
        }
    }

    /**
     * Performs a role the backend leaves to PHP for the window $w (mygo's
     * performRole): the documents' roles, the page's reload and zoom, the
     * developer tools, the Format menu's on the selection of a rich text
     * editor of native UI, and the window's own where the backend reports
     * them.
     */
    private static function performRole(string $role, ?Window $w): void
    {
        if ($role === '') {
            return;
        }
        if ($role === 'quit') {
            App::quit();
            return;
        }
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
            'togglefullscreen' => $w->setFullScreen(!$w->isFullScreen()),
            'minimize' => $w->minimize(),
            'zoom' => $w->isMaximized() ? $w->unmaximize() : $w->maximize(),
            'close' => $w->close(),
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

    /** Shows the menu as a context menu with its top left at ($x, $y), points from the top-left corner of the window's content (mygo's PopupAt); right to left ($rtl), its top right, its items from the right and its submenus opening to the left. */
    public function popupAt(Window $window, float $x, float $y, bool $rtl = false): void
    {
        App::backend()->popupMenu($this->snapshot($rtl), $window, [$x, $y]);
    }

    /** Shows the menu as a context menu at the pointer over $window (mygo's Popup). */
    public function popup(Window $window): void
    {
        App::backend()->popupMenu($this->snapshot(), $window, null);
    }
}
