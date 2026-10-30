<?php
declare(strict_types=1);

namespace MyPHP\Native;

use MyPHP\{App, ContextMenuEvent, Menu, MenuItem};

/**
 * A page's context menu (mygo's context_menu.go and darwin
 * context_menu.go): WebKit builds its NSMenu and pops it up over the web
 * view, which AppKit tells the view as -[NSView willOpenMenu:withEvent:]
 * before the menu shows. MyPHP describes the menu there as a Menu of
 * MenuItems, lets the page's 'contextMenu' listeners change it, and
 * changes the NSMenu in place: WebKit's items, which carry what they do,
 * go back where the listeners put them, and the app's are made as those of
 * its other menus. WebKit tells nothing of what was clicked: the bridge
 * records the page's contextmenu event just before (Page::message).
 *
 * @internal Page, WebView
 */
final class ContextMenus
{
    /** MyPHP's names of the items WebKit identifies (WKMenuItemIdentifiers.h, macOS 11) that other platforms have too. */
    private const IDENTIFIERS = [
        'WKMenuItemIdentifierCopy' => 'copy', 'WKMenuItemIdentifierCopyImage' => 'copyImage', 'WKMenuItemIdentifierCopyLink' => 'copyLink',
        'WKMenuItemIdentifierCopyMediaLink' => 'copyMediaURL', 'WKMenuItemIdentifierDownloadImage' => 'downloadImage',
        'WKMenuItemIdentifierDownloadLinkedFile' => 'downloadLink', 'WKMenuItemIdentifierDownloadMedia' => 'downloadMedia',
        'WKMenuItemIdentifierGoBack' => 'back', 'WKMenuItemIdentifierGoForward' => 'forward', 'WKMenuItemIdentifierInspectElement' => 'inspect',
        'WKMenuItemIdentifierLookUp' => 'lookUp', 'WKMenuItemIdentifierOpenFrameInNewWindow' => 'openFrameInNewWindow',
        'WKMenuItemIdentifierOpenImageInNewWindow' => 'openImageInNewWindow', 'WKMenuItemIdentifierOpenLink' => 'openLink',
        'WKMenuItemIdentifierOpenLinkInNewWindow' => 'openLinkInNewWindow', 'WKMenuItemIdentifierOpenMediaInNewWindow' => 'openMediaInNewWindow',
        'WKMenuItemIdentifierPaste' => 'paste', 'WKMenuItemIdentifierReload' => 'reload', 'WKMenuItemIdentifierSearchWeb' => 'searchWeb',
        'WKMenuItemIdentifierShowHideMediaControls' => 'mediaControls', 'WKMenuItemIdentifierToggleFullScreen' => 'mediaFullScreen',
        'WKMenuItemIdentifierShareMenu' => 'share',
    ];
    /** WebKit's items without an identifier, by their tags, WebCore's ContextMenuAction values, which hold for the first of them. */
    private const TAGS = [1 => 'openLinkInNewWindow', 2 => 'downloadLink', 3 => 'copyLink', 4 => 'openImageInNewWindow', 5 => 'downloadImage',
        6 => 'copyImage', 7 => 'openFrameInNewWindow', 8 => 'copy', 9 => 'back', 10 => 'forward', 11 => 'stop', 12 => 'reload',
        13 => 'cut', 14 => 'paste', 15 => 'spellingGuess', 21 => 'searchWeb', 22 => 'lookUp'];
    /** AppKit's items by their actions, as the editing items of a text field's menu. */
    private const SELECTORS = ['cut:' => 'cut', 'copy:' => 'copy', 'paste:' => 'paste', 'pasteAsPlainText:' => 'pasteAndMatchStyle',
        'delete:' => 'delete', 'selectAll:' => 'selectAll', 'undo:' => 'undo', 'redo:' => 'redo'];

    /**
     * The name of an item of WebKit's menu: MyPHP's for the items other
     * platforms have, else WebKit's identifier, else by WebKit's tag or
     * AppKit's action; "" for an item that has none (mygo's
     * contextItemName).
     */
    public static function name(string $identifier, string $action, int $tag): string
    {
        if (isset(self::IDENTIFIERS[$identifier])) { return self::IDENTIFIERS[$identifier]; }
        if (str_starts_with($identifier, 'WKMenuItemIdentifier')) { return $identifier; }
        if ($action === 'forwardContextMenuAction:') { return self::TAGS[$tag] ?? ''; } // AppKit names an item WebKit gave no identifier by this action, which tells nothing
        return self::SELECTORS[$action] ?? '';
    }

