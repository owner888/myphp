<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use Closure;
use FFI;
use FFI\CData;
use MyPHP\UI\{AccessNode, AccessTree, Role};
use MyPHP\Native\Mem;

/**
 * Assistive technology, such as Orca, reads applications through AT-SPI,
 * which GTK bridges to ATK (mygo's internal/linux/surface_access.go). The
 * area native UI draws in is a subclass of GtkGLArea whose accessible, a
 * subclass of GtkWidgetAccessible, has the nodes of the content's tree
 * below it: ATK objects of types registered here, which answer from the
 * last tree and turn what assistive technology does into the core's
 * ['access', …] events. The page of a web view is inside the node standing
 * for it: the node's child is the web view's accessible, an AtkSocket the
 * web process's tree is embedded in, which the overlay holding the web
 * views and the area (a subclass) does not list beside the content.
 *
 * Registering types writes functions into ATK's class and interface
 * structures at offsets of its headers. The class's size is checked
 * against the one GObject reports, and the interfaces' layout against
 * ATK's version; native UI goes without accessibility rather than write
 * past them.
 */
final class Access
{
    /** GObjectClass, 26 functions and a pad. */
    private const ATK_OBJECT_CLASS_SIZE = 352;
    /** Offsets of the functions of AtkObjectClass. */
    private const GET_N_CHILDREN = 160, REF_CHILD = 168, GET_INDEX_IN_PARENT = 176, REF_STATE_SET = 216, GET_ATTRIBUTES = 328;
    /** The functions of interfaces follow a GTypeInterface. */
    private const IFACE_HEADER = 16;
    private const G_TYPE_DOUBLE = 15 << 2, ATK_XY_PARENT = 2;

    private static ?FFI $f = null;
    private static bool $tried = false;
    /** The types: the area, its accessible, and the nodes', by the interfaces they implement; the overlay and its accessible. */
    private static int $areaType = 0, $rootType = 0, $nodeType = 0, $rangeType = 0, $textType = 0, $editableType = 0, $selectionType = 0;
    private static int $overlayType = 0, $overlayAccessType = 0, $socketType = 0;
    /** GtkWidgetAccessible's get_index_in_parent, which the root's calls. */
    private static ?CData $rootParentIndex = null;
    private static bool $announces = false;
    /** @var array<int, int> ATK's roles by Role */
    private static array $roles = [];
    /** @var array<string, int> ATK's roles and states by their names */
    private static array $atkRole = [], $st = [];
    /** @var list<CData> the C functions made of closures, and the structures given to GObject, held for as long as the process */
    private static array $held = [];

    /** @var array<int, self> the trees, by their window's area */
    private static array $byArea = [];
    /** @var array<int, self> the trees, by the area's accessible */
    private static array $byRoot = [];
    /** @var array<int, array{0: self, 1: int}> the nodes, by their ATK object: their tree and node id */
    private static array $objects = [];

    /** @var array<int, array{obj: CData, type: int, parent: int, children: list<int>, n: AccessNode, chosen: list<int>, page: ?CData}> the nodes by id; parent 0 for those at the top */
    private array $nodes = [];
    /** @var list<int> the ids of the nodes at the top */
    private array $top = [];
    private int $focus = 0;
    /** Building the tree assistive technology asked for: nothing told of it. */
    private bool $quiet = false;
    private ?CData $root = null;

    private function __construct(private readonly Window $win, private readonly CData $area) {}

    /** The area of a window that assistive technology sees the content of: a GtkGLArea of the type registered here, where ATK allows it. */
    public static function newArea(Window $win): CData
    {
        self::register();
        $g = Gtk::ffi();
        $area = $g->cast('void*', self::$areaType !== 0 ? self::$f->g_object_new(self::$areaType, null) : $g->gtk_gl_area_new());
        self::$byArea[Mem::addr($area)] = new self($win, $area);
        return $area;
    }

    /** The overlay putting an area over its web views, which lists the area alone. */
    public static function newOverlay(): CData
    {
        $g = Gtk::ffi();
        return self::$overlayType !== 0 ? $g->cast('void*', self::$f->g_object_new(self::$overlayType, null)) : $g->gtk_overlay_new();
    }

    /** Shows assistive technology the content's last frame of the window of $area, and tells it what changed. */
    public static function update(CData $area, AccessTree $tree): void
    {
        $t = self::$byArea[Mem::addr($area)] ?? null;
        if ($t !== null && $t->root !== null) { $t->apply($tree); }
    }

    /** Lets go of the tree of the window of $area, which is going. */
    public static function destroy(CData $area): void
    {
        $t = self::$byArea[Mem::addr($area)] ?? null;
        if ($t === null) {
            return;
        }
        unset(self::$byArea[Mem::addr($area)]);
        if ($t->root !== null) { unset(self::$byRoot[Mem::addr($t->root)]); }
        $t->graft([]);
        foreach (array_keys($t->nodes) as $id) { $t->release($id); }
        $t->nodes = []; $t->top = [];
    }

    private static function register(): void
    {
        if (self::$tried) {
            return;
        }
        self::$tried = true;
        try {
            self::$f = FFI::cdef(self::HEADER, 'libgtk-3.so.0'); // ATK, GObject and GLib come with GTK
        } catch (FFI\Exception) {
            return; // no ATK: native UI without accessibility, as upstream
        }
        $f = self::$f;
        $major = $f->atk_get_major_version(); $minor = $f->atk_get_minor_version();
        if ($major < 2 || $major === 2 && $minor < 12) { // the value and text functions written below arrived in ATK 2.12
            return;
        }
        $q = $f->new('GTypeQuery');
        $f->g_type_query($f->atk_object_get_type(), FFI::addr($q));
        if ($q->class_size !== self::ATK_OBJECT_CLASS_SIZE) {
            return;
        }
        self::names();
        self::$announces = $f->g_signal_lookup('announcement', $f->atk_object_get_type()) !== 0; // AtkObject's, since ATK 2.46
        $cb = self::callbacks($major > 2 || $minor >= 30);
        $register = function (int $parent, string $name, ?CData $classInit) use ($f): int {
            $q = $f->new('GTypeQuery');
            $f->g_type_query($parent, FFI::addr($q));
            return $f->g_type_register_static_simple($parent, $name, $q->class_size, $classInit, $q->instance_size, null, 0);
        };
        $implement = function (int $type, int $iface, CData $init) use ($f): void {
            $info = $f->new('GInterfaceInfo', false); // GObject keeps it
            $info->interface_init = $init;
            self::$held[] = $info;
            $f->g_type_add_interface_static($type, $iface, FFI::addr($info));
        };
        self::$rootType = $register($f->gtk_widget_accessible_get_type(), 'MyPHPSurfaceAccessible', $cb['rootClass']);
        self::$nodeType = $register($f->atk_object_get_type(), 'MyPHPAccessible', $cb['nodeClass']);
        if (self::$rootType === 0 || self::$nodeType === 0) {
            return;
        }
        self::$rangeType = $register(self::$nodeType, 'MyPHPAccessibleRange', null);
        self::$textType = $register(self::$nodeType, 'MyPHPAccessibleText', null);
        self::$editableType = $register(self::$textType, 'MyPHPAccessibleEditable', null);
        self::$selectionType = $register(self::$nodeType, 'MyPHPAccessibleSelection', null);
        if (self::$rangeType === 0 || self::$textType === 0 || self::$editableType === 0 || self::$selectionType === 0) {
            return;
        }
        $implement(self::$rootType, $f->atk_component_get_type(), $cb['rootComponent']);
        $implement(self::$nodeType, $f->atk_component_get_type(), $cb['component']);
        $implement(self::$nodeType, $f->atk_action_get_type(), $cb['action']);
        $implement(self::$rangeType, $f->atk_value_get_type(), $cb['value']);
        $implement(self::$textType, $f->atk_text_get_type(), $cb['text']);
        $implement(self::$editableType, $f->atk_editable_text_get_type(), $cb['editable']);
        $implement(self::$selectionType, $f->atk_selection_get_type(), $cb['selection']);
        // The overlay holding web views lists the area alone, whose nodes hold the pages.
        self::$socketType = $f->atk_socket_get_type();
        self::$overlayAccessType = $register($f->gtk_container_accessible_get_type(), 'MyPHPSurfaceOverlayAccessible', $cb['overlayAccessClass']);
        if (self::$overlayAccessType !== 0) {
            self::$overlayType = $register($f->gtk_overlay_get_type(), 'MyPHPSurfaceOverlay', $cb['overlayClass']);
        }
        self::$areaType = $register($f->gtk_gl_area_get_type(), 'MyPHPSurfaceArea', $cb['areaClass']); // last: an area of it is accessible
    }

