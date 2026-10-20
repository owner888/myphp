<?php
declare(strict_types=1);

namespace MyPHP\UI;

use MyPHP\Text\Boundaries;

/**
 * Selecting and copying text that is not edited (mygo's textselection.go):
 * the paragraphs of a Selectable container share one selection, from a
 * rune of one to a rune of another in the order they were built, which the
 * pointer drags, double and triple clicks take by words and lines, Shift
 * and the arrows extend, and Copy copies, their parts joined by newlines. A
 * Selectable paragraph alone is a container of itself. A paragraph keeps
 * its node's id, so the selection outlives the frames that build it again,
 * and is cleared when an endpoint goes or its text changes other than
 * growing at the end.
 */
trait RuntimeSelection
{
    private TextSelection $selection;
    /** @var list<Node> the paragraphs in a selection's container, of last frame, in build order (mygo's texts) */
    private array $texts = [];
    /** @var array<int, int> each paragraph's index in $texts, by id */
    private array $textIndex = [];
    /** @var array<int, Node> the Selectable containers of last frame, by id */
    private array $selectScopes = [];
    /** @var array<int, true> the Unselectable nodes of last frame */
    private array $unselectables = [];
    /** @var array<int, array{0: int, 1: int}> the runes of each paragraph the selection holds, by id, as painted */
    private array $selectedRunes = [];
    /** @var array<int, string> the texts of the paragraphs the selection holds, when it was made, to see them change */
    private array $selectedSources = [];
    /** Whether the window is the key window, where a selection shows (mygo's windowFocus); the window sets it. */
    public bool $windowFocus = true;

    /** The scope of node $n's text for a selection, 0 for none: its own when Selectable alone, its container's, or none for text a control owns or Unselectable text (mygo's prepareSelectable). */
    private static function textScope(Node $n, ?Node $parent): int
    {
        if (($n->kind !== Node::TEXT && $n->kind !== Node::RICH) || $parent?->kind === Node::RICH || $n->unselectable || $n->focusable || $n->url !== null || $n->disabled) {
            return 0;
        }
        return $n->scope !== 0 ? $n->scope : ($n->selectable ? $n->id : 0);
    }

    /** Notes a node of the frame that a selection may span: a paragraph, a Selectable container, or an Unselectable node (mygo's collectSelectable). */
    private function recordSelectable(Node $n, ?Node $parent): void
    {
        if ($n->kind === Node::CONTAINER && $n->selectable) {
            $this->selectScopes[$n->id] = $n;
        }
        if ($n->unselectable) {
            $this->unselectables[$n->id] = true;
        }
        $scope = self::textScope($n, $parent);
        if ($scope !== 0 && $n->kind === Node::RICH && !$n->inert) {
            $n->textScope = $scope;
            $this->textIndex[$n->id] = count($this->texts);
            $this->texts[] = $n;
            if ($this->hovered($n->id)) {
                $this->cursor = 'ibeam';
            }
        }
    }

    private function forgetSelectable(): void
    {
        $this->texts = []; $this->textIndex = []; $this->selectScopes = []; $this->unselectables = [];
    }

    /** The rune count of paragraph $n's text. */
    private static function runeCount(Node $n): int
    {
        return count($n->textLayout()->runes);
    }

    /** @param array{0: int, 1: int} $p */
    private function pointIndex(array $p): int
    {
        $i = $this->textIndex[$p[0]] ?? -1;
        return $i >= 0 && $this->texts[$i]->textScope === $this->selection->scope ? $i : -1;
    }

    /** @param array{0: int, 1: int} $a @param array{0: int, 1: int} $b */
    private function comparePoints(array $a, array $b): int
    {
        $i = $this->pointIndex($a); $j = $this->pointIndex($b);
        return $i !== $j ? $i - $j : $a[1] - $b[1];
    }

    /** The selection's endpoints in order, and whether it holds anything; null when it has none in this frame's paragraphs (mygo's textSelectionBounds). @return ?array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}, 2: bool} */
    private function selectionBounds(): ?array
    {
        $t = $this->selection;
        if ($t->scope === 0 || $this->pointIndex($t->anchor) < 0 || $this->pointIndex($t->caret) < 0) {
            return null;
        }
        $d = $this->comparePoints($t->anchor, $t->caret);
        return $d > 0 ? [$t->caret, $t->anchor, true] : [$t->anchor, $t->caret, $d !== 0];
    }

