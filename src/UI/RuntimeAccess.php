<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;

/**
 * Assistive technology, such as screen readers, sees the nodes of each
 * frame as a tree once it asks for the window (mygo's access.go): widgets,
 * texts, and the nodes that take the focus or have a label, with their
 * states. It acts on them as the keyboard and the pointer would: pressing
 * clicks, setting a text field's value edits it.
 *
 * A list's rows are nodes of the tree of their own, as mygo builds each in
 * a box, around what the app built for them: here their nodes are the
 * list's children, so the tree makes a node for each row (rowNodeId) over
 * them.
 */
trait RuntimeAccess
{
    /** Whether assistive technology asked for the window: each frame then describes itself (accessTree), as of the first frame after it asked. */
    public bool $access = false;
    /** The last frame's tree, while $access. */
    public ?AccessTree $accessTree = null;
    /** Called with each frame's tree, while $access: the window's, which gives it to the system (Native\Access). @var ?Closure(AccessTree): void */
    public ?Closure $onAccess = null;
    /** @var list<string> texts for assistive technology to read out, of this frame (Context::announce) */
    private array $announcements = [];
    /** @var array<int, true> the nodes asked whether they were clicked this frame (Context::clicked, mygo's flagClickable) */
    private array $clickable = [];
    /** The last frame's tree of nodes. */
    private ?Node $root = null;
    /** @var array<int, array{0: int, 1: ListState, 2: int}> the nodes of the tree for rows of lists: the row's first node, its list and its index */
    private array $rowNodes = [];
    /** @var array<int, int> the parent of each node of the last frame, by id, for revealing it */
    private array $parentOf = [];
    /** @var array<int, Node> the last frame's nodes by id */
    private array $nodeOf = [];
    /** @var array<int, int> the items of trees assistive technology opened (1) or closed (-1), until they ask (expanded) */
    private array $expands = [];

    /** Whether assistive technology opened item $id of a tree (1) or closed it (-1) since it last asked, 0 for neither: the tree opens or closes it as it builds it. */
    public function expanded(int $id): int
    {
        $e = $this->expands[$id] ?? 0;
        unset($this->expands[$id]);
        return $e;
    }

    /** Asks screen readers to read $text out once, after what they are reading (mygo's Announce): news the focus does not bring. */
    public function announce(string $text): void
    {
        if ($text !== '') { $this->announcements[] = $text; }
    }

    /** Starts describing frames for assistive technology, with the last one at once (mygo's accessibilityOn). */
    public function accessibilityOn(): void
    {
        if (!$this->access) {
            $this->access = true;
            if ($this->root === null) { $this->needsFrame = true; }
        }
        $this->describe();
    }

    /** Describes the last frame, while assistive technology reads the window; its announcements go, read or not. */
    private function describe(): void
    {
        if ($this->access && $this->root !== null) {
            $this->accessTree = $this->accessTreeOf($this->root);
            if ($this->onAccess !== null) { ($this->onAccess)($this->accessTree); }
        }
        $this->announcements = [];
    }

    /** The tree of the frame of root $root. */
    private function accessTreeOf(Node $root): AccessTree
    {
        $t = new AccessTree;
        $this->rowNodes = []; $this->parentOf = []; $this->nodeOf = [];
        $focused = null;
        $this->accessNode($t, $root, -1, null, $focused, 0);
        if ($this->focus !== null) {
            $focus = $this->focus;
            // A list choosing rows with the arrows has the focus for them: assistive technology follows the row chosen, as the arrows move the choice.
            if (isset($this->lists[$this->focus])) {
                $l = $this->lists[$this->focus];
                foreach ($this->rowNodes as $id => [, $list, $i]) {
                    if ($list === $l && $i === $l->selected) { $focus = $id; }
                }
            }
            // A combobox has the focus for the option its arrows are on.
            if ($focused?->activeDescendant !== null) { $focus = $focused->activeDescendant->id; }
            foreach ([$focus, $this->focus] as $id) {
                if ($t->focus !== 0) { break; }
                foreach ($t->nodes as $n) { if ($n->id === $id) { $t->focus = $id; break; } }
            }
        }
        $t->announcements = $this->announcements;
        return $t;
    }