    /** ATK's roles and states by name, which, unlike their numbers, do not change between versions. */
    private static function names(): void
    {
        $f = self::$f;
        $role = function (string ...$names) use ($f): int {
            foreach ($names as $n) { if (($r = $f->atk_role_for_name($n)) !== 0) { return $r; } }
            return 0;
        };
        self::$atkRole = ['panel' => $role('panel'), 'text' => $role('text'), 'password' => $role('password text'), 'listBox' => $role('list box')];
        foreach ([
            Role::GROUP => ['panel'], Role::TEXT => ['label'], Role::BUTTON => ['push button', 'button'], Role::LINK => ['link'],
            Role::CHECK_BOX => ['check box'], Role::RADIO => ['radio button'], Role::SWITCH => ['switch', 'toggle button'], Role::SLIDER => ['slider'],
            Role::PROGRESS => ['progress bar'], Role::TEXT_FIELD => ['entry'], Role::IMAGE => ['image'], Role::LIST => ['list'],
            Role::SCROLL => ['scroll pane'], Role::DIALOG => ['dialog'], Role::POPUP => ['popup menu'], Role::TOOLTIP => ['tool tip'],
            Role::POP_UP_BUTTON => ['combo box'], Role::TAB_LIST => ['page tab list'], Role::TAB => ['page tab'], Role::SPLITTER => ['separator'],
            Role::STATUS => ['notification', 'status bar'], Role::TABLE => ['table'], Role::ROW => ['table row'], Role::CELL => ['table cell'],
            Role::COLUMN_HEADER => ['column header', 'table column header'], Role::TREE => ['tree', 'tree table'], Role::TREE_ITEM => ['tree item', 'list item'],
            Role::LIST_ITEM => ['list item'], Role::MENU_BUTTON => ['push button menu', 'push button', 'button'], Role::TOOLBAR => ['tool bar'],
            Role::RADIO_GROUP => ['panel'], Role::TOGGLE_BUTTON => ['toggle button'], Role::COMBO_BOX => ['combo box'], Role::DISCLOSURE => ['toggle button'],
            Role::METER => ['level bar', 'progress bar'], Role::STEPPER => ['spin button'], Role::COLOR_WELL => ['push button', 'button'],
            Role::ALERT_DIALOG => ['alert', 'dialog'], Role::MENU => ['menu'], Role::MENU_BAR => ['menu bar'], Role::MENU_ITEM => ['menu item'],
            Role::MENU_ITEM_CHECK_BOX => ['check menu item', 'menu item'], Role::MENU_ITEM_RADIO => ['radio menu item', 'menu item'], Role::HEADING => ['heading', 'label'],
        ] as $r => $names) {
            self::$roles[$r] = $role(...$names);
        }
        foreach (['enabled', 'sensitive', 'visible', 'showing', 'focusable', 'focused', 'checkable', 'checked', 'indeterminate', 'expandable', 'expanded',
            'editable', 'read-only', 'multi-line', 'single-line', 'selectable-text', 'defunct', 'selectable', 'selected', 'multiselectable', 'has-popup',
            'invalid-entry', 'vertical', 'horizontal'] as $name) {
            self::$st[$name] = $f->atk_state_type_for_name($name);
        }
    }

    // ---- the tree ---------------------------------------------------------

    /** The tree below the area's accessible $root, asking the content for it while it has none, as before its first frame. */
    private static function rootTree(CData $root): ?self
    {
        $t = self::$byRoot[Mem::addr($root)] ?? null;
        if ($t === null) {
            $area = self::$f->gtk_accessible_get_widget($root);
            $t = $area === null ? null : (self::$byArea[Mem::addr($area)] ?? null);
            if ($t === null || $t->win->isDead()) {
                return null;
            }
            $t->root = $root;
            self::$byRoot[Mem::addr($root)] = $t;
        }
        if ($t->top === []) {
            $t->quiet = true;
            $t->win->accessibilityOn(); // the content describes its last frame now (Runtime::accessibilityOn), which comes back through update
            $t->quiet = false;
        }
        return $t;
    }

    private static function chooses(AccessNode $n): bool
    {
        return in_array($n->role, [Role::LIST_ITEM, Role::ROW, Role::TREE_ITEM], true) && ($n->states & AccessNode::SELECTABLE) !== 0;
    }

    /** @return list<int> the rows of node $id, a list or a table, that are chosen */
    private function chosenRows(int $id): array
    {
        $rows = [];
        foreach ($this->nodes[$id]['children'] as $c) {
            $n = $this->nodes[$c]['n'];
            if (self::chooses($n) && ($n->states & AccessNode::CHECKED) !== 0) { $rows[] = $c; }
        }
        return $rows;
    }

    private static function typeOf(AccessNode $n): int
    {
        return match (true) {
            Role::ranged($n->role) => self::$rangeType,
            $n->role === Role::TEXT_FIELD || $n->role === Role::COMBO_BOX => self::$editableType,
            $n->role === Role::TEXT => self::$textType,
            in_array($n->role, [Role::LIST, Role::TABLE, Role::TREE], true) && ($n->states & AccessNode::SELECTABLE) !== 0 => self::$selectionType, // choosing its rows
            default => self::$nodeType,
        };
    }

    private static function atkRoleOf(AccessNode $n): int
    {
        $r = self::$roles[$n->role] ?? 0;
        if ($n->role === Role::LIST && ($n->states & AccessNode::SELECTABLE) !== 0 && self::$atkRole['listBox'] !== 0) { $r = self::$atkRole['listBox']; } // choosing its items
        if ($n->role === Role::TEXT_FIELD) {
            if (($n->states & AccessNode::PASSWORD) !== 0) { $r = self::$atkRole['password']; }
            elseif (($n->states & AccessNode::MULTILINE) !== 0) { $r = self::$atkRole['text']; }
        }
        return $r !== 0 ? $r : self::$atkRole['panel'];
    }