    /**
     * The parts of the paragraphs from endpoint $a to $b, each one's text
     * and its runes held.
     * @param array{0: int, 1: int} $a @param array{0: int, 1: int} $b
     * @return list<array{0: Node, 1: int, 2: int}>
     */
    private function selectedParts(array $a, array $b): array
    {
        $out = [];
        $first = $this->pointIndex($a); $last = $this->pointIndex($b);
        for ($i = $first; $i <= $last; $i++) {
            $p = $this->texts[$i];
            if ($p->textScope !== $this->selection->scope) {
                continue;
            }
            $to = self::runeCount($p);
            $from = $i === $first ? min($a[1], $to) : 0;
            if ($i === $last) { $to = min($b[1], $to); }
            $out[] = [$p, $from, $to];
        }
        return $out;
    }

    /** Whether a selection in paragraph $n shows: the window is key and the focus is in its container (mygo's textSelectionVisible). */
    private function selectionVisible(Node $n): bool
    {
        if (!$this->windowFocus || $n->disabled) {
            return false;
        }
        $f = $this->focus === null ? null : ($this->textIndex[$this->focus] ?? null);
        return $f !== null && $this->texts[$f]->textScope === $n->textScope && $n->textScope === $this->selection->scope;
    }

    /** The runes of paragraph $id the selection holds and shows, for the painter; null for none. @return ?array{0: int, 1: int} */
    public function textSelectionOf(int $id): ?array
    {
        return $this->selectedRunes[$id] ?? null;
    }

    /** Projects the selection on this frame's paragraphs, clearing it when an endpoint went or the text it holds changed (mygo's syncTextSelection). */
    private function syncTextSelection(): void
    {
        $this->selectedRunes = [];
        $t = $this->selection;
        if ($t->scope === 0) {
            return;
        }
        if ($this->pointIndex($t->anchor) < 0 || $this->pointIndex($t->caret) < 0) {
            $this->clearSelection(); // an endpoint was removed, disabled or moved to another container
            return;
        }
        if ($t->dragging && $this->input->mouseDown) {
            $this->moveTextSelection($this->input->mouseX, $this->input->mouseY);
        }
        [$a, $b] = $this->selectionBounds();
        $sources = [];
        foreach ($this->selectedParts($a, $b) as [$p, $from, $to]) {
            $text = $p->plainText();
            $old = $this->selectedSources[$p->id] ?? null;
            if ($old !== null && $old !== $text && !str_starts_with($text, $old)) {
                $this->clearSelection(); // text only added at the end, as a reply being written, keeps it
                return;
            }
            $sources[$p->id] = $text;
            if ($from !== $to && $this->selectionVisible($p)) {
                $this->selectedRunes[$p->id] = [$from, $to];
            }
        }
        $this->selectedSources = $sources;
    }

    private function clearSelection(): void
    {
        $this->selection = new TextSelection();
        $this->selectedRunes = []; $this->selectedSources = [];
    }

    /** The point of paragraph $n nearest to (x, y): above its text its start, below its end (mygo's textHit). @return array{0: int, 1: int} */
    private function textHit(Node $n, float $x, float $y): array
    {
        [$lx, $ly] = $this->toLocal($n->id, $x, $y) ?? [0.0, 0.0];
        $l = $n->textLayout();
        if ($ly < 0) {
            return [$n->id, 0];
        }
        if ($ly >= $l->height) {
            return [$n->id, count($l->runes)];
        }
        return [$n->id, $l->indexAt($lx, $ly)];
    }