    /** The node of the tree for row $i of the list of node $list, whose first node is $first. */
    private static function rowNodeId(Node $first): int
    {
        return $first->id ^ 0x5bd1e9955bd1e995;
    }

    /** Whether the node takes the keyboard focus. */
    private static function takesFocus(Node $n): bool
    {
        return $n->kind === Node::BUTTON || $n->kind === Node::INPUT || $n->kind === Node::TEXTAREA || $n->focusable;
    }

    /** The node's role, or null for nodes assistive technology does not see (mygo's accessRole). */
    private function roleOf(Node $n): ?int
    {
        if ($n->role === Role::NONE) { return null; }
        if ($n->role !== Role::AUTO) { return $n->role; }
        return match (true) {
            $n->kind === Node::INPUT || $n->kind === Node::TEXTAREA => Role::TEXT_FIELD,
            $n->kind === Node::TEXT || $n->kind === Node::RICH => self::innerText($n) !== '' ? Role::TEXT : null,
            $n->kind === Node::IMAGE || $n->svg !== null => $n->label !== '' ? Role::IMAGE : null, // images and icons; others are decoration
            $n->kind === Node::BUTTON || isset($this->clickable[$n->id]) && $n->focusable => Role::BUTTON,
            $n->scroll !== null => Role::SCROLL,
            $n->label !== '' || $n->focusable => Role::GROUP,
            default => null,
        };
    }

    /** The texts of a node and those inside it, joined by spaces: a widget's own text, as a button's (mygo's innerText). */
    public static function innerText(Node $n): string
    {
        if ($n->kind === Node::RICH) { return implode('', array_map(fn(Span $s) => $s->text, $n->spans)); }
        if ($n->role === Role::NONE && $n->text !== null) { return ''; } // a glyph drawn as text, as a tree's arrow: decoration
        if ($n->kind !== Node::INPUT && $n->kind !== Node::TEXTAREA && $n->text !== null && $n->text !== '') { return $n->text; } // a field's text is its placeholder
        $out = [];
        foreach ($n->kids as $k) {
            if (($s = self::innerText($k)) !== '') { $out[] = $s; }
        }
        return implode(' ', $out);
    }

    /**
     * Adds the nodes of $n and those inside it, below node $parent;
     * $clip is the box of the scroll containers around it, and $focused
     * gets the node with the keyboard focus (mygo's accessElement).
     */
    private function accessNode(AccessTree $t, Node $n, int $parent, ?array $clip, ?Node &$focused, int $parentId): void
    {
        $this->parentOf[$n->id] = $parentId;
        $this->nodeOf[$n->id] = $n;
        if ($n->id === $this->focus) { $focused = $n; }
        // What is behind a dialog is inert: the dialog on top and what shows above it alone.
        if (($role = $this->roleOf($n)) !== null && $this->reachable($n->id)) {
            $node = new AccessNode($n->id, $parent, $role);
            $node->label = $n->label;
            $node->bounds = $n->world->bounds($n->x, $n->y, $n->w, $n->h); // as it shows, through its transform (mygo's accessBounds)
            if ($clip !== null) { $node->actions |= AccessNode::ACTION_SCROLL_INTO_VIEW; }
            $this->accessDetails($n, $node, $clip);
            $t->nodes[] = $node;
            $parent = count($t->nodes) - 1;
            if (Role::leaf($role)) { return; }
        }
        if ($n->scroll !== null) {
            $box = $n->world->bounds($n->x, $n->y, $n->w, $n->h);
            $clip = $clip === null ? $box : self::intersect($clip, $box);
        }
        $list = $n->scroll instanceof ListState && $n->rowRole !== Role::NONE ? $n->scroll : null; // a grid's rows are no nodes: its items are
        // Children in flow first, absolute ones above them, as they paint.
        foreach ([false, true] as $absolute) {
            foreach ($n->kids as $k => $kid) {
                if ($kid->absolute !== $absolute) { continue; }
                if ($list !== null && !$absolute && isset($n->rowStarts[$k])) {
                    $this->accessRow($t, $n, $list, $k, $parent, $clip, $focused);
                    continue;
                }
                if ($list !== null && !$absolute && $n->rowStarts !== []) { continue; } // a row's other nodes: inside the row's node
                $this->accessNode($t, $kid, $parent, $clip, $focused, $n->id);
            }
        }
    }

