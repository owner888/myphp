<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * Subtrees kept from frame to frame (Context::memo): a memo built once is
 * placed again, its nodes as they were laid out, while what it was built
 * from is the same, nothing the pointer or the keys did this frame could
 * have reached it, and its build asked for no frame of its own. What its
 * build registered with the runtime and the context (clicks it takes,
 * steppers, local state…) is noted as it is built and given back each frame
 * it is placed again; a build that changed anything else there is not kept.
 * Not mygo's, whose nodes are compared one by one: in PHP that costs about
 * as much as laying them out (docs/mygo-deviations.md).
 */
trait RuntimeMemo
{
    /**
     * The memos of the frames before, by the id of their node: what they were built from, the context they were built
     * in, the node, what the build registered, the ids recorded under it (last frame) and where they showed, the states
     * inside it and how they were, the frame it was last placed in, whether its build read the keys; what it added to
     * the context (ctxAdds), what recording it added (rec, memoRecordEnd) and its quads (paint, memoPaintEnd).
     * @var array<int, array{deps: array, ctx: array, style: TextStyle, node: Node, adds: array, ids: array<int, true>, area: ?array{0: float, 1: float, 2: float, 3: float}, states: list<array{0: object, 1: array}>, frame: int, keys: bool, ctxAdds?: array, rec?: array, paint?: array}>
     */
    private array $memos = [];
    /** @var list<array{0: float, 1: float}> where the pointer was, went, pressed or scrolled since the frame before: a memo there is built again */
    private array $memoPoints = [];
    /** Whether a key, a command or text came this frame: what reads keys (shortcut()) or holds the focus is built again. */
    private bool $memoKeyed = false;
    /** Whether the state the build reads outside the nodes (the focus, a press, a tip, a menu, a drag…) changed since the frame before: every memo is built again. */
    private bool $memoStale = true;
    /** That state last frame, and the pointer then (null outside the window). */
    private ?array $memoSeen = null;
    private ?array $memoPointer = null;
    /** Whether what is being built read the keys of the frame (shortcut(), takeKeys(), submitMods()). */
    private bool $readsKeys = false;
    /** The runtime's state that a memo's build changes, as it was before it, while one is built: [values, its own flags]. */
    private array $memoMarks = [];

    /** The context the nodes memos keep are in from the frame they were built on (memoKeeper). */
    private ?Context $keeper = null;

    /** @internal Context::memo: the context of no tree, but the runtime, that kept nodes are in; $make makes it the first time. @param \Closure(): Context $make */
    public function memoKeeper(\Closure $make): Context
    {
        return $this->keeper ??= $make();
    }

    /** Notes, once the frame's input is in, what reaches memos this frame: the points the pointer touched, the keys, the state they read. */
    private function memoFrame(array $events): void
    {
        $this->memoPoints = [];
        $this->memoKeyed = false;
        foreach ($events as $e) {
            if (in_array($e[0], ['down', 'up', 'drag', 'scroll', 'magnify', 'pressure'], true)) {
                $this->memoPoints[] = [(float)$e[1], (float)$e[2]];
            } elseif (in_array($e[0], ['key', 'edit', 'command', 'insert', 'access', 'accessOn'], true)) {
                $this->memoKeyed = true;
            }
        }
        $pointer = $this->input->mouseInside ? [$this->input->mouseX, $this->input->mouseY] : null;
        if ($pointer !== null) {
            $this->memoPoints[] = $pointer; // where it rests: what moves under it hovers
        }
        if ($this->memoPointer !== null && $this->memoPointer !== $pointer) {
            $this->memoPoints[] = $this->memoPointer; // where it was: what it left hovers no longer
        }
        $this->memoPointer = $pointer;
        $seen = [$this->focus, $this->focusVisible, $this->tip, $this->tipAnchor, $this->tipByFocus, $this->pointerPress['id'] ?? null, $this->pointerPress['down'] ?? null,
            $this->sliding, $this->valueDrag !== null, $this->drag !== null, $this->dataOver, $this->fileOver, $this->menuAsked, $this->menuChosen, $this->modal, $this->windowFocus,
            $this->fullScreen, $this->captured, $this->reduceMotion, $this->rightToLeft, $this->dirOverride, $this->inspector?->open ?? false,
            $this->selection->scope, $this->selection->anchor, $this->selection->caret];
        $this->memoStale = $seen !== $this->memoSeen;
        $this->memoSeen = $seen;
    }