    /** The point of the paragraph of container $scope under (x, y), or of the nearest one's line that shows (mygo's textSelectionAt); null when none shows. @return ?array{0: int, 1: int} */
    private function textSelectionAt(int $scope, float $x, float $y): ?array
    {
        $top = null;
        foreach ($this->texts as $n) {
            if ($n->textScope === $scope && !$n->disabled && $this->under($n->id, $x, $y) && ($top === null || $this->order[$n->id] > $this->order[$top->id])) {
                $top = $n; // the topmost paragraph under the point
            }
        }
        if ($top !== null) {
            return $this->textHit($top, $x, $y);
        }
        $nearest = null; $distance = INF;
        foreach ($this->texts as $n) {
            [$vx, $vy, $vw, $vh] = $this->visible[$n->id] ?? [0, 0, 0, 0];
            if ($n->textScope !== $scope || $vw <= 0 || $vh <= 0) {
                continue;
            }
            foreach ($n->textLayout()->lines as $line) {
                [$bx, $by, $bw, $bh] = $n->world->bounds($n->x + $line->x, $n->y + $line->y, $line->width, $line->height);
                $x0 = max($bx, $vx); $y0 = max($by, $vy); $x1 = min($bx + $bw, $vx + $vw); $y1 = min($by + $bh, $vy + $vh);
                if ($x1 <= $x0 || $y1 <= $y0) {
                    continue;
                }
                $dx = max($x0 - $x, $x - $x1, 0); $dy = max($y0 - $y, $y - $y1, 0);
                if ($nearest === null || $dx * $dx + $dy * $dy < $distance) {
                    $nearest = $n; $distance = $dx * $dx + $dy * $dy;
                }
            }
        }
        return $nearest === null ? null : $this->textHit($nearest, $x, $y);
    }

    /** The edges of the word ($unit 2) or line (3) around rune $at of paragraph $id, or $at (mygo's textUnit). @return array{0: int, 1: int} */
    private function textUnit(int $id, int $at, int $unit): array
    {
        $n = $this->texts[$this->textIndex[$id]];
        $l = $n->textLayout();
        if ($unit === 2) {
            return self::boundaries($n)->wordAt($at);
        }
        if ($unit === 3) {
            if ($l->lines === []) {
                return [0, count($l->runes)];
            }
            $line = $l->lines[$l->lineAt($at)];
            return [$line->start, $line->end];
        }
        return [$at, $at];
    }

    private static function boundaries(Node $n): Boundaries
    {
        $b = new Boundaries();
        $b->reset($n->textLayout()->runes, $n->plainText());
        return $b;
    }

    /**
     * A press at (x, y), $clicks of them, starts a selection: on a
     * selectable paragraph, or in its container around them, but not on a
     * control, which keeps its press, nor on Unselectable nodes; with Shift
     * it extends the selection there is (mygo's pressTextSelection). The
     * paragraph pressed takes the focus.
     */
    private function pressTextSelection(float $x, float $y, int $mods, int $clicks): bool
    {
        $target = $this->pressTarget($x, $y);
        if ($target !== null && !isset($this->textIndex[$target])) {
            return false; // a control keeps its press
        }
        $scope = 0; $at = -1;
        foreach ($this->texts as $n) {
            if ($this->under($n->id, $x, $y) && $this->order[$n->id] > $at) {
                $scope = $n->disabled ? 0 : $n->textScope; $at = $this->order[$n->id];
            }
        }
        if ($at < 0) { // in a container, around its paragraphs: the innermost
            foreach ($this->selectScopes as $id => $_) {
                if ($this->under($id, $x, $y) && $this->order[$id] > $at) {
                    $scope = $id; $at = $this->order[$id];
                }
            }
        }
        if ($scope === 0) {
            return false;
        }
        foreach ($this->unselectables as $id => $_) {
            if ($this->order[$id] > $this->order[$scope] && $this->under($id, $x, $y)) {
                return false;
            }
        }
        $p = $this->textSelectionAt($scope, $x, $y);
        if ($p === null) {
            return false;
        }
        $t = $this->selection;
        if ($t->scope !== $scope || ($mods & Key::SHIFT) === 0 || $this->pointIndex($t->anchor) < 0) {
            $t = $this->selection = new TextSelection($scope, $p);
        }
        $t->caret = $p; $t->unit = min($clicks, 3); $t->dragging = true; $t->desiredX = null;
        if ($t->unit > 1) {
            [$a, $b] = $this->textUnit($p[0], $p[1], $t->unit);
            $t->anchor = [$p[0], $a]; $t->caret = [$p[0], $b];
        }
        $t->start = [$t->anchor, $t->caret];
        $this->focus = $p[0]; // the paragraph pressed, or the nearest one to a press around them
        $this->focusVisible = false;
        return true;
    }

    /** The pointer dragging a selection to (x, y): its caret there, by the unit it started with (mygo's moveTextSelection). */
    private function moveTextSelection(float $x, float $y): void
    {
        $t = $this->selection;
        $p = $this->textSelectionAt($t->scope, $x, $y);
        if ($p === null) {
            return;
        }
        if ($t->unit > 1) {
            [$a, $b] = $this->textUnit($p[0], $p[1], $t->unit);
            if ($this->comparePoints($p, $t->start[0]) < 0) {
                $t->anchor = $t->start[1]; $t->caret = [$p[0], $a];
            } else {
                $t->anchor = $t->start[0]; $t->caret = [$p[0], $b];
                if ($this->comparePoints($t->caret, $t->start[1]) < 0) {
                    $t->caret = $t->start[1];
                }
            }
        } else {
            $t->caret = $p;
        }
    }