    /** Adds the node of the row of list $state starting at child $k of the list's node $n, and the nodes of the row inside it. */
    private function accessRow(AccessTree $t, Node $n, ListState $state, int $k, int $parent, ?array $clip, ?Node &$focused): void
    {
        $i = $n->rowStarts[$k];
        $end = $k + 1;
        while ($end < count($n->kids) && !isset($n->rowStarts[$end]) && !$n->kids[$end]->absolute) { $end++; }
        $first = $n->kids[$k];
        if (!$this->reachable($first->id)) { // behind a dialog
            for ($j = $k; $j < $end; $j++) { $this->accessNode($t, $n->kids[$j], $parent, $clip, $focused, $n->id); }
            return;
        }
        $id = self::rowNodeId($first);
        $this->rowNodes[$id] = [$first->id, $state, $i];
        $this->parentOf[$id] = $n->id;
        $x0 = INF; $y0 = INF; $x1 = -INF; $y1 = -INF;
        for ($j = $k; $j < $end; $j++) {
            $r = $n->kids[$j];
            $x0 = min($x0, $r->x); $y0 = min($y0, $r->y); $x1 = max($x1, $r->x + $r->w); $y1 = max($y1, $r->y + $r->h);
        }
        $row = new AccessNode($id, $parent, $n->rowRole);
        $row->bounds = $n->world->bounds($x0, $y0, $x1 - $x0, $y1 - $y0);
        $row->posInSet = $i + 1; $row->setSize = $state->count;
        if ($clip !== null) {
            $row->actions |= AccessNode::ACTION_SCROLL_INTO_VIEW;
            if (self::intersect($clip, $row->bounds)[2] <= 0 || self::intersect($clip, $row->bounds)[3] <= 0) { $row->states |= AccessNode::OFFSCREEN; }
        }
        if ($state->selected !== null) {
            // Focusing a row a list chooses chooses it.
            $row->states |= AccessNode::SELECTABLE | AccessNode::FOCUSABLE;
            $row->actions |= AccessNode::ACTION_PRESS | AccessNode::ACTION_FOCUS;
            if ($state->chosen($i)) { $row->states |= AccessNode::CHECKED; }
        }
        // Rows are named by their content, as leaves are, but show what is inside them too.
        $names = [];
        for ($j = $k; $j < $end; $j++) { if (($s = self::innerText($n->kids[$j])) !== '') { $names[] = $s; } }
        $row->label = implode(' ', $names);
        $t->nodes[] = $row;
        $at = count($t->nodes) - 1;
        for ($j = $k; $j < $end; $j++) { $this->accessNode($t, $n->kids[$j], $at, $clip, $focused, $id); }
    }

    /** The part of box $a in box $b. @return array{0: float, 1: float, 2: float, 3: float} */
    private static function intersect(array $a, array $b): array
    {
        $x = max($a[0], $b[0]); $y = max($a[1], $b[1]);
        return [$x, $y, max(0.0, min($a[0] + $a[2], $b[0] + $b[2]) - $x), max(0.0, min($a[1] + $a[3], $b[1] + $b[3]) - $y)];
    }