    /**
     * The node memo $id built last, placed again, or null when it is built again: in the first pass of a frame, from the
     * same $deps in the same context, none of the frame's input reaching it, the states inside it as they were.
     */
    public function memoHit(int $id, array $deps, array $ctx, TextStyle $style): ?Node
    {
        $m = $this->memos[$id] ?? null;
        if ($m === null || $this->pass !== 0 || $this->memoStale || $m['deps'] !== $deps || $m['ctx'] !== $ctx || $m['style'] != $style || $m['area'] === null && $m['ids'] === []) {
            return null;
        }
        if ($this->memoKeyed && ($m['keys'] || ($this->focus !== null && isset($m['ids'][$this->focus])))) {
            return null;
        }
        if ($m['area'] !== null) {
            [$x0, $y0, $x1, $y1] = $m['area'];
            foreach ($this->memoPoints as [$x, $y]) {
                if ($x >= $x0 - 2 && $x <= $x1 + 2 && $y >= $y0 - 2 && $y <= $y1 + 2) { return null; }
            }
        }
        foreach ($m['states'] as [$state, $was]) {
            if (self::stateSeen($state) !== $was) { return null; }
        }
        foreach ($m['adds'] as $prop => [$list, $added]) { // what its build registered, again
            if ($list) { array_push($this->$prop, ...$added); } else { foreach ($added as $k => $v) { $this->$prop[$k] = $v; } } // not +=, which copies a typed property's array whole
        }
        $this->memos[$id]['frame'] = $this->frameNumber;
        $n = $m['node'];
        $n->memo = 2;
        return $n;
    }

    /** Before memo $id is built: what the runtime holds, to tell what its build changes. */
    public function memoBegin(): void
    {
        $this->memoMarks[] = [get_object_vars($this), $this->readsKeys, $this->animating, $this->wakeAt];
        $this->readsKeys = false; $this->animating = false; $this->wakeAt = null;
    }

    /**
     * After memo $id is built into $n: kept for the frames after when its build registered nothing but what can be given
     * back and asked for no frame of its own, in the first pass ($clean: nor did it change the context's state but for
     * what Context::memo gives back). Returns whether it is kept.
     */
    public function memoEnd(int $id, array $deps, array $ctx, TextStyle $style, Node $n, bool $clean): bool
    {
        [$before, $keys, $animating, $wake] = array_pop($this->memoMarks);
        $asked = $this->animating || $this->wakeAt !== null;
        $readKeys = $this->readsKeys;
        $this->readsKeys = $keys || $readKeys; $this->animating = $animating || $this->animating;
        $this->wakeAt = $wake === null ? $this->wakeAt : ($this->wakeAt === null ? $wake : min($wake, $this->wakeAt));
        $adds = $clean && !$asked && $this->pass === 0 ? self::memoAdds($before, get_object_vars($this), ['memos', 'memoMarks', 'readsKeys', 'animating', 'wakeAt']) : null;
        $n->memo = 1;
        if ($adds === null) {
            if (($old = $this->memos[$id]['node'] ?? null) !== null && $old !== $n && $old->placed === $this->frameNumber) { self::detach($old, -1); }
            unset($this->memos[$id]);
            return false;
        }
        $old = $this->memos[$id] ?? null;
        if ($old !== null && $old['node'] !== $n && $old['node']->placed === $this->frameNumber) {
            self::detach($old['node'], -1); // placed in a pass before this one, which detach() passed over
        }
        $this->memos[$id] = ['deps' => $deps, 'ctx' => $ctx, 'style' => $style, 'node' => $n, 'adds' => $adds, 'ids' => $old['ids'] ?? [], 'area' => $old['area'] ?? null,
            'states' => [], 'frame' => $this->frameNumber, 'keys' => $readKeys];
        return true;
    }