    /** A drag near the top or bottom of a scrolling container around the paragraph it is in scrolls it (mygo's scrollTextSelection). */
    private function scrollTextSelection(): void
    {
        $t = $this->selection;
        if (!$t->dragging || !$this->input->mouseDown) {
            return;
        }
        $edge = 32.0;
        for ($id = $t->caret[0]; $id !== 0 && $id !== null; $id = $this->parents[$id] ?? 0) {
            $s = $this->scrolls[$id] ?? null;
            if ($s === null || !($this->scrollAxes[$id][1] ?? false)) {
                continue;
            }
            [, $y] = $this->toLocal($id, $this->input->mouseX, $this->input->mouseY) ?? [0.0, 0.0];
            $top = $y; $bottom = $this->rects[$id][3] - $y; $dy = 0.0;
            if ($top < $edge) {
                $dy = -min($edge - $top, $edge) / 2;
            } elseif ($bottom < $edge) {
                $dy = min($edge - $bottom, $edge) / 2;
            }
            if ($dy != 0) {
                $s->scrollBy($dy);
                $this->animating = true;
            }
            return;
        }
    }

    /** Copy and Select All on the focused paragraph; other editing commands do nothing to text that is not edited (mygo's textSelectionCommand). Whether the focus is a paragraph's. */
    private function textSelectionCommand(string $name, ?Node $s = null): bool
    {
        $s ??= $this->focusedText();
        if ($s === null) {
            return false;
        }
        if ($s->disabled) {
            return true;
        }
        switch ($name) {
            case 'selectAll':
                $first = null; $last = null;
                foreach ($this->texts as $p) {
                    if ($p->textScope === $s->textScope) { $first ??= $p; $last = $p; }
                }
                if ($first !== null) {
                    $this->selection = new TextSelection($s->textScope, [$first->id, 0]);
                    $this->selection->caret = [$last->id, self::runeCount($last)];
                }
                break;
            case 'copy':
                $bounds = $this->selectionBounds();
                if ($bounds === null || !$bounds[2] || $this->selection->scope !== $s->textScope) {
                    break;
                }
                $scope = $this->selectScopes[$s->textScope] ?? null;
                if ($scope?->copyData !== null) {
                    ($this->writeClipboardData)(($scope->copyData)($this->selectedTexts($s->textScope)));
                    break;
                }
                $out = [];
                foreach ($this->selectedParts($bounds[0], $bounds[1]) as [$p, $from, $to]) {
                    $out[] = mb_substr($p->plainText(), $from, $to - $from, 'UTF-8');
                }
                ($this->writeClipboard)(implode("\n", $out));
                break;
        }
        return true;
    }

    /** The paragraph that has the focus, in a selection's container; null for none. */
    private function focusedText(): ?Node
    {
        $i = $this->focus === null ? null : ($this->textIndex[$this->focus] ?? null);
        return $i === null ? null : $this->texts[$i];
    }

    /**
     * The parts of the paragraphs that the selection of container $scope
     * holds, in the order they were built, as of the last frame (mygo's
     * selectedTexts).
     * @return list<SelectedText>
     */
    public function selectedTexts(int $scope): array
    {
        $bounds = $this->selectionBounds();
        if ($bounds === null || !$bounds[2] || $this->selection->scope !== $scope) {
            return [];
        }
        $out = [];
        foreach ($this->selectedParts($bounds[0], $bounds[1]) as [$p, $from, $to]) {
            $out[] = new SelectedText($p->id, $p->plainText(), $from, $to);
        }
        return $out;
    }

    /** The paragraph after (1) or before (-1) that of point $p in the selection's container. @param array{0: int, 1: int} $p */
    private function neighboringText(array $p, int $direction): ?Node
    {
        for ($i = $this->pointIndex($p) + $direction; $i >= 0 && $i < count($this->texts); $i += $direction) {
            if ($this->texts[$i]->textScope === $this->selection->scope) {
                return $this->texts[$i];
            }
        }
        return null;
    }