    /** Fills in the name, value, states and actions of node $node of $n (mygo's accessDetails). */
    private function accessDetails(Node $n, AccessNode $node, ?array $clip): void
    {
        if ($n->segment) { $node->states |= AccessNode::SEGMENT; }
        if ($n->vertical) { $node->states |= AccessNode::VERTICAL; }
        if ($n->search) { $node->states |= AccessNode::SEARCH; }
        if ($n->invalid) { $node->states |= AccessNode::INVALID; }
        $node->states |= match ($n->sort) { 1 => AccessNode::SORT_ASCENDING, 2 => AccessNode::SORT_DESCENDING, default => 0 };
        $node->description = $n->description ?? $n->tooltip ?? '';
        if (($f = $n->nameFrom) !== null) {
            $name = $f->label === '' && $f->kind === Node::TEXT ? (string)$f->text : $f->label; // its label, or the text it is (mygo's nameOf)
            if ($node->label === '') { $node->label = $name; } elseif ($n->nameJoin && $name !== '') { $node->label = $name . ' ' . $node->label; }
        }
        if ($n->setSize > 0) { $node->posInSet = $n->setPos; $node->setSize = $n->setSize; }
        if ($n->choosesItems) { $node->states |= AccessNode::SELECTABLE; }
        if ($n->chooseMany) { $node->states |= AccessNode::MULTISELECTABLE; }
        // Statuses are named by their content, as leaves are, but show what is inside them too, as a toast's button.
        if ($node->label === '' && (Role::leaf($node->role) || $node->role === Role::STATUS)) { $node->label = self::innerText($n); }
        $node->states |= match ($n->checked) { 2 => AccessNode::CHECKED, 3 => AccessNode::MIXED, default => 0 };
        if ($n->expanded) { $node->states |= AccessNode::EXPANDED; }
        if ($n->expandable) { $node->states |= AccessNode::EXPANDABLE; }
        $node->level = $n->level;
        $node->value = $n->accValue;
        if ($n->accRange !== null) { [$node->min, $node->max, $node->now] = $n->accRange; $node->step = $n->accStep; }
        // A list says how many rows it has, built or not.
        if ($n->scroll instanceof ListState && $n->rowStarts !== [] && $n->setSize === 0) {
            $node->setSize = $n->scroll->count;
            if ($n->scroll->selected !== null) { $node->states |= AccessNode::SELECTABLE; } // as do its rows
            if ($n->scroll->selection !== null) { $node->states |= AccessNode::MULTISELECTABLE; }
        }
        // What a scroll container built out of view.
        if ($clip !== null && $n->w > 0 && $n->h > 0) {
            $v = self::intersect($clip, $node->bounds);
            if ($v[2] <= 0 || $v[3] <= 0) { $node->states |= AccessNode::OFFSCREEN; }
        }
        if ($n->choosable) { $node->states |= AccessNode::SELECTABLE; }
        if (self::takesFocus($n) || $n->choosable || ($n->scroll instanceof ListState && $n->scroll->selected !== null)) {
            // Focusing an item its container chooses chooses it.
            $node->states |= AccessNode::FOCUSABLE;
            $node->actions |= AccessNode::ACTION_FOCUS;
        }
        if ($n->kind === Node::BUTTON || isset($this->clickable[$n->id])) { $node->actions |= AccessNode::ACTION_PRESS; }
        if ($node->role === Role::SLIDER || $node->role === Role::STEPPER) { $node->actions |= AccessNode::ACTION_INCREMENT | AccessNode::ACTION_DECREMENT; }
        if ($n->expandable) { $node->actions |= AccessNode::ACTION_EXPAND; }
        if (($e = $n->edit) !== null && ($n->kind === Node::INPUT || $n->kind === Node::TEXTAREA)) {
            if ($e->readOnly) { $node->states |= AccessNode::READ_ONLY; } else { $node->actions |= AccessNode::ACTION_SET_VALUE; }
            if ($e->multiline) { $node->states |= AccessNode::MULTILINE; }
            $node->placeholder = $n->text ?? '';
            $node->value = $e->text;
            [$a, $b] = [min($e->anchor, $e->cursor), max($e->anchor, $e->cursor)];
            $node->selStart = mb_strlen(substr($e->text, 0, $a), 'UTF-8');
            $node->selEnd = mb_strlen(substr($e->text, 0, $b), 'UTF-8');
        }
        if ($n->accFill !== null) { ($n->accFill)($node); }
    }

