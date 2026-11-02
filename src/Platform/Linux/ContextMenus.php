<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use Closure;
use FFI\CData;
use MyPHP\{App, ContextMenuEvent, Menu, MenuItem};
use MyPHP\Native\ContextMenus as Shared;

/**
 * A page's context menu on Linux (mygo's linux context_menu.go): the web
 * view's context-menu signal hands over the WebKitContextMenu it is about
 * to show, which MyPHP describes as a Menu of MenuItems, lets the page's
 * 'contextMenu' listeners change, and changes in place. WebKit's items
 * do what they do wherever they go; the app's are items of a GAction
 * each, which WebKitGTK shows without a shortcut or an icon. What was
 * clicked comes from the bridge's record, as on macOS (Page::message).
 *
 * @internal WebView
 */
final class ContextMenus
{
    /** @var array<int, array{0: WebView, 1: string}> the app's items of the menu shown last, by id: their view and their role */
    private static array $items = [];
    private static ?CData $onActivate = null;
    /** The GEnumClass of WebKitContextMenuAction, kept: the type is static. */
    private static ?CData $actions = null;
    /** The editing roles WebKit does itself in its page (mygo's editCommands). */
    private const EDIT_COMMANDS = ['undo' => 'Undo', 'redo' => 'Redo', 'cut' => 'Cut', 'copy' => 'Copy', 'paste' => 'Paste',
        'pasteAndMatchStyle' => 'PasteAsPlainText', 'delete' => 'Delete', 'selectAll' => 'SelectAll'];
    /** MyPHP's names of WebKitContextMenuAction's values other platforms have (mygo's contextActionName); the others are their nicks. */
    private const NAMES = [1 => 'openLink', 2 => 'openLinkInNewWindow', 3 => 'downloadLink', 4 => 'copyLink', 5 => 'openImageInNewWindow',
        6 => 'downloadImage', 7 => 'copyImage', 8 => 'copyImageURL', 9 => 'openFrameInNewWindow', 10 => 'back', 11 => 'forward', 13 => 'reload',
        18 => 'selectAll', 21 => 'spellingGuess', 31 => 'inspect', 32 => 'openMediaInNewWindow', 33 => 'openMediaInNewWindow',
        34 => 'copyMediaURL', 35 => 'copyMediaURL', 36 => 'mediaControls', 37 => 'mediaLoop', 38 => 'mediaFullScreen',
        42 => 'downloadMedia', 43 => 'downloadMedia', 44 => 'emoji', 45 => 'pasteAndMatchStyle'];
    private const NO_ACTION = 0, CUSTOM = 10000;

    /**
     * Describes $menu, asks $listen, and changes the menu as it answers;
     * returns true to show none.
     * @param Closure(Menu, ?array{0: float, 1: float}): ContextMenuEvent $listen
     */
    public static function show(WebView $view, CData $menu, ?CData $event, Closure $listen): bool
    {
        $f = WebKit::ffi();
        $natives = []; $was = [];
        try {
            [$items, $numbers] = self::describe($menu, $natives, $was);
            $at = null;
            if ($event !== null) {
                $b = Gtk::ffi()->cast('GdkEventButton*', $event);
                if ($b->type >= 4 && $b->type <= 7) { $at = [(float)$b->x, (float)$b->y]; } // a button's, at a point of the web view; the menu key's has none
            }
            $m = new Menu($items);
            $m->transient = true;
            $e = $listen($m, $at);
            if ($e->prevented || $e->menu === null) {
                return true;
            }
            $shown = [];
            $show = Shared::onceEach(array_values($e->menu->items), $shown);
            if ($e->menu === $m && Shared::same($show, $numbers, $was)) {
                return false; // WebKit's menu as it is
            }
            self::$items = [];
            self::fill($view, $menu, Shared::tidy($show), $natives, $was);
            return false;
        } finally {
            foreach ($natives as $n) { $f->g_object_unref($n); }
        }
    }

