<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use MyPHP\UI\{AccessNode, AccessTree, Input, Role};

/**
 * VoiceOver and other assistive technology see the content of a view as
 * NSAccessibilityElements, one per node of the frame's tree (AccessTree),
 * which the view contains (mygo's internal/darwin/access.go). Elements are
 * kept from frame to frame by node ID, so that VoiceOver's cursor stays on
 * them, and only what changed is set again. The view's content describes
 * its frames once AppKit first asks it for its children. Lists are tables
 * of rows, as AppKit's own lists, and VoiceOver reads their rows "row 5
 * of 10,000" from their index among all their table's (AXIndex) and how
 * many it has (AXRowCount), rather than from the rows built, as a list
 * builds only those in view; the table says which rows show
 * (AXVisibleRows) and which are chosen (AXSelectedRows). What is in a
 * scroll container takes AXScrollToVisible.
 */
final class Access
{
    /** The roles and subroles of node roles. */
    private const ROLES = [
        Role::GROUP => ['AXGroup', ''], Role::TEXT => ['AXStaticText', ''], Role::BUTTON => ['AXButton', ''], Role::LINK => ['AXLink', ''],
        Role::CHECK_BOX => ['AXCheckBox', ''], Role::RADIO => ['AXRadioButton', ''], Role::SWITCH => ['AXCheckBox', 'AXSwitch'],
        Role::SLIDER => ['AXSlider', ''], Role::PROGRESS => ['AXProgressIndicator', ''], Role::TEXT_FIELD => ['AXTextField', ''],
        Role::IMAGE => ['AXImage', ''], Role::LIST => ['AXTable', ''], Role::SCROLL => ['AXScrollArea', ''], Role::DIALOG => ['AXGroup', 'AXDialog'],
        Role::POPUP => ['AXPopover', ''], Role::TOOLTIP => ['AXHelpTag', ''], Role::POP_UP_BUTTON => ['AXPopUpButton', ''],
        Role::TAB_LIST => ['AXTabGroup', ''], Role::TAB => ['AXRadioButton', 'AXTabButton'], Role::SPLITTER => ['AXSplitter', ''],
        Role::STATUS => ['AXGroup', 'AXApplicationStatus'], Role::TABLE => ['AXTable', ''], Role::ROW => ['AXRow', 'AXTableRow'],
        Role::CELL => ['AXCell', ''], Role::COLUMN_HEADER => ['AXCell', ''], Role::TREE => ['AXOutline', ''], Role::TREE_ITEM => ['AXRow', 'AXOutlineRow'],
        Role::LIST_ITEM => ['AXRow', 'AXTableRow'], Role::MENU_BUTTON => ['AXMenuButton', ''], Role::TOOLBAR => ['AXToolbar', ''],
        Role::RADIO_GROUP => ['AXRadioGroup', ''], Role::TOGGLE_BUTTON => ['AXCheckBox', 'AXToggle'], Role::COMBO_BOX => ['AXComboBox', ''],
        Role::DISCLOSURE => ['AXDisclosureTriangle', ''], Role::METER => ['AXLevelIndicator', ''], Role::STEPPER => ['AXIncrementor', ''],
        Role::COLOR_WELL => ['AXColorWell', ''], Role::ALERT_DIALOG => ['AXGroup', 'AXApplicationAlertDialog'], Role::MENU => ['AXMenu', ''],
        Role::MENU_BAR => ['AXMenuBar', ''],
        // AppKit's items show a choice with a mark (AXMenuItemMarkChar).
        Role::MENU_ITEM => ['AXMenuItem', ''], Role::MENU_ITEM_CHECK_BOX => ['AXMenuItem', ''], Role::MENU_ITEM_RADIO => ['AXMenuItem', ''],
        // WebKit's headings, whose value is their level.
        Role::HEADING => ['AXHeading', ''],
    ];

    /** The actions of nodes by their names in AppKit's older API: AXScrollToVisible is NSAccessibilityScrollToVisibleAction, which AppKit exports only since macOS 26. */
    private const LEGACY_ACTIONS = [
        'AXPress' => [AccessNode::ACTION_PRESS, AccessNode::PRESS], 'AXIncrement' => [AccessNode::ACTION_INCREMENT, AccessNode::INCREMENT],
        'AXDecrement' => [AccessNode::ACTION_DECREMENT, AccessNode::DECREMENT], 'AXScrollToVisible' => [AccessNode::ACTION_SCROLL_INTO_VIEW, AccessNode::SCROLL_INTO_VIEW],
    ];

    /** @var array<int, self> the surfaces by view address */
    private static array $surfaces = [];
    /** @var array<int, array{0: self, 1: int}> the elements by address: their surface and node ID */
    private static array $byObj = [];
    private static bool $defined = false;
    /** NSAccessibilityPostNotification, NSAccessibilityPostNotificationWithUserInfo and NSAccessibilityActionDescription. */
    private static $postNote, $postInfo, $actionDescription;