    /**
     * The text system's commands of a key on the focused paragraph: Shift
     * with the arrows, Home and End extends the selection, across the
     * paragraphs of its container; the keys without Shift are left to
     * scroll (mygo's textSelectionKey with its read-only editor's keys).
     * Whether it took the command.
     */
    private function textSelectionKey(string $selector): bool
    {
        $s = $this->focusedText();
        if ($s === null) {
            return false;
        }
        if ($s->disabled) {
            return true;
        }
        if ($selector === 'selectAll:' || $selector === 'copy:') {
            return $this->textSelectionCommand(substr($selector, 0, -1));
        }
        if (!str_ends_with($selector, 'AndModifySelection:')) {
            return false;
        }
        $base = substr($selector, 0, -strlen('AndModifySelection:')) . ':';
        $t = $this->selection;
        if ($t->scope !== $s->textScope || $this->pointIndex($t->caret) < 0) {
            $t = $this->selection = new TextSelection($s->textScope, [$s->id, 0]);
        }
        $s = $this->texts[$this->textIndex[$t->caret[0]]];
        $l = $s->textLayout();
        $at = $t->caret[1];
        $n = count($l->runes);
        switch ($base) {
            case 'moveToBeginningOfDocument:': case 'moveToEndOfDocument:':
                if (in_array($this->keys[array_key_last($this->keys) ?? 0][1] ?? -1, [Key::HOME, Key::END], true)) { // Home and End: the line's edges, as mygo's keys; Cmd and the arrows the container's
                    $line = $l->lines === [] ? null : $l->lines[$l->lineAt($at)];
                    $t->caret = [$s->id, $base === 'moveToEndOfDocument:' ? ($line?->end ?? $n) : ($line?->start ?? 0)]; $t->desiredX = null;
                    return true;
                }
                $direction = $base === 'moveToEndOfDocument:' ? 1 : -1;
                for ($next = $this->neighboringText([$s->id, 0], $direction); $next !== null; $next = $this->neighboringText([$s->id, 0], $direction)) {
                    $s = $next;
                }
                $t->caret = [$s->id, $direction < 0 ? 0 : self::runeCount($s)]; $t->desiredX = null;
                return true;
            case 'moveUp:': case 'moveDown:':
                $direction = $base === 'moveUp:' ? -1 : 1;
                [$x, $y, $h] = $l->caret($at);
                $t->desiredX ??= $s->x + $x;
                $y += $h / 2 + $direction * $h;
                if ($y < 0 || $y >= $l->height) {
                    $next = $this->neighboringText($t->caret, $direction);
                    if ($next === null) {
                        $t->caret = [$s->id, $direction < 0 ? 0 : $n];
                        return true;
                    }
                    $s = $next; $l = $s->textLayout();
                    $y = $direction < 0 ? $l->height - 1 : 0.0;
                }
                $t->caret = [$s->id, $l->indexAt($t->desiredX - $s->x, $y)];
                return true;
        }
        $t->desiredX = null;
        if (($base === 'moveLeft:' && $at === 0) || ($base === 'moveRight:' && $at === $n)) { // across the boundary to the paragraph beside
            $next = $this->neighboringText($t->caret, $base === 'moveLeft:' ? -1 : 1);
            if ($next !== null) {
                $t->caret = [$next->id, $base === 'moveLeft:' ? self::runeCount($next) : 0];
                return true;
            }
        }
        $b = self::boundaries($s);
        $line = $l->lines === [] ? null : $l->lines[$l->lineAt($at)];
        $to = match ($base) {
            'moveLeft:', 'moveBackward:' => $b->prevGrapheme($at),
            'moveRight:', 'moveForward:' => $b->nextGrapheme($at),
            'moveWordLeft:', 'moveWordBackward:' => $b->prevWord($at),
            'moveWordRight:', 'moveWordForward:' => $b->nextWord($at),
            'moveToBeginningOfLine:', 'moveToLeftEndOfLine:', 'moveToBeginningOfParagraph:' => $line?->start ?? 0,
            'moveToEndOfLine:', 'moveToRightEndOfLine:', 'moveToEndOfParagraph:' => $line?->end ?? $n,
            default => null,
        };
        if ($to === null) {
            return true; // other moves cannot change text that is not edited
        }
        $t->caret = [$s->id, max(0, min($to, $n))];
        return true;
    }
}