    /**
     * What a build added to an object's state, from its properties before and after it: by array, its entries added
     * (appended to a list), or null when it changed anything else (a value, an entry it had), but for those $skip names.
     * @return ?array<string, array{0: bool, 1: array}>
     */
    public static function memoAdds(array $before, array $after, array $skip): ?array
    {
        $adds = [];
        foreach ($after as $prop => $v) {
            if (in_array($prop, $skip, true)) { continue; }
            $b = $before[$prop] ?? null;
            if ($v === $b) { continue; }
            if (!is_array($v) || !is_array($b) || count($v) < count($b)) { return null; }
            if (array_is_list($b) && array_is_list($v)) {
                if (array_slice($v, 0, count($b)) !== $b) { return null; }
                $adds[$prop] = [true, array_slice($v, count($b))];
                continue;
            }
            $added = array_diff_key($v, $b);
            if (count($b) + count($added) !== count($v) || array_intersect_key($v, $b) !== $b) { return null; } // an entry it had changed
            $adds[$prop] = [false, $added];
        }
        return $adds;
    }

    /** Notes, as the frame is recorded, the ids under memo node $n and the room they show in, the states inside it, from $from on in the order of the frame's nodes. */
    private function memoRecorded(Node $n, int $from): void
    {
        if (!isset($this->memos[$n->id])) { return; }
        $m = &$this->memos[$n->id];
        $ids = []; $x0 = $y0 = INF; $x1 = $y1 = -INF; $states = [];
        foreach (array_slice($this->order, $from, null, true) as $id => $_) {
            $ids[$id] = true;
            $v = $this->visible[$id];
            if ($v[2] > 0 && $v[3] > 0) {
                $x0 = min($x0, $v[0]); $y0 = min($y0, $v[1]); $x1 = max($x1, $v[0] + $v[2]); $y1 = max($y1, $v[1] + $v[3]);
            }
            if (isset($this->scrolls[$id])) { $states[] = $this->scrolls[$id]; }
            if (isset($this->fields[$id])) { $states[] = $this->fields[$id][0]; }
            if (isset($this->areas[$id])) { $states[] = $this->areas[$id]; }
        }
        $area = $x0 === INF ? null : [$x0, $y0, $x1, $y1];
        if ($n->memo === 2 && $area !== null && $this->memoPointer !== null && ($m['area'] === null || !self::inArea($m['area'], $this->memoPointer)) && self::inArea($area, $this->memoPointer)) {
            unset($this->memos[$n->id]); // it moved under the pointer as it was placed again: built again in the next frame, hovered
            $this->needsFrame = true;
            return;
        }
        $m['ids'] = $ids; $m['area'] = $area;
        $m['states'] = array_map(fn(object $s): array => [$s, self::stateSeen($s)], $states);
        $m['frame'] = $this->frameNumber;
        unset($m);
    }

    private static function inArea(array $a, array $p): bool
    {
        return $p[0] >= $a[0] - 2 && $p[0] <= $a[2] + 2 && $p[1] >= $a[1] - 2 && $p[1] <= $a[3] + 2;
    }

    /** How a state inside a memo is: what of it the build and the layout read. */
    private static function stateSeen(object $s): array
    {
        return match (true) {
            $s instanceof ListState => [$s->offset, $s->row, $s->first, $s->x, $s->lag, $s->count, $s->aligning],
            $s instanceof ScrollState => [$s->x, $s->y],
            default => [$s->text ?? null, $s->cursor ?? null, $s->anchor ?? null, $s->marked ?? null, $s->scrollY ?? null],
        };
    }