    /**
     * WebKit's items of $menu and its submenus as MenuItems, each numbered
     * and referenced in $natives while the menus are emptied and filled
     * again, $was what each was (mygo's describeContextMenu).
     * @param list<CData> $natives @param array<int, array{0: string, 1: bool, 2: bool, 3: string, 4: ?list<int>}> $was
     * @return array{0: list<MenuItem>, 1: list<int>}
     */
    private static function describe(CData $menu, array &$natives, array &$was): array
    {
        $f = WebKit::ffi();
        $items = []; $numbers = [];
        for ($node = $f->webkit_context_menu_get_items($menu); $node !== null; $node = $node->next) {
            $natives[] = $f->g_object_ref($node->data);
            $item = $node->data;
            $k = count($natives);
            if ($f->webkit_context_menu_item_is_separator($item)) {
                $it = MenuItem::separator();
                $was[$k] = ['', true, false, '', null];
            } else {
                [$label, $enabled, $checked] = self::state($item);
                $it = new MenuItem($label, type: $checked !== null ? MenuItem::CHECKBOX : '', disabled: !$enabled, checked: $checked === true,
                    id: self::name($f->webkit_context_menu_item_get_stock_action($item)));
                $subNumbers = null;
                if (($sub = $f->webkit_context_menu_item_get_submenu($item)) !== null) {
                    [$kids, $subNumbers] = self::describe($sub, $natives, $was);
                    $it->submenu = $kids;
                    foreach ($kids as $kid) { $kid->parent = $it; }
                }
                $was[$k] = [$label, $enabled, $checked === true, '', $subNumbers];
            }
            $it->webKit = $k;
            $items[] = $it;
            $numbers[] = $k;
        }
        return [$items, $numbers];
    }

    /** An item's label (its GtkAction's, without the mnemonic's underscore), whether it is enabled, and its check (null: none). @return array{0: string, 1: bool, 2: ?bool} */
    private static function state(CData $item): array
    {
        $f = WebKit::ffi();
        $label = '';
        if (($a = $f->webkit_context_menu_item_get_action($item)) !== null) {
            $label = self::withoutMnemonic((string)$f->gtk_action_get_label($a));
        }
        $enabled = true; $checked = null;
        if (($g = $f->webkit_context_menu_item_get_gaction($item)) !== null) {
            $enabled = (bool)$f->g_action_get_enabled($g);
            if (($st = $f->g_action_get_state($g)) !== null) {
                if ($f->g_variant_get_type_string($st) === 'b') { $checked = (bool)$f->g_variant_get_boolean($st); }
                $f->g_variant_unref($st);
            }
        }
        return [$label, $enabled, $checked];
    }

    /** Empties $menu and fills it with $items: WebKit's, as the listeners changed them, and the app's (mygo's fillContextMenu). @param list<MenuItem> $items */
    private static function fill(WebView $view, CData $menu, array $items, array $natives, array $was): void
    {
        $f = WebKit::ffi();
        $f->webkit_context_menu_remove_all($menu);
        foreach ($items as $it) {
            if ($it->hidden) { continue; }
            $native = $it->webKit !== 0 && $it->click === null ? ($natives[$it->webKit - 1] ?? null) : null;
            $f->webkit_context_menu_append($menu, $native !== null ? self::native($view, $native, $it, $natives, $was) : self::app($view, $it, $natives, $was));
        }
    }