    /**
     * Drops the separators of a menu shown that would start or end it, or
     * follow another, as the items removed between them leave (mygo's
     * TidySeparators), in its submenus too.
     * @param list<MenuItem> $items @return list<MenuItem>
     */
    public static function tidy(array $items): array
    {
        $kept = []; $after = false; // a visible item other than a separator came last
        foreach ($items as $it) {
            $it->submenu = self::tidy(array_values($it->submenu));
            if (!$it->hidden) {
                $sep = $it->type === MenuItem::SEPARATOR;
                if ($sep && !$after) { continue; }
                $after = !$sep;
            }
            $kept[] = $it;
        }
        if (!$after) { // a separator came last
            for ($i = count($kept) - 1; $i >= 0; $i--) {
                if (!$kept[$i]->hidden) { array_splice($kept, $i, 1); break; }
            }
        }
        return $kept;
    }

    /**
     * Whether $items show the web view's items as they were ($was, by
     * number: [label, enabled, checked, accelerator, submenu numbers]), which
     * the web view then shows itself (mygo's sameItems).
     * @param list<MenuItem> $items @param list<int> $numbers @param array<int, array{0: string, 1: bool, 2: bool, 3: string, 4: ?list<int>}> $was
     */
    public static function same(array $items, array $numbers, array $was): bool
    {
        if (count($items) !== count($numbers)) { return false; }
        foreach (array_values($items) as $i => $it) {
            $w = $was[$numbers[$i]] ?? null;
            if ($it->webKit === 0 || $it->webKit !== $numbers[$i] || $w === null || $it->hidden || $it->click !== null || $it->label !== $w[0] || $it->disabled === $w[1]
                || $it->checked !== $w[2] || $it->accelerator !== $w[3] || ($it->submenu !== []) !== ($w[4] !== null && $w[4] !== [])) {
                return false;
            }
            if ($w[4] !== null && $w[4] !== [] && !self::same(array_values($it->submenu), $w[4], $was)) { return false; }
        }
        return true;
    }

    /** Drops the web view's items shown already from $items and their submenus: a native item is in one place at most (mygo's onceEach). @param list<MenuItem> $items @param array<int, true> $shown @return list<MenuItem> */
    public static function onceEach(array $items, array &$shown): array
    {
        $kept = [];
        foreach ($items as $it) {
            if ($it->webKit !== 0) {
                if (isset($shown[$it->webKit])) { continue; }
                $shown[$it->webKit] = true;
            }
            $it->submenu = self::onceEach(array_values($it->submenu), $shown);
            $kept[] = $it;
        }
        return $kept;
    }

    /**
     * Whether the page's contextmenu event at ($x, $y), CSS pixels of the
     * viewport, is that of a menu the pointer opened at ($px, $py) points
     * of the web view, at the page's $zoom, rounded to whole ones maybe; a
     * menu the keyboard opened ($pointer false) takes the latest event
     * (mygo's contextTarget.of).
     */
    public static function recordOf(float $x, float $y, bool $pointer, float $px, float $py, float $zoom): bool
    {
        if (!$pointer) { return true; }
        $zoom = $zoom > 0 ? $zoom : 1.0;
        $near = 2 * max($zoom, 1.0);
        return abs($x * $zoom - $px) <= $near && abs($y * $zoom - $py) <= $near;
    }

    // WebKit's NSMenu.

    /** Whether $menu is WebKit's context menu, rather than a menu of the app's popped up over the web view: an item of it does what WebKit does, or bears one of its identifiers. */
    public static function isWebKits($menu): bool
    {
        $forward = ObjC::addr(ObjC::sel('forwardContextMenuAction:'));
        for ($i = 0, $n = ObjC::msg('l:', $menu, 'numberOfItems'); $i < $n; $i++) {
            $item = ObjC::msg('p:l', $menu, 'itemAtIndex:', $i);
            $action = ObjC::msg('p:', $item, 'action');
            if ($action !== null && ObjC::addr($action) === $forward || str_starts_with(self::identifier($item), 'WKMenuItemIdentifier')) {
                return true;
            }
        }
        return false;
    }