    private function apply(AccessTree $tree): void
    {
        $f = self::$f;
        $notify = !$this->quiet;
        $old = $this->nodes;
        $this->nodes = [];
        $list = []; $prev = []; $fresh = [];
        $oldKids = [0 => $this->top]; // by parent id, 0 the root
        foreach ($tree->nodes as $i => $n) {
            $type = self::typeOf($n);
            $an = $old[$n->id] ?? null;
            if ($an !== null && $an['type'] === $type) {
                unset($old[$n->id]);
                $oldKids[$n->id] = $an['children'];
                $prev[$i] = $an['n'];
                $fresh[$i] = false;
            } else {
                $obj = $f->cast('void*', $f->g_object_new($type, null));
                $an = ['obj' => $obj, 'type' => $type, 'parent' => -1, 'children' => [], 'n' => $n, 'chosen' => [], 'page' => null];
                self::$objects[Mem::addr($obj)] = [$this, $n->id];
                $prev[$i] = null;
                $fresh[$i] = true;
            }
            $an['n'] = $n;
            $an['children'] = [];
            $this->nodes[$n->id] = $an;
            $list[$i] = $n->id;
        }
        $this->top = [];
        foreach ($list as $i => $id) {
            $an = &$this->nodes[$id];
            $n = $an['n'];
            $parent = $n->parent >= 0 ? $list[$n->parent] : 0;
            if ($parent !== 0) { $this->nodes[$parent]['children'][] = $id; } else { $this->top[] = $id; }
            if ($fresh[$i] || $an['parent'] !== $parent) { $f->atk_object_set_parent($an['obj'], $parent !== 0 ? $this->nodes[$parent]['obj'] : $this->root); }
            $an['parent'] = $parent;
            $p = $prev[$i];
            $roleStates = AccessNode::PASSWORD | AccessNode::MULTILINE | AccessNode::SELECTABLE | AccessNode::MULTISELECTABLE;
            if ($p === null || $p->role !== $n->role || ($p->states & $roleStates) !== ($n->states & $roleStates)) { $f->atk_object_set_role($an['obj'], self::atkRoleOf($n)); }
            if ($p === null || $p->label !== $n->label) { $f->atk_object_set_name($an['obj'], $n->label); }
            if ($p === null || $p->description !== $n->description) { $f->atk_object_set_description($an['obj'], $n->description); }
            unset($an);
        }
        if ($notify) {
            // Children that left their parent, then those that joined it, for the parents that were in the tree before.
            foreach ($oldKids as $parent => $kids) {
                if ($parent !== 0 && !isset($this->nodes[$parent])) { continue; }
                [$obj, $now] = $parent === 0 ? [$this->root, $this->top] : [$this->nodes[$parent]['obj'], $this->nodes[$parent]['children']];
                for ($i = count($kids) - 1; $i >= 0; $i--) {
                    if (!in_array($kids[$i], $now, true)) {
                        $gone = $this->nodes[$kids[$i]]['obj'] ?? $old[$kids[$i]]['obj'] ?? null;
                        if ($gone !== null) { $f->g_signal_emit_by_name($obj, 'children-changed::remove', self::u32($i), $gone); }
                    }
                }
                foreach ($now as $i => $kid) {
                    if (!in_array($kid, $kids, true)) { $f->g_signal_emit_by_name($obj, 'children-changed::add', self::u32($i), $this->nodes[$kid]['obj']); }
                }
            }
            foreach ($list as $i => $id) {
                if (!$fresh[$i]) { $this->notifyChanges($id, $prev[$i]); }
            }
        }
        // Lists choosing their rows tell when the choice moves.
        foreach ($list as $id) {
            if ($this->nodes[$id]['type'] !== self::$selectionType) { continue; }
            $chosen = $this->chosenRows($id);
            if ($chosen !== $this->nodes[$id]['chosen']) {
                $this->nodes[$id]['chosen'] = $chosen;
                if ($notify) { $f->g_signal_emit_by_name($this->nodes[$id]['obj'], 'selection-changed'); }
            }
        }
        $this->graft($list);
        foreach ($old as $id => $an) { $this->releaseNode($an); }
        if ($tree->focus !== $this->focus) {
            if ($notify && isset($this->nodes[$this->focus])) { $f->atk_object_notify_state_change($this->nodes[$this->focus]['obj'], self::$st['focused'], 0); }
            $this->focus = $tree->focus;
            if ($notify && isset($this->nodes[$this->focus])) { $f->atk_object_notify_state_change($this->nodes[$this->focus]['obj'], self::$st['focused'], 1); }
        }
        if (self::$announces && $notify) {
            foreach ($tree->announcements as $text) { $f->g_signal_emit_by_name($this->root, 'announcement', $text); }
        }
    }

    /** Tells assistive technology how node $id changed since the last tree, but for its focus. */
    private function notifyChanges(int $id, AccessNode $prev): void
    {
        $f = self::$f;
        $an = $this->nodes[$id];
        $n = $an['n'];
        $before = self::stateList($prev, false); $after = self::stateList($n, false);
        foreach ($before as $s) { if (!in_array($s, $after, true)) { $f->atk_object_notify_state_change($an['obj'], $s, 0); } }
        foreach ($after as $s) { if (!in_array($s, $before, true)) { $f->atk_object_notify_state_change($an['obj'], $s, 1); } }
        if ($an['type'] === self::$rangeType) {
            if ($prev->now !== $n->now || $prev->min !== $n->min || $prev->max !== $n->max) { $f->g_object_notify($an['obj'], 'accessible-value'); }
        } elseif ($an['type'] === self::$editableType) {
            if ($prev->value !== $n->value) { // what changed, between the text both have at their ends
                $a = mb_str_split($prev->value); $b = mb_str_split($n->value);
                $start = 0;
                while ($start < count($a) && $start < count($b) && $a[$start] === $b[$start]) { $start++; }
                $end = 0;
                while ($end < count($a) - $start && $end < count($b) - $start && $a[count($a) - 1 - $end] === $b[count($b) - 1 - $end]) { $end++; }
                if (($removed = array_slice($a, $start, count($a) - $end - $start)) !== []) { $f->g_signal_emit_by_name($an['obj'], 'text-remove', self::i32($start), self::i32(count($removed)), implode('', $removed)); }
                if (($inserted = array_slice($b, $start, count($b) - $end - $start)) !== []) { $f->g_signal_emit_by_name($an['obj'], 'text-insert', self::i32($start), self::i32(count($inserted)), implode('', $inserted)); }
            }
            if ($prev->selEnd !== $n->selEnd) { $f->g_signal_emit_by_name($an['obj'], 'text-caret-moved', self::i32($n->selEnd)); }
            if (($prev->selStart !== $n->selStart || $prev->selEnd !== $n->selEnd) && ($prev->selStart !== $prev->selEnd || $n->selStart !== $n->selEnd)) {
                $f->g_signal_emit_by_name($an['obj'], 'text-selection-changed');
            }
        }
    }