    /** @var array<int, array{obj: mixed, node: AccessNode, parent: int, children: list<int|string>, chosen: list<int>, count: int}> the elements by node ID */
    private array $elements = [];
    /** The top elements, retained. */
    private $topLevel = null;
    /** The last tree, without its announcements. */
    private AccessTree $tree;
    private bool $on = false;
    /** Set while the elements are being set, which their setters then take as no action of the user's. */
    private bool $updating = false;

    private function __construct(private $view, private Input $input)
    {
        $this->tree = new AccessTree;
    }

    /** Makes the view of $input the container of the elements of its frames. */
    public static function attach($view, Input $input): void
    {
        self::define();
        self::$surfaces[ObjC::addr($view)] = new self($view, $input);
    }

    /** The methods of the view that make it the container of the elements, for View's class. @return array<string, array> */
    public static function viewMethods(): array
    {
        $surface = fn($view) => self::$surfaces[ObjC::addr($view)] ?? null;
        return [
            'isAccessibilityElement' => ['B@:', 'b(pp)', fn($self, $cmd) => false],
            'accessibilityChildren' => ['@@:', 'p(pp)', function ($self, $cmd) use ($surface) {
                $s = $surface($self);
                if ($s === null) { return null; }
                $s->requested();
                return $s->topLevel ?? ObjC::msg('p:', ObjC::cls('NSArray'), 'array');
            }],
            'accessibilityHitTest:' => ['@@:{CGPoint=dd}', 'p(ppq)', function ($self, $cmd, $p) use ($surface) {
                $s = $surface($self);
                if ($s === null) { return $self; }
                $s->requested();
                $inWindow = ObjC::msg('q:q', ObjC::msg('p:', $self, 'window'), 'convertPointFromScreen:', $p);
                $v = ObjC::msg('q:qp', $self, 'convertPoint:fromView:', $inWindow, null);
                return $s->at($v->x, $v->y) ?? $self;
            }],
            'accessibilityFocusedUIElement' => ['@@:', 'p(pp)', function ($self, $cmd) use ($surface) {
                $s = $surface($self);
                if ($s === null) { return $self; }
                $s->requested();
                return $s->elements[$s->tree->focus]['obj'] ?? $self;
            }],
        ];
    }

    /** Gives the frame's tree of the view to AppKit. */
    public static function update($view, AccessTree $tree): void
    {
        $s = self::$surfaces[ObjC::addr($view)] ?? null;
        $s?->apply($tree);
    }

    /** Releases the elements of the view, which is going (mygo's destroyAccess). */
    public static function detach($view): void
    {
        $s = self::$surfaces[ObjC::addr($view)] ?? null;
        if ($s === null) { return; }
        unset(self::$surfaces[ObjC::addr($view)]);
        foreach ($s->elements as $id => $el) { $s->drop($id); }
        if ($s->topLevel !== null) { ObjC::msg('v:', $s->topLevel, 'release'); }
    }

    /** Starts the content describing its frames, the first time AppKit asks about the view. */
    private function requested(): void
    {
        if ($this->on) { return; }
        $this->on = true;
        $this->input->push(['accessOn']);
        Metal::requestFrame($this->view);
    }

    /** Sends an action of assistive technology to the content, unless it comes of the elements being set. */
    private function act(int $id, int $action, string $text = '', int $from = 0, int $to = 0): bool
    {
        if ($this->updating) { return false; }
        $this->input->push(['access', $id, $action, $text, $from, $to]);
        Metal::requestFrame($this->view);
        return true;
    }

    /** The element of the node of $obj, an element, and its surface; null for one gone. @return ?array{0: self, 1: array} */
    private static function of($obj): ?array
    {
        [$s, $id] = self::$byObj[ObjC::addr($obj)] ?? [null, 0];
        return $s === null || !isset($s->elements[$id]) ? null : [$s, $s->elements[$id]];
    }

    private static function post($obj, string $name): void
    {
        if ($obj !== null) { (self::$postNote)($obj, ObjC::str($name)); }
    }