    private static function identifier($item): string
    {
        if (!ObjC::msg('b:p', $item, 'respondsToSelector:', ObjC::sel('identifier'))) { return ''; }
        $s = ObjC::msg('p:', $item, 'identifier');
        return $s === null ? '' : ObjC::phpStr($s);
    }

    /**
     * Describes WebKit's items of $menu and its submenus as MenuItems,
     * numbering each in $natives, which retains it while the menus are
     * emptied and filled again, and $was, what each was.
     * @param list<mixed> $natives @param array<int, array{0: string, 1: bool, 2: bool, 3: string, 4: ?list<int>}> $was
     * @return array{0: list<MenuItem>, 1: list<int>} the items and their numbers
     */
    public static function describe($menu, array &$natives, array &$was): array
    {
        $items = []; $numbers = [];
        for ($i = 0, $n = ObjC::msg('l:', $menu, 'numberOfItems'); $i < $n; $i++) {
            $ns = ObjC::msg('p:l', $menu, 'itemAtIndex:', $i);
            $natives[] = ObjC::msg('p:', $ns, 'retain');
            $k = count($natives);
            $enabled = ObjC::msg('b:', $ns, 'isEnabled');
            if (ObjC::msg('b:', $ns, 'isSeparatorItem')) {
                $it = MenuItem::separator();
                $it->hidden = ObjC::msg('b:', $ns, 'isHidden');
                $was[$k] = ['', $enabled, false, '', null];
            } else {
                $action = ObjC::msg('p:', $ns, 'action');
                $label = ObjC::phpStr(ObjC::msg('p:', $ns, 'title'));
                $checked = ObjC::msg('l:', $ns, 'state') === 1; // NSControlStateValueOn
                $it = new MenuItem($label, type: $checked ? MenuItem::CHECKBOX : '', disabled: !$enabled, checked: $checked,
                    id: self::name(self::identifier($ns), $action === null ? '' : ObjC::selName($action), ObjC::msg('l:', $ns, 'tag')), hidden: ObjC::msg('b:', $ns, 'isHidden'));
                $sub = ObjC::msg('p:', $ns, 'submenu');
                $subNumbers = null;
                if ($sub !== null) {
                    [$kids, $subNumbers] = self::describe($sub, $natives, $was);
                    $it->submenu = $kids;
                    foreach ($kids as $kid) { $kid->parent = $it; }
                }
                $was[$k] = [$label, $enabled, $checked, '', $subNumbers];
            }
            $it->webKit = $k;
            $items[] = $it;
            $numbers[] = $k;
        }
        return [$items, $numbers];
    }

    /**
     * Fills $menu, emptied, with $items: WebKit's, as the listeners changed
     * them, and the app's (mygo's contextFill.fill).
     * @param list<MenuItem> $items @param list<mixed> $natives @param array<int, array{0: string, 1: bool, 2: bool, 3: string, 4: ?list<int>}> $was
     */
    public static function fill($menu, array $items, array $natives, array $was): void
    {
        foreach ($items as $it) {
            if ($it->hidden) { continue; }
            if ($it->webKit !== 0 && $it->click === null && isset($natives[$it->webKit - 1])) {
                ObjC::msg('v:p', $menu, 'addItem:', self::native($natives[$it->webKit - 1], $it, $natives, $was));
            } else {
                ObjC::msg('v:p', $menu, 'addItem:', self::app($it, $natives, $was));
            }
        }
    }