    /** @return list<int> the ATK states of a node */
    private static function stateList(AccessNode $n, bool $focused): array
    {
        $st = self::$st;
        $list = [$st['visible']];
        if (($n->states & AccessNode::OFFSCREEN) === 0) { $list[] = $st['showing']; }
        if (($n->states & AccessNode::DISABLED) === 0) { $list[] = $st['enabled']; $list[] = $st['sensitive']; }
        if (($n->states & AccessNode::FOCUSABLE) !== 0) { $list[] = $st['focusable']; }
        if ($focused) { $list[] = $st['focused']; }
        $item = in_array($n->role, [Role::TAB, Role::TREE_ITEM, Role::ROW, Role::LIST_ITEM], true);
        switch ($n->role) {
            case Role::CHECK_BOX: case Role::RADIO: case Role::SWITCH: case Role::MENU_ITEM_CHECK_BOX: case Role::MENU_ITEM_RADIO:
                $list[] = $st['checkable'];
                break;
            case Role::TAB: case Role::TREE_ITEM: case Role::ROW: case Role::LIST_ITEM:
                if (($n->states & AccessNode::SELECTABLE) === 0 && ($n->role === Role::ROW || $n->role === Role::LIST_ITEM)) { break; } // rows and items of lists only where their list chooses them
                $list[] = $st['selectable'];
                if (($n->states & AccessNode::CHECKED) !== 0) { $list[] = $st['selected']; } // not checked
                break;
            case Role::POP_UP_BUTTON:
                $list[] = $st['expandable'];
                break;
            case Role::SLIDER: // as GtkScale's
                $list[] = ($n->states & AccessNode::VERTICAL) !== 0 ? $st['vertical'] : $st['horizontal'];
                break;
            case Role::DISCLOSURE: // checked while open, as GTK's expanders
                $list[] = $st['expandable'];
                if (($n->states & AccessNode::EXPANDED) !== 0) { $list[] = $st['checked']; }
                break;
            case Role::MENU_BUTTON:
                $list[] = $st['has-popup'];
                break;
            case Role::TEXT_FIELD: case Role::COMBO_BOX:
                if ($n->role === Role::COMBO_BOX) { $list[] = $st['expandable']; }
                $list[] = $st['selectable-text'];
                $list[] = ($n->states & AccessNode::READ_ONLY) === 0 ? $st['editable'] : $st['read-only'];
                $list[] = ($n->states & AccessNode::MULTILINE) !== 0 ? $st['multi-line'] : $st['single-line'];
                break;
        }
        if (($n->states & AccessNode::CHECKED) !== 0 && !$item) { $list[] = $st['checked']; } // a toggle button is checked while pressed, as GTK's are
        if (($n->states & AccessNode::MIXED) !== 0 || $n->now < $n->min) { $list[] = $st['indeterminate']; } // a progress of unknown length
        if (($n->states & AccessNode::EXPANDABLE) !== 0) { $list[] = $st['expandable']; }
        if (($n->states & AccessNode::EXPANDED) !== 0) { $list[] = $st['expanded']; }
        if (($n->states & AccessNode::MULTISELECTABLE) !== 0) { $list[] = $st['multiselectable']; }
        if (($n->states & AccessNode::INVALID) !== 0) { $list[] = $st['invalid-entry']; }
        return array_values(array_filter($list, fn(int $s) => $s !== 0));
    }

    /** Lets go of node $id, which left the tree. */
    private function release(int $id): void
    {
        $this->releaseNode($this->nodes[$id]);
    }

    /** @param array{obj: CData} $an */
    private function releaseNode(array $an): void
    {
        self::$f->atk_object_notify_state_change($an['obj'], self::$st['defunct'], 1);
        unset(self::$objects[Mem::addr($an['obj'])]);
        self::$f->g_object_unref($an['obj']);
    }

    /** Performs an action of assistive technology on node $id, as the core's ['access', …] event. */
    private function act(int $id, int $action, string $text = '', int $from = 0, int $to = 0): void
    {
        $this->win->accessAction($id, $action, $text, $from, $to);
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} node $id's bounds in ATK's coordinates */
    private function extents(int $id, int $coords): array
    {
        $an = $this->nodes[$id];
        [$x, $y, $w, $h] = $an['n']->bounds;
        if ($coords === self::ATK_XY_PARENT) {
            if ($an['parent'] !== 0) { $x -= $this->nodes[$an['parent']]['n']->bounds[0]; $y -= $this->nodes[$an['parent']]['n']->bounds[1]; }
        } else {
            $f = self::$f;
            $r = $f->new('int[4]');
            $f->atk_component_get_extents($this->root, FFI::addr($r[0]), FFI::addr($r[1]), FFI::addr($r[2]), FFI::addr($r[3]), $coords);
            if ($r[0] === -2147483648) { return [$r[0], $r[0], (int)$w, (int)$h]; } // not on the screen
            $x += $r[0]; $y += $r[1];
        }
        return [(int)floor($x), (int)floor($y), (int)round($w), (int)round($h)];
    }

    private function contains(int $id, int $x, int $y, int $coords): bool
    {
        [$nx, $ny, $w, $h] = $this->extents($id, $coords);
        return $x >= $nx && $y >= $ny && $x < $nx + $w && $y < $ny + $h;
    }

    /** A new reference to the topmost of nodes $ids at a point, or null. @param list<int> $ids */
    private function at(array $ids, int $x, int $y, int $coords): ?CData
    {
        for ($i = count($ids) - 1; $i >= 0; $i--) {
            if ($this->contains($ids[$i], $x, $y, $coords)) { return self::$f->g_object_ref($this->nodes[$ids[$i]]['obj']); }
        }
        return null;
    }

    /** @return list<string> the characters of node $id that ATK reads as text */
    private function text(int $id): array
    {
        $an = $this->nodes[$id];
        return mb_str_split($an['type'] === self::$textType ? $an['n']->label : $an['n']->value);
    }

    /**
     * Puts the pages of the window's web views in the nodes of $list standing for them, and takes those of the others out.
     * @param list<int> $list
     */
    private function graft(array $list): void
    {
        $grafted = [];
        foreach ($list as $id) {
            $this->nodes[$id]['page'] = null;
            $v = $this->nodes[$id]['n']->webView;
            if (!$v instanceof \MyPHP\WebView) { continue; }
            $sv = $this->win->surfaceWebView($v);
            if ($sv === null || isset($grafted[spl_object_id($sv)])) { continue; }
            if (($acc = self::pageAccessible($sv)) !== null) {
                $grafted[spl_object_id($sv)] = true;
                $this->nodes[$id]['page'] = $acc;
                if (Mem::addr($sv->accessParent) !== Mem::addr($this->nodes[$id]['obj'])) {
                    $sv->accessParent = $this->nodes[$id]['obj'];
                    self::$f->atk_object_set_parent($acc, $this->nodes[$id]['obj']);
                }
            }
        }
        foreach ($this->win->surfaceWebViews() as $sv) {
            if (!isset($grafted[spl_object_id($sv)]) && $sv->accessParent !== null) {
                $sv->accessParent = null;
                if (($acc = self::pageAccessible($sv)) !== null) { self::$f->atk_object_set_parent($acc, null); }
            }
        }
    }

    /** The accessible of a web view, the AtkSocket its page's tree is embedded in, or null. */
    private static function pageAccessible(SurfaceWebView $sv): ?CData
    {
        $acc = self::$f->gtk_widget_get_accessible($sv->widget());
        return $acc !== null && self::$f->g_type_check_instance_is_a($acc, self::$socketType) ? self::$f->cast('void*', $acc) : null;
    }

    // ---- the C functions --------------------------------------------------

    /** The node of ATK object $obj and its tree, or null. @return ?array{0: self, 1: int} */
    private static function node(CData $obj): ?array
    {
        $at = self::$objects[Mem::addr($obj)] ?? null;
        return $at !== null && isset($at[0]->nodes[$at[1]]) ? $at : null;
    }