    /** Asks VoiceOver to read $text out, after what it is reading (NSAccessibilityAnnouncementRequestedNotification). */
    private static function announce(string $text): void
    {
        $info = ObjC::msg('p:', ObjC::cls('NSMutableDictionary'), 'dictionary');
        ObjC::msg('v:pp', $info, 'setObject:forKey:', ObjC::str($text), ObjC::str('AXAnnouncementKey'));
        ObjC::msg('v:pp', $info, 'setObject:forKey:', ObjC::msg('p:l', ObjC::cls('NSNumber'), 'numberWithLong:', 50), ObjC::str('AXPriorityKey')); // NSAccessibilityPriorityMedium
        (self::$postInfo)(ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication'), ObjC::str('AXAnnouncementRequested'), $info);
    }

    /** @param list<mixed> $objs */
    private static function array(array $objs)
    {
        $a = ObjC::msg('p:', ObjC::cls('NSMutableArray'), 'array');
        foreach ($objs as $o) { ObjC::msg('v:p', $a, 'addObject:', $o); }
        return $a;
    }

    private static function number(float $v) { return ObjC::msg('p:d', ObjC::cls('NSNumber'), 'numberWithDouble:', $v); }

    private static function int(int $v) { return ObjC::msg('p:l', ObjC::cls('NSNumber'), 'numberWithLong:', $v); }

    /** Whether nodes of a role are items of a menu. */
    private static function menuItem(int $r): bool
    {
        return $r === Role::MENU_ITEM || $r === Role::MENU_ITEM_CHECK_BOX || $r === Role::MENU_ITEM_RADIO;
    }

    /** The mark an item of a menu shows for its choice, as AppKit's: a check mark when on, a dash when mixed, none otherwise. */
    private static function markChar(AccessNode $n): string
    {
        return match (true) {
            !self::menuItem($n->role) => '',
            ($n->states & AccessNode::MIXED) !== 0 => '-',
            ($n->states & AccessNode::CHECKED) !== 0 => '✓',
            default => '',
        };
    }

    /** Whether a node is the row of a list, a table or an outline that its list chooses. */
    private static function chooses(AccessNode $n): bool
    {
        return in_array($n->role, [Role::LIST_ITEM, Role::ROW, Role::TREE_ITEM], true) && ($n->states & AccessNode::SELECTABLE) !== 0;
    }

    /** @return array{0: string, 1: string} */
    private static function roleOf(AccessNode $n): array
    {
        [$role, $subrole] = self::ROLES[$n->role];
        if (($n->states & AccessNode::SEGMENT) !== 0) { $subrole = 'AXSegment'; } // as NSSegmentedControl's
        if ($n->role === Role::TEXT_FIELD) {
            if (($n->states & AccessNode::SEARCH) !== 0) { $subrole = 'AXSearchField'; }
            elseif (($n->states & AccessNode::PASSWORD) !== 0) { $subrole = 'AXSecureTextField'; }
            elseif (($n->states & AccessNode::MULTILINE) !== 0) { $role = 'AXTextArea'; }
        }
        return [$role, $subrole];
    }

    /** Whether elements of a role show their name as a title. */
    private static function titled(int $r): bool
    {
        return in_array($r, [Role::BUTTON, Role::LINK, Role::CHECK_BOX, Role::RADIO, Role::SWITCH, Role::POP_UP_BUTTON, Role::TAB,
            Role::MENU_BUTTON, Role::TOGGLE_BUTTON, Role::MENU_ITEM, Role::MENU_ITEM_CHECK_BOX, Role::MENU_ITEM_RADIO], true);
    }

    /** Whether elements of a role edit text: text fields and combo boxes. */
    private static function textual(int $r): bool
    {
        return $r === Role::TEXT_FIELD || $r === Role::COMBO_BOX;
    }

    /** Whether elements of a role have their state as their value. */
    private static function toggle(int $r): bool
    {
        return in_array($r, [Role::CHECK_BOX, Role::RADIO, Role::SWITCH, Role::TAB, Role::TOGGLE_BUTTON], true);
    }

    /** The value of a node as AppKit wants it: a number for toggles, disclosures and ranges, a string for texts. */
    private static function valueOf(AccessNode $n)
    {
        return match (true) {
            // 1 while open, as AppKit's disclosure triangles.
            $n->role === Role::DISCLOSURE => self::int(($n->states & AccessNode::EXPANDED) !== 0 ? 1 : 0),
            self::toggle($n->role) => self::int(($n->states & AccessNode::MIXED) !== 0 ? 2 : (($n->states & AccessNode::CHECKED) !== 0 ? 1 : 0)),
            Role::ranged($n->role) => $n->now < $n->min ? null : self::number($n->now), // null: a progress of unknown length
            $n->role === Role::TEXT => ObjC::str($n->label),
            $n->role === Role::HEADING && $n->level > 0 => self::int($n->level),
            $n->value !== '' || self::textual($n->role) => ObjC::str($n->value),
            default => null,
        };
    }

    /** How many UTF-16 units $s has. */
    private static function units(string $s): int
    {
        return intdiv(strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')), 2);
    }

    /** The UTF-16 units $lo to $lo+$len of $s, within it. */
    private static function unitSlice(string $s, int $lo, int $len): string
    {
        $u = mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
        $lo = max(0, min($lo, intdiv(strlen($u), 2)));
        $hi = max($lo, min($lo + $len, intdiv(strlen($u), 2)));
        return mb_convert_encoding(substr($u, 2 * $lo, 2 * ($hi - $lo)), 'UTF-8', 'UTF-16LE');
    }

    private static function str(string $s) { return $s === '' ? null : ObjC::str($s); }

    /** Sets the properties of element $el's node that changed, all of them for a new element, and reports whether its value changed. */
    private function set(array &$el, AccessNode $n, bool $fresh): bool
    {
        $o = $el['node'];
        $el['node'] = $n;
        $obj = $el['obj'];
        [$r, $sr] = self::roleOf($n);
        if ($fresh || [$r, $sr] !== self::roleOf($o)) {
            ObjC::msg('v:p', $obj, 'setAccessibilityRole:', ObjC::str($r));
            ObjC::msg('v:p', $obj, 'setAccessibilitySubrole:', self::str($sr));
        }
        if ($fresh || $n->label !== $o->label || $n->role !== $o->role) {
            // Controls show their name, which AppKit's own give as their title; other elements are described by it.
            if (self::titled($n->role)) {
                ObjC::msg('v:p', $obj, 'setAccessibilityTitle:', ObjC::str($n->label));
                ObjC::msg('v:p', $obj, 'setAccessibilityLabel:', null);
            } else {
                ObjC::msg('v:p', $obj, 'setAccessibilityTitle:', null);
                ObjC::msg('v:p', $obj, 'setAccessibilityLabel:', ObjC::str($n->label));
            }
        }
        $changed = fn(int $bits) => ($n->states & $bits) !== ($o->states & $bits);
        if ($fresh || $changed(AccessNode::DISABLED)) { ObjC::msg('v:b', $obj, 'setAccessibilityEnabled:', ($n->states & AccessNode::DISABLED) === 0); }
        if ($fresh || $changed(AccessNode::EXPANDED)) { ObjC::msg('v:b', $obj, 'setAccessibilityExpanded:', ($n->states & AccessNode::EXPANDED) !== 0); }
        if ($n->role === Role::SLIDER && ($fresh || $changed(AccessNode::VERTICAL))) {
            ObjC::msg('v:l', $obj, 'setAccessibilityOrientation:', ($n->states & AccessNode::VERTICAL) !== 0 ? 1 : 2); // NSAccessibilityOrientationVertical, Horizontal
        }
        if (Role::ranged($n->role) && ($fresh || $n->min !== $o->min || $n->max !== $o->max)) {
            ObjC::msg('v:p', $obj, 'setAccessibilityMinValue:', self::number($n->min));
            ObjC::msg('v:p', $obj, 'setAccessibilityMaxValue:', self::number($n->max));
        }
        // The state of a toggle is its value; a row's choice is not, which its table tells (AXSelectedRowsChanged).
        $toggled = self::toggle($n->role) && $changed(AccessNode::CHECKED | AccessNode::MIXED) || $n->role === Role::DISCLOSURE && $changed(AccessNode::EXPANDED);
        $valueChanged = false;
        if ($fresh || $n->value !== $o->value || $n->now !== $o->now || $toggled || $n->role === Role::TEXT && $n->label !== $o->label
            || $n->role === Role::HEADING && $n->level !== $o->level) {
            ObjC::msg('v:p', $obj, 'setAccessibilityValue:', self::valueOf($n));
            $valueChanged = !$fresh;
        }
        if ($n->level > 0 && $n->role !== Role::HEADING && ($fresh || $n->level !== $o->level)) {
            ObjC::msg('v:l', $obj, 'setAccessibilityDisclosureLevel:', $n->level - 1); // how deep the row of an outline is, from 0
        }
        if ($n->role === Role::TREE_ITEM && ($fresh || $changed(AccessNode::EXPANDED))) {
            ObjC::msg('v:b', $obj, 'setAccessibilityDisclosed:', ($n->states & AccessNode::EXPANDED) !== 0);
        }
        if ($n->posInSet > 0 && ($fresh || $n->posInSet !== $o->posInSet)) { ObjC::msg('v:l', $obj, 'setAccessibilityIndex:', $n->posInSet - 1); }
        if (self::chooses($n) && ($fresh || !self::chooses($o) || $changed(AccessNode::CHECKED))) {
            ObjC::msg('v:b', $obj, 'setAccessibilitySelected:', ($n->states & AccessNode::CHECKED) !== 0);
        }
        if ($fresh || $n->placeholder !== $o->placeholder) { ObjC::msg('v:p', $obj, 'setAccessibilityPlaceholderValue:', self::str($n->placeholder)); }
        if ($n->role === Role::COLUMN_HEADER && ($fresh || $changed(AccessNode::SORT_ASCENDING | AccessNode::SORT_DESCENDING))) {
            ObjC::msg('v:l', $obj, 'setAccessibilitySortDirection:', ($n->states & AccessNode::SORT_ASCENDING) !== 0 ? 1 : (($n->states & AccessNode::SORT_DESCENDING) !== 0 ? 2 : 0));
        }
        // AppKit's help, which it gives tooltips as.
        if ($fresh || $n->description !== $o->description) { ObjC::msg('v:p', $obj, 'setAccessibilityHelp:', self::str($n->description)); }
        if (self::textual($n->role) && ($fresh || $n->value !== $o->value || $n->selStart !== $o->selStart || $n->selEnd !== $o->selEnd)) {
            $len = mb_strlen($n->value, 'UTF-8');
            $a = max(0, min($n->selStart, $len)); $b = max(0, min($n->selEnd, $len));
            ObjC::msg('v:l', $obj, 'setAccessibilityNumberOfCharacters:', self::units($n->value));
            $before = self::units(mb_substr($n->value, 0, $a, 'UTF-8'));
            ObjC::msg('v:n', $obj, 'setAccessibilitySelectedTextRange:', ObjC::range($before, self::units(mb_substr($n->value, $a, max(0, $b - $a), 'UTF-8'))));
        }
        return $valueChanged;
    }

    /** Releases the element of node $id. */
    private function drop(int $id): void
    {
        $obj = $this->elements[$id]['obj'];
        self::post($obj, 'AXUIElementDestroyed');
        unset(self::$byObj[ObjC::addr($obj)], $this->elements[$id]);
        ObjC::msg('v:', $obj, 'release');
    }

    /** Sets the elements of the frame of $tree (mygo's UpdateAccessibility). */
    private function apply(AccessTree $tree): void
    {
        $this->updating = true;
        try {
            $changed = false;
            $objs = []; $valueChanged = []; $fresh = [];
            foreach ($tree->nodes as $i => $n) {
                if (!isset($this->elements[$n->id])) {
                    $fresh[$n->id] = true;
                    $obj = ObjC::msg('p:', ObjC::alloc('MyPHPAccessibilityElement'), 'init');
                    $this->elements[$n->id] = ['obj' => $obj, 'node' => $n, 'parent' => 0, 'children' => [], 'chosen' => [], 'count' => -1];
                    self::$byObj[ObjC::addr($obj)] = [$this, $n->id];
                    $this->set($this->elements[$n->id], $n, true);
                    $changed = true;
                } elseif ($this->set($this->elements[$n->id], $n, false)) {
                    $valueChanged[] = $this->elements[$n->id]['obj'];
                }
                $objs[$i] = $this->elements[$n->id]['obj'];
            }
            $children = []; $childObjs = []; $top = [];
            foreach ($tree->nodes as $i => $n) {
                if ($n->parent < 0 || !isset($tree->nodes[$n->parent])) { $top[] = $objs[$i]; continue; }
                $children[$n->parent][] = $n->id;
                $childObjs[$n->parent][] = $objs[$i];
            }
            foreach ($tree->nodes as $i => $n) {
                $el = &$this->elements[$n->id];
                $parent = $n->parent >= 0 && isset($tree->nodes[$n->parent]) ? $objs[$n->parent] : $this->view;
                if ($el['parent'] !== ObjC::addr($parent)) {
                    $el['parent'] = ObjC::addr($parent);
                    ObjC::msg('v:p', $el['obj'], 'setAccessibilityParent:', $parent);
                }
                $ids = $children[$i] ?? []; $kids = $childObjs[$i] ?? [];
                if ($n->webView !== null && !$n->webView->isDestroyed()) { // the page of a web view inside the element showing it (mygo's)
                    $web = $n->webView->page()->web->ns;
                    array_unshift($kids, $web); array_unshift($ids, 'web:' . ObjC::addr($web));
                }
                if ($el['children'] !== $ids) {
                    $el['children'] = $ids;
                    ObjC::msg('v:p', $el['obj'], 'setAccessibilityChildren:', self::array($kids));
                    $changed = true;
                }
                unset($el);
            }
            // Lists and tables: their rows, those that show, those chosen, and how many they have, built or not. A list's own rows say where they are among all; the rows of other tables, where among those.
            $selectionChanged = [];
            foreach ($tree->nodes as $i => $n) {
                if ($n->role !== Role::LIST && $n->role !== Role::TABLE && $n->role !== Role::TREE) { continue; }
                $el = &$this->elements[$n->id];
                $rows = []; $shown = []; $chosen = []; $chosenIds = [];
                foreach ($children[$i] ?? [] as $cid) {
                    $c = $this->elements[$cid];
                    if (!in_array($c['node']->role, [Role::ROW, Role::LIST_ITEM, Role::TREE_ITEM], true) || $n->setSize > 0 && $c['node']->posInSet === 0) { continue; } // a table's header
                    if ($c['node']->posInSet === 0) { ObjC::msg('v:l', $c['obj'], 'setAccessibilityIndex:', count($rows)); }
                    $rows[] = $c['obj'];
                    if (($c['node']->states & AccessNode::OFFSCREEN) === 0) { $shown[] = $c['obj']; }
                    if (self::chooses($c['node']) && ($c['node']->states & AccessNode::CHECKED) !== 0) { $chosen[] = $c['obj']; $chosenIds[] = $cid; }
                }
                $count = $n->setSize > 0 ? $n->setSize : count($rows);
                if ($count !== $el['count']) {
                    $el['count'] = $count;
                    ObjC::msg('v:l', $el['obj'], 'setAccessibilityRowCount:', $count);
                }
                ObjC::msg('v:p', $el['obj'], 'setAccessibilityRows:', self::array($rows));
                ObjC::msg('v:p', $el['obj'], 'setAccessibilityVisibleRows:', self::array($shown));
                ObjC::msg('v:p', $el['obj'], 'setAccessibilitySelectedRows:', self::array($chosen));
                if ($el['chosen'] !== $chosenIds) {
                    $el['chosen'] = $chosenIds;
                    if (!isset($fresh[$n->id])) { $selectionChanged[] = $el['obj']; } // a new list's choice did not change
                }
                unset($el);
            }
            if ($this->topLevel !== null) { ObjC::msg('v:', $this->topLevel, 'release'); }
            $this->topLevel = ObjC::msg('p:', self::array($top), 'retain');
            $live = [];
            foreach ($tree->nodes as $n) { $live[$n->id] = true; }
            foreach (array_keys($this->elements) as $id) {
                if (!isset($live[$id])) { $this->drop($id); $changed = true; }
            }
            if ($changed) { self::post($this->view, 'AXLayoutChanged'); }
            foreach ($valueChanged as $obj) { self::post($obj, 'AXValueChanged'); }
            foreach ($selectionChanged as $obj) { self::post($obj, 'AXSelectedRowsChanged'); }
            if ($tree->focus !== $this->tree->focus && isset($this->elements[$tree->focus])) { self::post($this->elements[$tree->focus]['obj'], 'AXFocusedUIElementChanged'); }
            foreach ($tree->announcements as $text) { self::announce($text); }
            $this->tree = clone $tree;
            $this->tree->announcements = [];
        } finally {
            $this->updating = false;
        }
    }

    /** The innermost element at a point of the view, or null. */
    private function at(float $x, float $y)
    {
        [$x, $y] = [$x / $this->input->zoom, $y / $this->input->zoom]; // the content's points (Context::setZoom)
        $found = null;
        foreach ($this->tree->nodes as $n) {
            [$bx, $by, $bw, $bh] = $n->bounds;
            if ($x >= $bx && $y >= $by && $x < $bx + $bw && $y < $by + $bh && isset($this->elements[$n->id])) {
                $found = $this->elements[$n->id]['obj']; // later nodes are deeper or above
            }
        }
        return $found;
    }

    /** A rectangle of the content, [x, y, w, h], on the screen: where it shows, zoomed (Context::setZoom). */
    private function screenRect(array $b)
    {
        $z = $this->input->zoom;
        $inWindow = ObjC::msg('r:rp', $this->view, 'convertRect:toView:', ObjC::rect($b[0] * $z, $b[1] * $z, $b[2] * $z, $b[3] * $z), null);
        return ObjC::msg('r:r', ObjC::msg('p:', $this->view, 'window'), 'convertRectToScreen:', $inWindow);
    }

    /** Defines the class of the elements, MyPHPAccessibilityElement. */
    private static function define(): void
    {
        if (self::$defined) { return; }
        self::$defined = true;
        $kit = ObjC::$c->dlopen('/System/Library/Frameworks/AppKit.framework/AppKit', 2);
        self::$postNote = ObjC::$c->cast('void(*)(void*, void*)', ObjC::$c->dlsym($kit, 'NSAccessibilityPostNotification'));
        self::$postInfo = ObjC::$c->cast('void(*)(void*, void*, void*)', ObjC::$c->dlsym($kit, 'NSAccessibilityPostNotificationWithUserInfo'));
        self::$actionDescription = ObjC::$c->cast('void*(*)(void*)', ObjC::$c->dlsym($kit, 'NSAccessibilityActionDescription'));
        $super = 'NSAccessibilityElement';
        $allowed = [
            'accessibilityPerformPress' => AccessNode::ACTION_PRESS, 'accessibilityPerformIncrement' => AccessNode::ACTION_INCREMENT,
            'accessibilityPerformDecrement' => AccessNode::ACTION_DECREMENT, 'setAccessibilityValue:' => AccessNode::ACTION_SET_VALUE,
            'setAccessibilitySelectedText:' => AccessNode::ACTION_SET_VALUE,
            'setAccessibilityFocused:' => AccessNode::ACTION_FOCUS, 'setAccessibilityDisclosed:' => AccessNode::ACTION_EXPAND,
        ];
        // The text of a text field's UTF-16 units of range $r.
        $slice = fn(AccessNode $n, $r) => self::unitSlice($n->value, (int)$r->location, (int)$r->length);
        ObjC::defineClass('MyPHPAccessibilityElement', $super, [
            'accessibilityFrame' => ['{CGRect={CGPoint=dd}{CGSize=dd}}@:', 'r(pp)', function ($self, $cmd) {
                [$s, $el] = self::of($self) ?? [null, null];
                return $s === null ? ObjC::rect(0, 0, 0, 0) : $s->screenRect($el['node']->bounds);
            }],
            'isAccessibilityFocused' => ['B@:', 'b(pp)', function ($self, $cmd) {
                [$s, $el] = self::of($self) ?? [null, null];
                return $s !== null && $s->tree->focus === $el['node']->id;
            }],
            // AXInvalid, which the newer API lacks, says a value is not valid, as WebKit's fields do, and AXMenuItemMarkChar what an item of a menu shows of its choice.
            'accessibilityAttributeNames' => ['@@:', 'p(pp)', function ($self, $cmd) use ($super) {
                $names = ObjC::msgSuper('p:', $self, $super, 'accessibilityAttributeNames');
                [, $el] = self::of($self) ?? [null, null];
                if ($el === null) { return $names; }
                if (($el['node']->states & AccessNode::INVALID) !== 0) { $names = ObjC::msg('p:p', $names, 'arrayByAddingObject:', ObjC::str('AXInvalid')); }
                if (self::menuItem($el['node']->role)) { $names = ObjC::msg('p:p', $names, 'arrayByAddingObject:', ObjC::str('AXMenuItemMarkChar')); }
                return $names;
            }],
            'accessibilityAttributeValue:' => ['@@:@', 'p(ppp)', function ($self, $cmd, $attr) use ($super) {
                [, $el] = self::of($self) ?? [null, null];
                if ($el !== null) {
                    switch (ObjC::phpStr($attr)) {
                        case 'AXInvalid':
                            if (($el['node']->states & AccessNode::INVALID) !== 0) { return ObjC::str('true'); }
                            break;
                        case 'AXMenuItemMarkChar':
                            if (($m = self::markChar($el['node'])) !== '') { return ObjC::str($m); }
                            if (self::menuItem($el['node']->role)) { return null; }
                            break;
                    }
                }
                return ObjC::msgSuper('p:p', $self, $super, 'accessibilityAttributeValue:', $attr);
            }],
            // The older API asks which attributes are settable, which NSAccessibilityElement does not answer (calling super throws): AppKit's answer without it is the attributes of the setters the class overrides, AXValue, AXFocused and a row's AXDisclosing, kept but for the value of a read-only text field, which is not settable, as WebKit's.
            'accessibilityIsAttributeSettable:' => ['B@:@', 'b(ppp)', function ($self, $cmd, $attr) {
                [, $el] = self::of($self) ?? [null, null];
                if ($el === null) { return false; }
                return match (ObjC::phpStr($attr)) {
                    'AXValue' => !self::textual($el['node']->role) || ($el['node']->actions & AccessNode::ACTION_SET_VALUE) !== 0,
                    'AXSelectedText' => self::textual($el['node']->role) && ($el['node']->actions & AccessNode::ACTION_SET_VALUE) !== 0,
                    'AXFocused' => true,
                    'AXDisclosing' => $el['node']->role === Role::TREE_ITEM,
                    default => false,
                };
            }],
            'isAccessibilitySelectorAllowed:' => ['B@::', 'b(ppp)', function ($self, $cmd, $selector) use ($super, $allowed) {
                [, $el] = self::of($self) ?? [null, null];
                if ($el !== null && isset($allowed[$name = ObjC::selName($selector)])) { return ($el['node']->actions & $allowed[$name]) !== 0; }
                return ObjC::msgSuper('b:p', $self, $super, 'isAccessibilitySelectorAllowed:', $selector);
            }],
            // Scrolling into view is an action of the older API alone, which VoiceOver asks for as it moves to what is out of view: answering it, an element answers for all its actions there.
            'accessibilityActionNames' => ['@@:', 'p(pp)', function ($self, $cmd) {
                [, $el] = self::of($self) ?? [null, null];
                $names = [];
                foreach (self::LEGACY_ACTIONS as $name => [$bit]) {
                    if ($el !== null && ($el['node']->actions & $bit) !== 0) { $names[] = ObjC::str($name); }
                }
                return self::array($names);
            }],
            'accessibilityActionDescription:' => ['@@:@', 'p(ppp)', fn($self, $cmd, $action) => (self::$actionDescription)($action)],
            'accessibilityPerformAction:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $action) {
                [$s, $el] = self::of($self) ?? [null, null];
                [$bit, $kind] = self::LEGACY_ACTIONS[ObjC::phpStr($action)] ?? [0, 0];
                if ($s !== null && ($el['node']->actions & $bit) !== 0) { $s->act($el['node']->id, $kind); }
            }],
            'accessibilityPerformPress' => ['B@:', 'b(pp)', function ($self, $cmd) {
                [$s, $el] = self::of($self) ?? [null, null];
                return $s !== null && $s->act($el['node']->id, AccessNode::PRESS);
            }],
            'accessibilityPerformIncrement' => ['B@:', 'b(pp)', function ($self, $cmd) {
                [$s, $el] = self::of($self) ?? [null, null];
                return $s !== null && $s->act($el['node']->id, AccessNode::INCREMENT);
            }],
            'accessibilityPerformDecrement' => ['B@:', 'b(pp)', function ($self, $cmd) {
                [$s, $el] = self::of($self) ?? [null, null];
                return $s !== null && $s->act($el['node']->id, AccessNode::DECREMENT);
            }],
            // Opening and closing a row of an outline, as VoiceOver does.
            'setAccessibilityDisclosed:' => ['v@:B', 'v(ppb)', function ($self, $cmd, $open) use ($super) {
                ObjC::msgSuper('v:b', $self, $super, 'setAccessibilityDisclosed:', $open);
                [$s, $el] = self::of($self) ?? [null, null];
                if ($s !== null && ($el['node']->actions & AccessNode::ACTION_EXPAND) !== 0) { $s->act($el['node']->id, $open ? AccessNode::EXPAND : AccessNode::COLLAPSE); }
            }],
            'setAccessibilityFocused:' => ['v@:B', 'v(ppb)', function ($self, $cmd, $focused) {
                [$s, $el] = self::of($self) ?? [null, null];
                if ($s !== null && $focused) { $s->act($el['node']->id, AccessNode::FOCUS); }
            }],
            'setAccessibilityValue:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $value) use ($super) {
                ObjC::msgSuper('v:p', $self, $super, 'setAccessibilityValue:', $value);
                [$s, $el] = self::of($self) ?? [null, null];
                if ($s !== null && self::textual($el['node']->role)) { $s->act($el['node']->id, AccessNode::SET_VALUE, ObjC::textOf($value)); } // a number too: phpStr would send it UTF8String, which aborts
            }],
            // Typing into a text field over its selection, as Voice Control, dictation and automation insert text (kAXSelectedTextAttribute).
            'setAccessibilitySelectedText:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $text) use ($super) {
                ObjC::msgSuper('v:p', $self, $super, 'setAccessibilitySelectedText:', $text);
                [$s, $el] = self::of($self) ?? [null, null];
                if ($s !== null && self::textual($el['node']->role) && ($el['node']->actions & AccessNode::ACTION_SET_VALUE) !== 0) {
                    $s->act($el['node']->id, AccessNode::INSERT_TEXT, ObjC::textOf($text));
                }
            }],
            // Moving the selection of a text field, as VoiceOver does.
            'setAccessibilitySelectedTextRange:' => ['v@:{_NSRange=QQ}', 'v(ppn)', function ($self, $cmd, $r) use ($super) {
                ObjC::msgSuper('v:n', $self, $super, 'setAccessibilitySelectedTextRange:', $r);
                [$s, $el] = self::of($self) ?? [null, null];
                if ($s === null || !self::textual($el['node']->role)) { return; }
                $v = $el['node']->value;
                $start = mb_strlen(self::unitSlice($v, 0, (int)$r->location), 'UTF-8');
                $s->act($el['node']->id, AccessNode::SELECT_TEXT, '', $start, $start + mb_strlen(self::unitSlice($v, (int)$r->location, (int)$r->length), 'UTF-8'));
            }],
            // The text of text fields, for reading by characters and lines.
            'accessibilityStringForRange:' => ['@@:{_NSRange=QQ}', 'p(ppn)', function ($self, $cmd, $r) use ($slice) {
                [, $el] = self::of($self) ?? [null, null];
                return $el === null ? null : ObjC::str($slice($el['node'], $r));
            }],
            // The text with the system's font, which VoiceOver reads it in.
            'accessibilityAttributedStringForRange:' => ['@@:{_NSRange=QQ}', 'p(ppn)', function ($self, $cmd, $r) use ($slice) {
                [, $el] = self::of($self) ?? [null, null];
                if ($el === null) { return null; }
                $text = $slice($el['node'], $r);
                $as = ObjC::msg('p:', ObjC::msg('p:p', ObjC::alloc('NSMutableAttributedString'), 'initWithString:', ObjC::str($text)), 'autorelease');
                if ($text !== '') { ObjC::msg('v:ppn', $as, 'addAttribute:value:range:', ObjC::str('AXFont'), self::font(), ObjC::range(0, self::units($text))); }
                return $as;
            }],
            'accessibilityLineForIndex:' => ['q@:q', 'l(ppl)', fn($self, $cmd, $i) => 0],
            'accessibilityRangeForLine:' => ['{_NSRange=QQ}@:q', 'n(ppl)', function ($self, $cmd, $line) {
                [, $el] = self::of($self) ?? [null, null];
                return ObjC::range(0, $el === null ? 0 : self::units($el['node']->value));
            }],
            'accessibilityFrameForRange:' => ['{CGRect={CGPoint=dd}{CGSize=dd}}@:{_NSRange=QQ}', 'r(ppn)', function ($self, $cmd, $r) {
                [$s, $el] = self::of($self) ?? [null, null];
                return $s === null ? ObjC::rect(0, 0, 0, 0) : $s->screenRect($el['node']->bounds);
            }],
        ]);
    }

    /** The AXFont of a text: the dictionary of the system font's name, family and size (mygo's accessFont of a run of no format). */
    private static function font()
    {
        $size = 14.0;
        $font = ObjC::msg('p:dd', ObjC::cls('NSFont'), 'systemFontOfSize:weight:', $size, 0.0);
        $d = ObjC::msg('p:', ObjC::cls('NSMutableDictionary'), 'dictionary');
        ObjC::msg('v:pp', $d, 'setObject:forKey:', ObjC::msg('p:', $font, 'fontName'), ObjC::str('AXFontName'));
        ObjC::msg('v:pp', $d, 'setObject:forKey:', ObjC::msg('p:', $font, 'familyName'), ObjC::str('AXFontFamily'));
        ObjC::msg('v:pp', $d, 'setObject:forKey:', ObjC::msg('p:', $font, 'displayName'), ObjC::str('AXVisibleName'));
        ObjC::msg('v:pp', $d, 'setObject:forKey:', self::number($size), ObjC::str('AXFontSize'));
        return $d;
    }
}