    /**
     * Performs an action of assistive technology on node $id of the last
     * frame, as the keyboard or the pointer would (mygo's accessAction):
     * an AccessNode action, with the text set or the characters $from to
     * $to selected.
     */
    private function accessAction(int $id, int $action, string $text = '', int $from = 0, int $to = 0): void
    {
        $this->needsFrame = true;
        $row = $this->rowNodes[$id] ?? null;
        $n = $this->nodeOf[$id] ?? null;
        if ($row === null && $n === null) { return; }
        switch ($action) {
            case AccessNode::PRESS:
                if ($row !== null) { $this->activated = $row[0]; break; }
                if ($n->kind === Node::BUTTON || isset($this->clickable[$id])) { $this->activated = $id; } else { $this->accessFocus($id); }
                break;
            case AccessNode::FOCUS:
                if ($row !== null) {
                    // A row of a list choosing rows: its list takes the focus for it, choosing it.
                    $this->activated = $row[0];
                    $this->accessFocus($this->parentOf[$id]);
                    break;
                }
                if ($n->choosable) { $this->activated = $id; break; }
                $this->accessFocus($id);
                break;
            case AccessNode::EXPAND:
            case AccessNode::COLLAPSE:
                // The tree opens or closes the item as it builds it.
                $this->expands[$id] = $action === AccessNode::EXPAND ? 1 : -1;
                break;
            case AccessNode::SCROLL_INTO_VIEW:
                // The rows of lists around it scroll into view by their place, as the next frame may not build them, then it does.
                for ($at = $id; $at !== 0; $at = $this->parentOf[$at] ?? 0) {
                    if (isset($this->rowNodes[$at])) { $this->rowNodes[$at][1]->reveal($this->rowNodes[$at][2]); }
                }
                $this->reveal($id);
                break;
            case AccessNode::INCREMENT:
            case AccessNode::DECREMENT:
                $this->accessFocus($id);
                // The key, as pressed: sliders step right and left, steppers up and down.
                $up = $action === AccessNode::INCREMENT;
                [$code, $selector] = $n?->role === Role::STEPPER ? ($up ? [Key::UP, 'moveUp:'] : [Key::DOWN, 'moveDown:']) : ($up ? [Key::RIGHT, 'moveRight:'] : [Key::LEFT, 'moveLeft:']);
                $this->keys[] = ['', $code, 0, false];
                if ($this->command($selector)) { $this->keys[array_key_last($this->keys)][3] = true; }
                break;
            case AccessNode::SELECT_TEXT:
                if (($e = $n?->edit) !== null) {
                    $e->anchor = self::byteOf($e->text, $from);
                    $e->cursor = self::byteOf($e->text, $to);
                }
                break;
            case AccessNode::SET_VALUE:
                if (($e = $n?->edit) === null || $e->readOnly) { return; }
                $this->accessFocus($id);
                $e->selectAll();
                $e->insert($text);
                break;
        }
    }

    /** The byte of character $i of $s. */
    private static function byteOf(string $s, int $i): int
    {
        return strlen(mb_substr($s, 0, max(0, $i), 'UTF-8'));
    }

    /** Gives node $id the keyboard focus, as Tab would (mygo's focusOn), if it takes it. */
    private function accessFocus(int $id): void
    {
        $n = $this->nodeOf[$id] ?? null;
        if ($n === null || !(self::takesFocus($n) || isset($this->lists[$id]))) { return; }
        $this->focus = $id;
        $this->focusVisible = true;
        $this->reveal($id);
    }

    /** Scrolls the scroll containers around node $id of the last frame to show it. */
    private function reveal(int $id): void
    {
        $r = $this->rects[$id] ?? null;
        if ($r === null) { return; }
        for ($at = $this->parentOf[$id] ?? 0; $at !== 0; $at = $this->parentOf[$at] ?? 0) {
            $s = $this->nodeOf[$at]->scroll ?? null;
            if (!$s instanceof ScrollState || !isset($this->rects[$at])) { continue; }
            $box = $this->rects[$at];
            if ($r[1] < $box[1]) { $s->scrollBy($r[1] - $box[1]); } elseif ($r[1] + $r[3] > $box[1] + $box[3]) { $s->scrollBy(min($r[1] - $box[1], $r[1] + $r[3] - $box[1] - $box[3])); }
        }
    }
}