    /** Whether a node recorded inside the memo being recorded changes the runtime's state in place (a pose, a drag, a dialog…): it is not recorded from what it added. */
    private bool $recordTaint = false;

    /** Before memo node $n is recorded: what the runtime holds, to tell what its record adds. @return array{0: array, 1: bool} */
    private function memoRecordBegin(): array
    {
        $mark = [get_object_vars($this), $this->recordTaint];
        $this->recordTaint = false;
        return $mark;
    }

    /**
     * After memo node $n is recorded with $args (its place, the clip, the transform around it, the focus): what the record
     * added, kept to be given again while it is placed and recorded with the same, unless it changed the runtime's state
     * other than adding to it. The order of the frame's nodes, and of its paragraphs, count from where it comes.
     */
    private function memoRecordEnd(Node $n, array $args, array $mark): void
    {
        [$before, $taint] = $mark;
        $tainted = $this->recordTaint;
        $this->recordTaint = $taint || $tainted;
        if (!isset($this->memos[$n->id])) { return; }
        if ($tainted) { unset($this->memos[$n->id]['rec']); return; }
        $adds = []; $cursor = null; $inner = [];
        foreach (get_object_vars($this) as $prop => $v) {
            if ($prop === 'memos' || $prop === 'recordTaint') { continue; }
            $b = $before[$prop];
            if ($v === $b) { continue; }
            if ($prop === 'counter') { continue; }
            if ($prop === 'cursor') { $cursor = $v; continue; }
            if (!is_array($v) || !is_array($b) || count($v) <= count($b)) { unset($this->memos[$n->id]['rec']); return; } // changed, not added to
            $adds[$prop] = array_slice($v, count($b), null, !array_is_list($v)); // what came after: these arrays were empty of its ids
        }
        foreach ($this->memos[$n->id]['ids'] as $id => $_) {
            if ($id !== $n->id && isset($this->memos[$id])) { $inner[] = $id; } // memos inside it, kept with it
        }
        $this->memos[$n->id]['rec'] = ['args' => $args, 'adds' => $adds, 'cursor' => $cursor, 'counter' => $before['counter'], 'nodes' => $this->counter - $before['counter'],
            'texts' => count($before['texts']), 'inner' => $inner];
    }

    /** Records memo node $n, placed again, as it was recorded, when it is with the same $args; whether it was. */
    private function memoReplay(Node $n, array $args): bool
    {
        $r = $this->memos[$n->id]['rec'] ?? null;
        if ($r === null || $r['args'] !== $args) { return false; }
        $dOrder = $this->counter - $r['counter']; $dText = count($this->texts) - $r['texts'];
        foreach ($r['adds'] as $prop => $added) {
            if (($prop === 'order' || $prop === 'overlays') && $dOrder === 0 || $prop === 'textIndex' && $dText === 0) {
                if ($prop === 'overlays') { array_push($this->overlays, ...$added); } else { foreach ($added as $k => $v) { $this->$prop[$k] = $v; } } // where it was in the frame's order: as it was
            } elseif ($prop === 'order') {
                foreach ($added as $id => $o) { $this->order[$id] = $o + $dOrder; }
            } elseif ($prop === 'textIndex') {
                foreach ($added as $id => $i) { $this->textIndex[$id] = $i + $dText; }
            } elseif ($prop === 'overlays') {
                foreach ($added as $o) { $o[0] += $dOrder; $this->overlays[] = $o; }
            } elseif (array_is_list($added)) {
                array_push($this->$prop, ...$added);
            } else {
                foreach ($added as $k => $v) { $this->$prop[$k] = $v; } // not +=, which copies a typed property's array whole
            }
        }
        $this->counter += $r['nodes'];
        if ($r['cursor'] !== null) { $this->cursor = $r['cursor']; }
        $this->memos[$n->id]['frame'] = $this->frameNumber;
        foreach ($r['inner'] as $id) { if (isset($this->memos[$id])) { $this->memos[$id]['frame'] = $this->frameNumber; } }
        return true;
    }