    private static function u32(int $v): CData { $c = self::$f->new('unsigned int'); $c->cdata = $v; return $c; }

    private static function i32(int $v): CData { $c = self::$f->new('int'); $c->cdata = $v; return $c; }

    /** A C function of holder type $type running $fn, held for as long as the process. */
    private static function fn(string $type, Closure $fn): CData
    {
        $h = self::$f->new($type, false);
        $h->fn = $fn;
        self::$held[] = $h;
        return self::$f->cast('void*', $h->fn);
    }

    /** Writes C function $fn at $offset of the structure at $p. */
    private static function slot(CData $p, int $offset, ?CData $fn): void
    {
        $f = self::$f;
        $at = $f->cast('void**', $f->cast('char*', $p) + $offset);
        $at[0] = $fn;
    }

    /** @param array<int, CData> $fns by their index among the interface's functions */
    private static function setIface(CData $iface, array $fns): void
    {
        foreach ($fns as $i => $fn) { self::slot($iface, self::IFACE_HEADER + $i * 8, $fn); }
    }

    private static function setInt(?CData $p, int $v): void
    {
        if ($p !== null) { self::$f->cast('int*', $p)[0] = $v; }
    }

    /** A copy of $s GLib frees. */
    private static function cString(string $s): CData
    {
        return self::$f->g_strdup($s);
    }

    /** @param array<string, string> $attrs @return ?CData an AtkAttributeSet ATK frees */
    private static function attributeSet(array $attrs): ?CData
    {
        $f = self::$f;
        $list = null;
        foreach ($attrs as $name => $value) {
            $attr = $f->cast('void**', $f->g_malloc0(16)); // AtkAttribute: name, value
            $attr[0] = $f->cast('void*', self::cString($name));
            $attr[1] = $f->cast('void*', self::cString($value));
            $list = $f->g_slist_append($list, $attr);
        }
        return $list;
    }

    /** @return array{0: int, 1: int} a range of characters of ATK, whose end is -1 for the end, within $n */
    private static function span(int $start, int $end, int $n): array
    {
        if ($end < 0 || $end > $n) { $end = $n; }
        $a = max(0, min($start, $n));
        return [$a, max($a, $end)];
    }

    /** @param list<string> $r @return array{0: int, 1: int} the character, word or line at $offset, for granularities 0, 1 and above */
    private static function segment(array $r, int $offset, int $granularity): array
    {
        $n = count($r);
        $offset = max(0, min($offset, $n));
        $space = fn(string $c): bool => preg_match('/^\s$/u', $c) === 1;
        if ($granularity === 0) { return [$offset, min($offset + 1, $n)]; }
        if ($granularity === 1) {
            $start = $offset;
            while ($start > 0 && $space($r[$start - 1]) && ($start === $n || $space($r[$start]))) { $start--; } // between words: the one before
            while ($start > 0 && !$space($r[$start - 1])) { $start--; }
            $end = $start;
            while ($end < $n && !$space($r[$end])) { $end++; }
            while ($end < $n && $space($r[$end])) { $end++; }
            return [$start, $end];
        }
        [$start, $end] = [$offset, $offset];
        while ($start > 0 && $r[$start - 1] !== "\n") { $start--; }
        while ($end < $n && $r[$end] !== "\n") { $end++; }
        return [$start, min($end + 1, $n)];
    }