    /** WebKit's item, with the label, the state and the submenu the listeners gave it; a submenu left as it was is not filled again, which keeps those AppKit fills as they open, as Services. */
    private static function native($ns, MenuItem $it, array $natives, array $was)
    {
        $w = $was[$it->webKit] ?? null;
        if ($it->type === MenuItem::SEPARATOR || $w === null) { return $ns; }
        if ($it->label !== $w[0]) { ObjC::msg('v:p', $ns, 'setTitle:', ObjC::str($it->label)); }
        ObjC::msg('v:b', $ns, 'setEnabled:', !$it->disabled);
        if ($it->checked !== $w[2]) { ObjC::msg('v:l', $ns, 'setState:', $it->checked ? 1 : 0); }
        if ($it->accelerator !== $w[3]) {
            [$key, $mask] = Menu::accelerator($it->accelerator);
            ObjC::msg('v:p', $ns, 'setKeyEquivalent:', ObjC::str($key));
            ObjC::msg('v:L', $ns, 'setKeyEquivalentModifierMask:', $mask);
        }
        $sub = ObjC::msg('p:', $ns, 'submenu');
        if ($sub !== null && $w[4] !== null && !self::same(array_values($it->submenu), $w[4], $was)) {
            ObjC::msg('v:', $sub, 'removeAllItems');
            self::fill($sub, array_values($it->submenu), $natives, $was);
        }
        return $ns;
    }

    /** An item of the app's, as its other menus' (Menu::buildItem), whose submenu may hold WebKit's items. */
    private static function app(MenuItem $it, array $natives, array $was)
    {
        if ($it->type === MenuItem::SEPARATOR) {
            return ObjC::msg('p:', ObjC::cls('NSMenuItem'), 'separatorItem');
        }
        $kids = array_values($it->submenu);
        $it->submenu = [];
        try {
            $ns = Menu::buildItem($it);
        } finally {
            $it->submenu = $kids;
        }
        if ($kids !== []) {
            $sub = ObjC::msg('p:', ObjC::msg('p:p', ObjC::alloc('NSMenu'), 'initWithTitle:', ObjC::str($it->label)), 'autorelease');
            ObjC::msg('v:b', $sub, 'setAutoenablesItems:', false);
            self::fill($sub, $kids, $natives, $was);
            ObjC::msg('v:p', $ns, 'setSubmenu:', $sub);
        }
        return $ns;
    }

    /**
     * Asks $listen about the menu WebKit is about to show over web view
     * $view, opened by $event, and changes it as the listeners answer:
     * $listen gets the menu as MenuItems and where the pointer opened it
     * (null for the keyboard), points from the view's top-left corner, and
     * returns the event its listeners made of it.
     * @param \Closure(Menu, ?array{0: float, 1: float}): ContextMenuEvent $listen
     */
    public static function show($view, $menu, $event, \Closure $listen): void
    {
        if (!self::isWebKits($menu)) { return; }
        $natives = []; $was = [];
        try {
            [$items, $numbers] = self::describe($menu, $natives, $was);
            $at = null;
            if ($event !== null && in_array(ObjC::msg('l:', $event, 'type'), [1, 2, 3, 4, 25, 26], true)) { // a right or Control-click
                $p = ObjC::msg('q:qp', $view, 'convertPoint:fromView:', ObjC::msg('q:', $event, 'locationInWindow'), null);
                $y = ObjC::msg('b:', $view, 'isFlipped') ? $p->y : ObjC::msg('r:', $view, 'bounds')->h - $p->y;
                $at = [(float)$p->x, (float)$y];
            }
            $m = new Menu($items);
            $m->transient = true;
            $e = $listen($m, $at);
            if (!$e->prevented && $e->menu !== null) {
                $shown = [];
                $show = self::onceEach(array_values($e->menu->items), $shown);
                if ($e->menu === $m && self::same($show, $numbers, $was)) {
                    return; // the web view's menu as it is
                }
                $show = self::tidy($show);
            } else {
                $show = [];
            }
            ObjC::msg('v:', $menu, 'removeAllItems');
            self::fill($menu, $show, $natives, $was);
            if (ObjC::msg('l:', $menu, 'numberOfItems') === 0) {
                // An empty menu shows nothing, but AppKit still tracks it, and WebKit pops up no other until it closed: it closes once its tracking began.
                ObjC::msg('p:', $menu, 'retain');
                App::later(function () use ($menu): void {
                    ObjC::msg('v:', $menu, 'cancelTrackingWithoutAnimation');
                    ObjC::msg('v:', $menu, 'release');
                });
            }
        } finally {
            foreach ($natives as $ns) { ObjC::msg('v:', $ns, 'release'); }
        }
    }
}