    /** WebKit's item with the label, the state and the submenu the listeners gave it, or one of the same stock action in its place: WebKitGTK renames none (mygo's nativeContextItem). */
    private static function native(WebView $view, CData $item, MenuItem $it, array $natives, array $was): CData
    {
        $f = WebKit::ffi();
        $w = $was[$it->webKit] ?? null;
        if ($it->type === MenuItem::SEPARATOR || $w === null) {
            return $item;
        }
        $sub = $f->webkit_context_menu_item_get_submenu($item);
        if ($sub !== null && $w[4] !== null && !Shared::same(array_values($it->submenu), $w[4], $was)) {
            self::fill($view, $sub, array_values($it->submenu), $natives, $was);
        }
        $action = $f->webkit_context_menu_item_get_stock_action($item);
        if ($it->label !== $w[0] && $sub === null && $action !== self::NO_ACTION && $action !== self::CUSTOM) {
            $item = $f->webkit_context_menu_item_new_from_stock_action_with_label($action, self::mnemonicLabel($it->label));
        }
        if (($g = $f->webkit_context_menu_item_get_gaction($item)) !== null && $f->g_type_check_instance_is_a($g, $f->g_simple_action_get_type())) {
            $f->g_simple_action_set_enabled($g, $it->disabled ? 0 : 1);
            if (($st = $f->g_action_get_state($g)) !== null) {
                if ($f->g_variant_get_type_string($st) === 'b' && (bool)$f->g_variant_get_boolean($st) !== $it->checked) {
                    $f->g_simple_action_set_state($g, $f->g_variant_new_boolean($it->checked ? 1 : 0));
                }
                $f->g_variant_unref($st);
            }
        }
        return $item;
    }

    /** An item of the app's: a separator, a submenu, or an item of a GAction of its own, a check box's stateful (mygo's appContextItem). */
    private static function app(WebView $view, MenuItem $it, array $natives, array $was): CData
    {
        $f = WebKit::ffi();
        if ($it->type === MenuItem::SEPARATOR) {
            return $f->webkit_context_menu_item_new_separator();
        }
        $kids = array_values($it->submenu);
        $it->submenu = [];
        try {
            $p = Menu::snapshotItem($it);
        } finally {
            $it->submenu = $kids;
        }
        $label = self::mnemonicLabel($p->label);
        if ($kids !== []) {
            $sub = $f->webkit_context_menu_new();
            self::fill($view, $sub, $kids, $natives, $was);
            return $f->webkit_context_menu_item_new_with_submenu($label, $sub); // which takes it
        }
        $name = 'myphp-' . $p->id;
        $action = $p->type === MenuItem::CHECKBOX || $p->type === MenuItem::RADIO
            ? $f->g_simple_action_new_stateful($name, null, $f->g_variant_new_boolean($p->checked ? 1 : 0))
            : $f->g_simple_action_new($name, null);
        $f->g_simple_action_set_enabled($action, $p->enabled ? 1 : 0);
        if (self::$onActivate === null) {
            self::$onActivate = $f->new('HNotify', false);
            self::$onActivate->fn = function ($action, $parameter, $data): void {
                [$v, $role] = self::$items[WebKit::addr($data)] ?? [null, ''];
                if ($v === null) { return; }
                if (isset(self::EDIT_COMMANDS[$role])) {
                    WebKit::ffi()->webkit_web_view_execute_editing_command($v->web, self::EDIT_COMMANDS[$role]);
                    return;
                }
                App::backend()->h->menuItemClicked(WebKit::addr($data));
            };
        }
        $f->g_signal_connect_data($action, 'activate', $f->cast('void*', self::$onActivate->fn), $f->cast('void*', $p->id), null, 0);
        self::$items[$p->id] = [$view, $p->role];
        $item = $f->webkit_context_menu_item_new_from_gaction($action, $label, null);
        $f->g_object_unref($action); // the item holds it
        return $item;
    }

    /** MyPHP's name of a stock action, else its nick ("spelling-guess"), "" for none (mygo's contextActionName). */
    private static function name(int $action): string
    {
        if ($action === self::NO_ACTION || $action === self::CUSTOM) {
            return '';
        }
        if (isset(self::NAMES[$action])) {
            return self::NAMES[$action];
        }
        $f = WebKit::ffi();
        self::$actions ??= $f->g_type_class_ref($f->webkit_context_menu_action_get_type());
        $v = $f->g_enum_get_value(self::$actions, $action);
        return $v === null ? '' : (string)$v->value_nick;
    }

    /** "_Copy" as "Copy". */
    private static function withoutMnemonic(string $s): string
    {
        return preg_replace('/_(.)/s', '$1', $s);
    }

    /** The label GTK shows as $s, whose underscores would mark mnemonics. */
    private static function mnemonicLabel(string $s): string
    {
        return str_replace('_', '__', $s);
    }
}