    /** The C functions of the types, made once. @return array<string, CData> the class and interface initializers */
    private static function callbacks(bool $scrolls): array
    {
        $f = self::$f;
        $g = Gtk::ffi();
        $cb = [];
        $cb['areaClass'] = self::fn('HClassInit', function ($class, $data) use ($f): void {
            $f->gtk_widget_class_set_accessible_type($class, self::$rootType);
            $f->gtk_widget_class_set_accessible_role($class, self::$atkRole['panel']);
        });
        $rootChildren = self::fn('HInt', fn($root): int => count(self::rootTree($root)?->top ?? []));
        $rootChild = self::fn('HPtrInt', function ($root, int $i) use ($f): ?CData {
            $t = self::rootTree($root);
            return $t !== null && $i >= 0 && $i < count($t->top) ? $f->g_object_ref($t->nodes[$t->top[$i]]['obj']) : null;
        });
        // In the overlay holding web views, the area is its only child.
        $rootIndex = self::fn('HInt', function ($root) use ($f, $g): int {
            $area = self::$f->gtk_accessible_get_widget($root);
            $t = $area === null ? null : (self::$byArea[Mem::addr($area)] ?? null);
            if ($t !== null && $t->win->layered() && self::$overlayType !== 0) { return 0; }
            return (self::$rootParentIndex)($root);
        });
        $cb['rootClass'] = self::fn('HClassInit', function ($class, $data) use ($f, $rootChildren, $rootChild, $rootIndex): void {
            self::slot($class, self::GET_N_CHILDREN, $rootChildren);
            self::slot($class, self::REF_CHILD, $rootChild);
            $was = $f->cast('HIntFn*', $f->cast('char*', $class) + self::GET_INDEX_IN_PARENT);
            self::$rootParentIndex = $f->new('HIntFn', false);
            FFI::memcpy(self::$rootParentIndex, $was, FFI::sizeof(self::$rootParentIndex)); // GtkWidgetAccessible's, which the root's calls
            self::$held[] = self::$rootParentIndex;
            self::$rootParentIndex = self::$rootParentIndex->fn;
            self::slot($class, self::GET_INDEX_IN_PARENT, $rootIndex);
        });
        $overlayChildren = self::fn('HInt', fn($obj): int => self::layersOf(self::$f->gtk_accessible_get_widget($obj)) !== null ? 1 : 0);
        $overlayChild = self::fn('HPtrInt', function ($obj, int $i) use ($f, $g): ?CData {
            $t = self::layersOf(self::$f->gtk_accessible_get_widget($obj));
            return $t !== null && $i === 0 ? $f->g_object_ref(self::$f->gtk_widget_get_accessible($t->area)) : null;
        });
        $cb['overlayAccessClass'] = self::fn('HClassInit', function ($class, $data) use ($overlayChildren, $overlayChild): void {
            self::slot($class, self::GET_N_CHILDREN, $overlayChildren);
            self::slot($class, self::REF_CHILD, $overlayChild);
        });
        $cb['overlayClass'] = self::fn('HClassInit', function ($class, $data) use ($f): void { $f->gtk_widget_class_set_accessible_type($class, self::$overlayAccessType); });
        $rootAt = self::fn('HAt', function ($root, int $x, int $y, int $coords): ?CData {
            $t = self::rootTree($root);
            return $t?->at($t->top, $x, $y, $coords);
        });
        $cb['rootComponent'] = self::fn('HClassInit', fn($iface, $data) => self::setIface($iface, [2 => $rootAt])); // ref_accessible_at_point; the rest is GtkWidgetAccessible's

        $children = self::fn('HInt', function ($obj): int {
            if (($at = self::node($obj)) === null) { return 0; }
            [$t, $id] = $at;
            return $t->nodes[$id]['page'] !== null ? 1 : count($t->nodes[$id]['children']);
        });
        $child = self::fn('HPtrInt', function ($obj, int $i) use ($f): ?CData {
            if (($at = self::node($obj)) === null) { return null; }
            [$t, $id] = $at;
            if ($t->nodes[$id]['page'] !== null) { return $i === 0 ? $f->g_object_ref($t->nodes[$id]['page']) : null; }
            $kids = $t->nodes[$id]['children'];
            return $i >= 0 && $i < count($kids) ? $f->g_object_ref($t->nodes[$kids[$i]]['obj']) : null;
        });
        $index = self::fn('HInt', function ($obj): int {
            if (($at = self::node($obj)) === null) { return -1; }
            [$t, $id] = $at;
            $parent = $t->nodes[$id]['parent'];
            $i = array_search($id, $parent === 0 ? $t->top : $t->nodes[$parent]['children'], true);
            return $i === false ? -1 : $i;
        });
        $states = self::fn('HPtr', function ($obj) use ($f): CData {
            $set = $f->atk_state_set_new();
            if (($at = self::node($obj)) === null) {
                $f->atk_state_set_add_state($set, self::$st['defunct']);
                return $set;
            }
            [$t, $id] = $at;
            foreach (self::stateList($t->nodes[$id]['n'], $id === $t->focus) as $s) { $f->atk_state_set_add_state($set, $s); }
            return $set;
        });
        // Object attributes: the placeholder of a text field, and the place of a row among its list's, which Orca reads as "5 of 10000".
        $attributes = self::fn('HPtr', function ($obj): ?CData {
            if (($at = self::node($obj)) === null) { return null; }
            $n = $at[0]->nodes[$at[1]]['n'];
            $a = [];
            if ($n->placeholder !== '') { $a['placeholder-text'] = $n->placeholder; }
            if ($n->posInSet > 0) { $a['posinset'] = (string)$n->posInSet; }
            if ($n->setSize > 0) { $a['setsize'] = (string)$n->setSize; }
            if ($n->level > 0) { $a['level'] = (string)$n->level; }
            if (($n->states & AccessNode::SORT_ASCENDING) !== 0) { $a['sort'] = 'ascending'; } elseif (($n->states & AccessNode::SORT_DESCENDING) !== 0) { $a['sort'] = 'descending'; } // as Chromium says
            return self::attributeSet($a);
        });
        $cb['nodeClass'] = self::fn('HClassInit', function ($class, $data) use ($children, $child, $index, $states, $attributes): void {
            self::slot($class, self::GET_N_CHILDREN, $children);
            self::slot($class, self::REF_CHILD, $child);
            self::slot($class, self::GET_INDEX_IN_PARENT, $index);
            self::slot($class, self::REF_STATE_SET, $states);
            self::slot($class, self::GET_ATTRIBUTES, $attributes);
        });

        $contains = self::fn('HContains', fn($obj, int $x, int $y, int $coords): int => ($at = self::node($obj)) !== null && $at[0]->contains($at[1], $x, $y, $coords) ? 1 : 0);
        $nodeAt = self::fn('HAt', function ($obj, int $x, int $y, int $coords) use ($f): ?CData {
            if (($at = self::node($obj)) === null) { return null; }
            [$t, $id] = $at;
            if ($t->nodes[$id]['page'] !== null) { return $f->g_object_ref($t->nodes[$id]['page']); }
            return $t->at($t->nodes[$id]['children'], $x, $y, $coords);
        });
        $extents = self::fn('HExtents', function ($obj, $px, $py, $pw, $ph, int $coords): void {
            if (($at = self::node($obj)) === null) { return; }
            [$x, $y, $w, $h] = $at[0]->extents($at[1], $coords);
            self::setInt($px, $x); self::setInt($py, $y); self::setInt($pw, $w); self::setInt($ph, $h);
        });
        $grabFocus = self::fn('HInt', function ($obj) use ($g): int {
            if (($at = self::node($obj)) === null || ($at[0]->nodes[$at[1]]['n']->actions & AccessNode::ACTION_FOCUS) === 0) { return 0; }
            self::$f->gtk_widget_grab_focus($at[0]->area);
            $at[0]->act($at[1], AccessNode::FOCUS);
            return 1;
        });
        // What assistive technology moves to out of view scrolls into view.
        $scrollTo = self::fn('HIntInt', function ($obj, int $how): int {
            if (($at = self::node($obj)) === null || ($at[0]->nodes[$at[1]]['n']->actions & AccessNode::ACTION_SCROLL_INTO_VIEW) === 0) { return 0; }
            $at[0]->act($at[1], AccessNode::SCROLL_INTO_VIEW);
            return 1;
        });
        $cb['component'] = self::fn('HClassInit', fn($iface, $data) => self::setIface($iface, [1 => $contains, 2 => $nodeAt, 3 => $extents, 6 => $grabFocus] + ($scrolls ? [15 => $scrollTo] : []))); // scroll_to arrived in ATK 2.30, past the end of the interface before

        // The actions of a node: click, then expand or contract, for an item of a tree with children, as GTK's tree views have.
        $actions = function ($obj): array {
            if (($at = self::node($obj)) === null) { return [null, []]; }
            $n = $at[0]->nodes[$at[1]]['n'];
            return [$at, array_values(array_filter([AccessNode::ACTION_PRESS, AccessNode::ACTION_EXPAND], fn(int $a) => ($n->actions & $a) !== 0))];
        };
        $action = function ($obj, int $i) use ($actions): array {
            [$at, $list] = $actions($obj);
            return $i < 0 || $i >= count($list) ? [null, 0] : [$at, $list[$i]];
        };
        $doAction = self::fn('HIntInt', function ($obj, int $i) use ($action): int {
            [$at, $a] = $action($obj, $i);
            if ($at === null) { return 0; }
            [$t, $id] = $at;
            $t->act($id, match (true) {
                $a === AccessNode::ACTION_PRESS => AccessNode::PRESS,
                ($t->nodes[$id]['n']->states & AccessNode::EXPANDED) !== 0 => AccessNode::COLLAPSE,
                default => AccessNode::EXPAND,
            });
            return 1;
        });
        $nActions = self::fn('HInt', fn($obj): int => count($actions($obj)[1]));
        $clickName = self::$f->new('char[6]', false); FFI::memcpy($clickName, "click\0", 6); self::$held[] = $clickName;
        $expandName = self::$f->new('char[19]', false); FFI::memcpy($expandName, "expand or contract\0", 19); self::$held[] = $expandName;
        $emptyName = self::$f->new('char[1]', false); self::$held[] = $emptyName;
        $actionName = self::fn('HPtrInt', fn($obj, int $i): ?CData => match ($action($obj, $i)[1]) {
            AccessNode::ACTION_PRESS => self::$f->cast('void*', FFI::addr($clickName[0])),
            AccessNode::ACTION_EXPAND => self::$f->cast('void*', FFI::addr($expandName[0])),
            default => null,
        });
        $actionEmpty = self::fn('HPtrInt', fn($obj, int $i): ?CData => $action($obj, $i)[0] !== null ? self::$f->cast('void*', FFI::addr($emptyName[0])) : null);
        // do_action, get_n_actions, get_description, get_name, get_keybinding and get_localized_name.
        $cb['action'] = self::fn('HClassInit', fn($iface, $data) => self::setIface($iface, [0 => $doAction, 1 => $nActions, 2 => $actionEmpty, 3 => $actionName, 4 => $actionEmpty, 6 => $actionName]));

        $valueOf = fn(Closure $pick): CData => self::fn('HValue', function ($obj, $gvalue) use ($f, $pick): void {
            if (($at = self::node($obj)) === null) { return; }
            $f->g_value_init($gvalue, self::G_TYPE_DOUBLE);
            $f->g_value_set_double($gvalue, $pick($at[0]->nodes[$at[1]]['n']));
        });
        $current = $valueOf(fn(AccessNode $n) => max($n->now, $n->min));
        $maximum = $valueOf(fn(AccessNode $n) => $n->max);
        $minimum = $valueOf(fn(AccessNode $n) => $n->min);
        // The step, through the GValue of get_minimum_increment: AT-SPI's bridge takes it for get_increment, which returns a double.
        $increment = $valueOf(fn(AccessNode $n) => $n->step);
        $valueAndText = self::fn('HValueText', function ($obj, $value, $text) use ($f): void {
            if (($at = self::node($obj)) !== null && $value !== null) { $n = $at[0]->nodes[$at[1]]['n']; $f->cast('double*', $value)[0] = max($n->now, $n->min); }
            if ($text !== null) { $f->cast('void**', $text)[0] = null; }
        });
        $valueRange = self::fn('HPtr', fn($obj): ?CData => ($at = self::node($obj)) !== null ? $f->atk_range_new($at[0]->nodes[$at[1]]['n']->min, $at[0]->nodes[$at[1]]['n']->max, null) : null);
        // Values change as the arrow keys change them.
        $setValue = self::fn('HSetValue', function ($obj, float $v): void {
            if (($at = self::node($obj)) === null) { return; }
            $n = $at[0]->nodes[$at[1]]['n'];
            if (($n->actions & AccessNode::ACTION_INCREMENT) === 0) { return; }
            if ($v > $n->now) { $at[0]->act($at[1], AccessNode::INCREMENT); } elseif ($v < $n->now) { $at[0]->act($at[1], AccessNode::DECREMENT); }
        });
        // get_current_value, get_maximum_value, get_minimum_value, get_minimum_increment, get_value_and_text, get_range and set_value.
        $cb['value'] = self::fn('HClassInit', fn($iface, $data) => self::setIface($iface, [0 => $current, 1 => $maximum, 2 => $minimum, 4 => $increment, 5 => $valueAndText, 6 => $valueRange, 9 => $setValue]));

        $getText = self::fn('HText', function ($obj, int $start, int $end): ?CData {
            if (($at = self::node($obj)) === null) { return null; }
            $r = $at[0]->text($at[1]);
            [$a, $b] = self::span($start, $end, count($r));
            return self::cString(implode('', array_slice($r, $a, $b - $a)));
        });
        $charAt = self::fn('HCharAt', function ($obj, int $offset): int {
            if (($at = self::node($obj)) === null) { return 0; }
            $r = $at[0]->text($at[1]);
            return $offset >= 0 && $offset < count($r) ? mb_ord($r[$offset]) : 0;
        });
        $caret = self::fn('HInt', fn($obj): int => ($at = self::node($obj)) !== null && $at[0]->nodes[$at[1]]['type'] === self::$editableType ? $at[0]->nodes[$at[1]]['n']->selEnd : -1);
        $count = self::fn('HInt', fn($obj): int => ($at = self::node($obj)) !== null ? count($at[0]->text($at[1])) : 0);
        $selections = self::fn('HInt', fn($obj): int => ($at = self::node($obj)) !== null && $at[0]->nodes[$at[1]]['n']->selStart !== $at[0]->nodes[$at[1]]['n']->selEnd ? 1 : 0);
        $selection = self::fn('HSelection', function ($obj, int $i, $start, $end): ?CData {
            $at = self::node($obj);
            $n = $at === null ? null : $at[0]->nodes[$at[1]]['n'];
            if ($n === null || $i !== 0 || $n->selStart === $n->selEnd) { self::setInt($start, 0); self::setInt($end, 0); return null; }
            $r = $at[0]->text($at[1]);
            [$a, $b] = self::span($n->selStart, $n->selEnd, count($r));
            self::setInt($start, $a); self::setInt($end, $b);
            return self::cString(implode('', array_slice($r, $a, $b - $a)));
        });
        $stringAt = function ($obj, int $offset, int $granularity, $start, $end): ?CData {
            if (($at = self::node($obj)) === null) { return null; }
            $r = $at[0]->text($at[1]);
            [$a, $b] = self::segment($r, $offset, $granularity);
            self::setInt($start, $a); self::setInt($end, $b);
            return self::cString(implode('', array_slice($r, $a, $b - $a)));
        };
        $stringAtOffset = self::fn('HStringAt', fn($obj, int $offset, int $granularity, $start, $end): ?CData => $stringAt($obj, $offset, $granularity, $start, $end));
        // Boundaries of the older text_at_offset: characters, the starts and ends of words, sentences and lines.
        $textAtOffset = self::fn('HStringAt', fn($obj, int $offset, int $boundary, $start, $end): ?CData => $stringAt($obj, $offset, [0, 1, 1, 3, 3, 3, 3][max(0, min($boundary, 6))], $start, $end));
        // The formats of the text, in ATK's text attributes, which Orca reads: the whole text one run, myphp's tree telling no runs.
        $defaults = ['weight' => '400', 'style' => 'normal', 'underline' => 'none', 'strikethrough' => 'false'];
        $runAttributes = self::fn('HRunAttributes', function ($obj, int $offset, $start, $end) use ($defaults): ?CData {
            if (($at = self::node($obj)) === null) { self::setInt($start, 0); self::setInt($end, 0); return null; }
            self::setInt($start, 0); self::setInt($end, count($at[0]->text($at[1])));
            return self::attributeSet($defaults);
        });
        $defaultAttributes = self::fn('HPtr', fn($obj): ?CData => self::attributeSet($defaults));
        // get_text, get_text_at_offset, get_character_at_offset, get_caret_offset, get_run_attributes, get_default_attributes,
        // get_character_count, get_n_selections, get_selection and get_string_at_offset.
        $cb['text'] = self::fn('HClassInit', fn($iface, $data) => self::setIface($iface, [0 => $getText, 2 => $textAtOffset, 3 => $charAt, 5 => $caret, 6 => $runAttributes,
            7 => $defaultAttributes, 9 => $count, 11 => $selections, 12 => $selection, 23 => $stringAtOffset]));

        // The rows a list chooses, which choosing a row as a click does changes: it chooses that row alone.
        $childAt = function ($obj, int $i): ?array {
            if (($at = self::node($obj)) === null) { return null; }
            $kids = $at[0]->nodes[$at[1]]['children'];
            return $i >= 0 && $i < count($kids) ? [$at[0], $kids[$i]] : null;
        };
        $addSelection = self::fn('HIntInt', function ($obj, int $i) use ($childAt): int {
            $c = $childAt($obj, $i);
            if ($c === null) { return 0; }
            $n = $c[0]->nodes[$c[1]]['n'];
            if (!self::chooses($n) || ($n->actions & AccessNode::ACTION_PRESS) === 0) { return 0; }
            $c[0]->act($c[1], AccessNode::PRESS);
            return 1;
        });
        $refuse = self::fn('HInt', fn($obj): int => 0);
        $refuseAt = self::fn('HIntInt', fn($obj, int $i): int => 0);
        $refSelection = self::fn('HPtrInt', function ($obj, int $i) use ($f): ?CData {
            if (($at = self::node($obj)) === null) { return null; }
            $rows = $at[0]->chosenRows($at[1]);
            return $i >= 0 && $i < count($rows) ? $f->g_object_ref($at[0]->nodes[$rows[$i]]['obj']) : null;
        });
        $selectionCount = self::fn('HInt', fn($obj): int => ($at = self::node($obj)) !== null ? count($at[0]->chosenRows($at[1])) : 0);
        $isSelected = self::fn('HIntInt', function ($obj, int $i) use ($childAt): int {
            $c = $childAt($obj, $i);
            return $c !== null && self::chooses($n = $c[0]->nodes[$c[1]]['n']) && ($n->states & AccessNode::CHECKED) !== 0 ? 1 : 0;
        });
        // add_selection, clear_selection, ref_selection, get_selection_count, is_child_selected, remove_selection and select_all_selection.
        $cb['selection'] = self::fn('HClassInit', fn($iface, $data) => self::setIface($iface, [0 => $addSelection, 1 => $refuse, 2 => $refSelection, 3 => $selectionCount,
            4 => $isSelected, 5 => $refuseAt, 6 => $refuse]));

        $editable = function ($obj): ?array {
            $at = self::node($obj);
            return $at !== null && ($at[0]->nodes[$at[1]]['n']->actions & AccessNode::ACTION_SET_VALUE) !== 0 ? $at : null;
        };
        $setContents = self::fn('HSetText', function ($obj, $text) use ($editable): void {
            if (($at = $editable($obj)) !== null) { $at[0]->act($at[1], AccessNode::SET_VALUE, FFI::string(self::$f->cast('char*', $text))); }
        });
        $insert = self::fn('HInsert', function ($obj, $text, int $length, $position) use ($editable): void {
            $at = $editable($obj);
            if ($at === null || $position === null) { return; }
            $s = $length < 0 ? FFI::string(self::$f->cast('char*', $text)) : FFI::string(self::$f->cast('char*', $text), $length);
            $r = $at[0]->text($at[1]);
            $pos = max(0, min(self::$f->cast('int*', $position)[0], count($r)));
            $at[0]->act($at[1], AccessNode::SET_VALUE, implode('', array_slice($r, 0, $pos)) . $s . implode('', array_slice($r, $pos)));
            self::setInt($position, $pos + mb_strlen($s));
        });
        $remove = self::fn('HRemove', function ($obj, int $start, int $end) use ($editable): void {
            if (($at = $editable($obj)) === null) { return; }
            $r = $at[0]->text($at[1]);
            [$a, $b] = self::span($start, $end, count($r));
            $at[0]->act($at[1], AccessNode::SET_VALUE, implode('', array_slice($r, 0, $a)) . implode('', array_slice($r, $b)));
        });
        // set_text_contents, insert_text and delete_text.
        $cb['editable'] = self::fn('HClassInit', fn($iface, $data) => self::setIface($iface, [1 => $setContents, 2 => $insert, 5 => $remove]));
        return $cb;
    }