    /**
     * What memo node $n is painted with, to be painted again as it was: its place, the clip, the opacity, the theme;
     * null when it is not kept (recorded otherwise than as it was kept, holding the focus, or drawn through a transform or a mask).
     */
    public function memoPaintArgs(Node $n, DisplayList $list, Theme $t): ?array
    {
        $m = $this->memos[$n->id] ?? null;
        if ($m === null || !isset($m['rec']) || ($this->focus !== null && isset($m['ids'][$this->focus])) || $list->world()->set || $list->mask() !== null) { return null; }
        return [$n->x, $n->y, $n->w, $n->h, $list->clip(), $list->opacity(), $t];
    }

    /** Paints memo node $n, placed again, as it was painted with $args: whether it was (what it drew from the atlases is there as it was). */
    public function memoRepaint(Node $n, array $args, DisplayList $list, TextPainter $text): bool
    {
        $p = $this->memos[$n->id]['paint'] ?? null;
        if ($p === null || $p['args'] !== $args || !$text->drawnAgain($p['drawn'])) { return false; }
        $list->copyIn($p['quads']);
        return true;
    }

    /** Before memo node $n is painted: where the display list and the painter are, to tell what painting it drew. @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int, 6: array{0: ?float, 1: ?float}, 7: bool} */
    public function memoPaintBegin(DisplayList $list, TextPainter $text): array
    {
        return [$list->n, $list->throughs + $list->transforms, self::specials($list), $text->drawnMark(), $this->places(), $this->frameAsks, [$this->wakeAt, $this->repaintAt], $this->needsFrame];
    }

    /** After memo node $n is painted with $args: its quads kept, when they drew through nothing else, asked for no frame, and drew nothing the window places. */
    public function memoPaintEnd(Node $n, array $args, array $mark, DisplayList $list, TextPainter $text): void
    {
        [$from, $throughs, $specials, $drawn, $places, $asks, $wake, $needs] = $mark;
        unset($this->memos[$n->id]['paint']);
        if (!isset($this->memos[$n->id]) || $list->throughs + $list->transforms !== $throughs || self::specials($list) !== $specials || $this->places() !== $places
            || $this->frameAsks !== $asks || [$this->wakeAt, $this->repaintAt] !== $wake || ($this->needsFrame && !$needs) || ($text instanceof Text && $text->repaint)) {
            return;
        }
        $this->memos[$n->id]['paint'] = ['args' => $args, 'quads' => $list->copyOut($from, $list->n), 'drawn' => $text->drawnSince($drawn)];
    }

    /** The quads of the list the renderer reads apart (glass, holes, wide colors, shaders), which it counts by their index. */
    private static function specials(DisplayList $list): int
    {
        return count($list->glass) + count($list->holes) + count($list->wide) + count($list->shaderRuns);
    }

    /** The places of native views and of what is painted under the materials, which the window reads. */
    private function places(): int
    {
        return count($this->webPlaces) + count($this->videoPlaces) + count($this->materialPlaces) + count($this->underPaints);
    }

    /** What memo $id's build added to the context's state (Context::memo), given back as it is placed again. @return array<string, array{0: bool, 1: array}> */
    public function memoContextAdds(int $id): array
    {
        return $this->memos[$id]['ctxAdds'] ?? [];
    }

    /** @param array<string, array{0: bool, 1: array}> $adds */
    public function memoKeepContext(int $id, array $adds): void
    {
        $this->memos[$id]['ctxAdds'] = $adds;
    }

    /** Forgets memo $id: built again (its node placed at another size, say). */
    public function memoForget(int $id): void
    {
        unset($this->memos[$id]);
    }

    /** Lets the memos no frame placed go, after the frame is recorded. */
    private function memoSweep(): void
    {
        foreach ($this->memos as $id => $m) {
            if ($m['frame'] < $this->frameNumber) { unset($this->memos[$id]); }
        }
    }
}