    /** The tree of the window whose web views overlay $overlay holds, or null. */
    private static function layersOf(?CData $overlay): ?self
    {
        if ($overlay === null) {
            return null;
        }
        foreach (self::$byArea as $t) {
            if ($t->win->layers() !== null && Mem::addr($t->win->layers()) === Mem::addr($overlay)) { return $t; }
        }
        return null;
    }

    private const HEADER = <<<'C'
        typedef unsigned long GType;
        typedef struct { GType type; const char *type_name; unsigned int class_size; unsigned int instance_size; } GTypeQuery;
        typedef struct { void (*interface_init)(void*, void*); void (*interface_finalize)(void*, void*); void *data; } GInterfaceInfo;
        typedef struct { void (*fn)(void*, void*); } HClassInit;
        typedef struct { int (*fn)(void*); } HInt;
        typedef struct { int (*fn)(void*); } HIntFn;
        typedef struct { int (*fn)(void*, int); } HIntInt;
        typedef struct { void* (*fn)(void*); } HPtr;
        typedef struct { void* (*fn)(void*, int); } HPtrInt;
        typedef struct { void* (*fn)(void*, int, int, int); } HAt;
        typedef struct { int (*fn)(void*, int, int, int); } HContains;
        typedef struct { void (*fn)(void*, void*, void*, void*, void*, int); } HExtents;
        typedef struct { void (*fn)(void*, void*); } HValue;
        typedef struct { void (*fn)(void*, void*, void*); } HValueText;
        typedef struct { void (*fn)(void*, double); } HSetValue;
        typedef struct { void* (*fn)(void*, int, int); } HText;
        typedef struct { unsigned int (*fn)(void*, int); } HCharAt;
        typedef struct { void* (*fn)(void*, int, void*, void*); } HSelection;
        typedef struct { void* (*fn)(void*, int, int, void*, void*); } HStringAt;
        typedef struct { void* (*fn)(void*, int, void*, void*); } HRunAttributes;
        typedef struct { void (*fn)(void*, void*); } HSetText;
        typedef struct { void (*fn)(void*, void*, int, void*); } HInsert;
        typedef struct { void (*fn)(void*, int, int); } HRemove;
        unsigned int atk_get_major_version(void);
        unsigned int atk_get_minor_version(void);
        GType g_type_register_static_simple(GType parent, const char *name, unsigned int class_size, void *class_init, unsigned int instance_size, void *instance_init, int flags);
        void g_type_add_interface_static(GType type, GType iface, const GInterfaceInfo *info);
        void g_type_query(GType type, GTypeQuery *query);
        void* g_object_new(GType type, const char *first, ...);
        void* g_object_ref(void*);
        void g_object_unref(void*);
        void g_object_notify(void*, const char*);
        void* g_value_init(void*, GType);
        void g_value_set_double(void*, double);
        char* g_strdup(const char*);
        void* g_malloc0(size_t);
        void* g_slist_append(void*, void*);
        void g_signal_emit_by_name(void*, const char*, ...);
        unsigned int g_signal_lookup(const char*, GType);
        GType gtk_widget_accessible_get_type(void);
        GType gtk_gl_area_get_type(void);
        GType gtk_overlay_get_type(void);
        GType gtk_container_accessible_get_type(void);
        void gtk_widget_class_set_accessible_type(void*, GType);
        void gtk_widget_class_set_accessible_role(void*, int);
        void* gtk_accessible_get_widget(void*);
        void* gtk_widget_get_accessible(void*);
        void gtk_widget_grab_focus(void*);
        int g_type_check_instance_is_a(void*, GType);
        void* gtk_gl_area_new(void);
        void* gtk_overlay_new(void);
        GType atk_object_get_type(void);
        GType atk_component_get_type(void);
        GType atk_action_get_type(void);
        GType atk_value_get_type(void);
        GType atk_text_get_type(void);
        GType atk_editable_text_get_type(void);
        GType atk_selection_get_type(void);
        GType atk_socket_get_type(void);
        int atk_role_for_name(const char*);
        int atk_state_type_for_name(const char*);
        void atk_object_set_name(void*, const char*);
        void atk_object_set_description(void*, const char*);
        void atk_object_set_role(void*, int);
        void atk_object_set_parent(void*, void*);
        void atk_object_notify_state_change(void*, uint64_t, int);
        void* atk_state_set_new(void);
        int atk_state_set_add_state(void*, int);
        void* atk_range_new(double, double, const char*);
        void atk_component_get_extents(void*, int*, int*, int*, int*, int);
        C;
}
