<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * Nodes given an ElementTransition (Context::transition) move from where
 * the last frame showed them to where the layout put them (mygo's
 * transition.go): after the layout of a frame, before it is recorded,
 * parents first, as one resizing lays out its children anew, which then
 * move themselves. Boxes are relative to the parent's, as mygo's are; the
 * layout here places nodes in the window, so a node moves with its
 * children (shiftTree) and resizes by laying them out at the size it shows
 * (Layout::arrange).
 */
trait RuntimeTransition
{
    /** @var array<int, TransitionState> the transitions, by node id */
    public array $trans = [];
    /** @var list<array{0: Node, 1: ElementTransition}> the transitions this pass's build asked for */
    public array $uses = [];
    /** The window's size as the last frame laid it out: changes that resizing it makes do not animate. */
    private float $laidW = -1.0, $laidH = -1.0;
    /** @var array<string|int, array{frame: int, id: int, box: array, colors: ?array}> where the node of each match showed in a frame, in the window, and its colors */
    private array $matched = [];

    /** Moves the nodes given a transition, and the copies of those gone with an exit, in the frame of root $root. */
    private function animateLayout(Node $root): void
    {
        $resized = $root->w != $this->laidW || $root->h != $this->laidH || $this->instant();
        $this->laidW = $root->w; $this->laidH = $root->h;
        if ($this->uses === [] && $this->trans === [] && $this->matched === []) {
            return;
        }
        // The frame's nodes: their parents and depths, and the nodes by id.
        $parentOf = []; $depthOf = []; $byId = [];
        $stack = [[$root, null, 0]];
        while ($stack !== []) {
            [$n, $p, $d] = array_pop($stack);
            $o = spl_object_id($n);
            $parentOf[$o] = $p; $depthOf[$o] = $d; $byId[$n->id] = $n;
            foreach ($n->kids as $k) {
                $stack[] = [$k, $n, $d + 1];
            }
        }
        $now = $this->now;
        $still = $this->reduceMotion;
        $uses = $this->uses;
        usort($uses, fn($a, $b) => $depthOf[spl_object_id($a[0])] <=> $depthOf[spl_object_id($b[0])]); // parents first; stable, so those of a depth in the order asked
        $moving = [];
        foreach ($uses as [$e, $t]) {
            $p = $parentOf[spl_object_id($e)] ?? null;
            if ($p === null) {
                continue; // the root's, or a node not in the frame
            }
            if ($e->gone) { // hidden: no box to animate to or from (mygo's Hidden)
                unset($this->trans[$e->id]);
                continue;
            }
            $r = $this->trans[$e->id] ?? null;
            if ($r === null) {
                $r = $this->trans[$e->id] = new TransitionState();
            } elseif ($r->built === $this->frameNumber) {
                if ($r->elem === $e) {
                    $r->t = $t; // the node's last transition counts
                }
                continue;
            }
            $r->fresh = $r->laid + 1 !== $this->frameNumber;
            $r->t = $t; $r->elem = $e; $r->parent = $p->id; $r->built = $this->frameNumber;
            $r->index = (int)array_search($e, $p->kids, true);
            $r->next = [$e->x - $p->x, $e->y - $p->y, $e->w, $e->h, 1.0, 1.0, 0.0];
            if ($r->ghost !== null) {
                // Built again as a copy of it was going: it comes back from where the copy shows, as fast as it went.
                $r->ghost = null; $r->fresh = false; $r->collapse = 0;
                $r->head($r->next, TransitionState::MOVE_ALL, $now);
            }
            $moving[] = [$e, $p, $r];
        }
        foreach ($moving as [$e, $p, $r]) {
            $next = $r->next;
            if ($r->fresh) {
                $r->rest($next, $now);
                // A node appearing in a parent that was there comes in; whole new parts of the interface, as a page, appear at once.
                $m = $r->t->enter;
                if ($m !== null && (!$still || $m->fades()) && !$resized && !isset($this->rects[$e->id]) && isset($this->rects[$p->id])) {
                    $axis = $still ? 0 : TransitionState::collapseAxis($m, $p);
                    $r->rest(TransitionState::moved($next, $m, $axis, $still), $now);
                    $r->collapse = $axis;
                    $r->head($next, TransitionState::MOVE_ALL, $now);
                }
            } elseif ($resized || ($still && ($r->moving & ~TransitionState::MOVE_ALPHA) !== 0)) {
                $r->rest($next, $now);
            } else {
                $props = $r->properties();
                $changed = 0;
                if (($props & TransitionState::PROP_POSITION) !== 0 && ($next[0] != $r->target[0] || $next[1] != $r->target[1])) {
                    $changed |= TransitionState::MOVE_POS;
                }
                if (($props & TransitionState::PROP_SIZE) !== 0 && ($next[2] != $r->target[2] || $next[3] != $r->target[3])) {
                    $changed |= TransitionState::MOVE_SIZE;
                }
                if ($still) {
                    $changed = 0;
                }
                if ($r->laid + 1 === $this->frameNumber) {
                    $r->follow($next, $props); // not back as its copy went: that heads for next already
                }
                if ($changed !== 0) {
                    $r->head($next, $changed, $now);
                }
            }
            $r->target = $next; $r->laid = $this->frameNumber;
            $shown = $r->moving !== 0 ? $r->sample($next, $now) : $next;
            if ($r->moving !== 0) {
                $this->animate();
            } else {
                $r->rest($next, $now);
            }
            $r->shown = $shown;
            $this->applyTransition($e, $p, $r, $shown);
        }
        $this->animateExits($byId, $now, $still, $resized);
        $this->animateMatches($now, $still);
        foreach ($moving as [$e, , $r]) {
            $this->animateColors($e, $r, $now);
        }
    }

    /** Shows $e, a child of $p, at box $s. */
    private function applyTransition(Node $e, Node $p, TransitionState $r, array $s): void
    {
        $x = $p->x + $s[0]; $y = $p->y + $s[1];
        if ($s[2] != $e->w || $s[3] != $e->h) {
            if ($r->collapse !== 0) {
                // Collapsing: the content keeps its layout, cut off.
                self::shiftTree($e, $x - $e->x, $y - $e->y);
                $e->w = max($s[2], 0.0); $e->h = max($s[3], 0.0);
                $e->clip = Node::CLIP_X | Node::CLIP_Y;
            } else {
                Layout::arrange($e, $x, $y, max($s[2], 0.0), max($s[3], 0.0));
            }
        } else {
            self::shiftTree($e, $x - $e->x, $y - $e->y);
        }
        if ($s[4] < 1) {
            $e->opacity *= max($s[4], 0.0);
        }
        if (($turn = TransitionState::turnOf($s)) !== null) {
            $e->transform = $e->transform->then($turn);
        }
    }

    /** Moves $n and what is inside it by ($dx, $dy). */
    private static function shiftTree(Node $n, float $dx, float $dy): void
    {
        if ($dx == 0 && $dy == 0) {
            return;
        }
        $n->x += $dx; $n->y += $dy;
        foreach ($n->kids as $k) {
            self::shiftTree($k, $dx, $dy);
        }
    }

    /**
     * Moves the copies of the nodes gone with an exit, under their parents,
     * and makes copies of those that just went: their nodes of the frame
     * before, which take neither room, the pointer nor the focus.
     * @param array<int, Node> $byId this frame's nodes
     */
    private function animateExits(array $byId, int $now, bool $still, bool $resized): void
    {
        // The copies go under their parents in the order the nodes had among their siblings, as they leave together (mygo's de135d4), not the order their transitions began in.
        $going = array_filter($this->trans, fn(TransitionState $r): bool => $r->built !== $this->frameNumber);
        uksort($going, fn(int $a, int $b): int => [$going[$a]->index, $a] <=> [$going[$b]->index, $b]);
        foreach ($going as $id => $r) {
            $exit = $r->t->exit;
            if ($r->ghost === null && ($exit === null || ($still && !$exit->fades()) || ($resized && $this->instant()) || $r->laid + 1 !== $this->frameNumber)) {
                unset($this->trans[$id]);
                continue;
            }
            $p = $byId[$r->parent] ?? null;
            if ($p === null) {
                unset($this->trans[$id]); // its parent went too
                continue;
            }
            if ($r->ghost === null) {
                $e = $r->elem;
                $r->ghost = $e; $r->elem = null;
                self::inert($e);
                $e->leaving = 1;
                $r->opacity = $e->opacity / max($r->shown[4], 0.001);
                $r->turn = $e->transform;
                $r->gone = $r->shown;
                $r->collapse = $still ? 0 : TransitionState::collapseAxis($exit, $p);
                $r->head(TransitionState::moved($r->gone, $exit, $r->collapse, $still), TransitionState::MOVE_ALL, $now); // from where it showed, as fast as it went
            }
            $s = $r->sample(TransitionState::moved($r->gone, $exit, $r->collapse, $still), $now);
            if ($r->moving === 0) {
                unset($this->trans[$id]);
                continue;
            }
            $g = $r->ghost;
            $p->ghosts[] = $g;
            Layout::arrange($g, $p->x + $s[0], $p->y + $s[1], $r->gone[2], $r->gone[3]);
            if ($s[2] != $r->gone[2] || $s[3] != $r->gone[3]) {
                $g->w = max($s[2], 0.0); $g->h = max($s[3], 0.0);
                $g->clip = Node::CLIP_X | Node::CLIP_Y;
            }
            $g->opacity = $r->opacity * max($s[4], 0.0);
            $g->transform = $r->turn;
            if (($turn = TransitionState::turnOf($s)) !== null) {
                $g->transform = $g->transform->then($turn);
            }
            $r->shown = $s;
            $this->animate();
        }
    }

    /** Makes $n, a node of the frame before going, and what is inside it, only show: no ring of the focus, no state of its own to the app. */
    private static function inert(Node $n): void
    {
        $n->focused = false; $n->focusVisible = false; $n->ghosts = [];
        foreach ($n->kids as $k) {
            self::inert($k);
        }
    }

    /** Moves the nodes of a transition's match appearing from where the node of their match showed in the frame before, in the window. */
    private function animateMatches(int $now, bool $still): void
    {
        foreach ($this->matched as $m => $rec) {
            if ($rec['frame'] + 1 < $this->frameNumber) {
                unset($this->matched[$m]);
            }
        }
        foreach ($this->uses as [$e, $t]) {
            $m = $t->match;
            $r = $this->trans[$e->id] ?? null;
            if ($m === null || $r === null || $r->elem !== $e || $r->built !== $this->frameNumber) {
                continue;
            }
            $box = [$e->x, $e->y, $e->w, $e->h];
            $rec = $this->matched[$m] ?? null;
            if ($still || $this->instant()) {
                $r->matching = false;
            } elseif ($r->fresh && $rec !== null && $rec['id'] !== $e->id && $rec['frame'] + 1 === $this->frameNumber) {
                $r->match = [];
                foreach ($rec['box'] as $v) {
                    $r->match[] = new MotionChannel($v, $now);
                }
                $r->matching = true;
                if ($rec['colors'] !== null) {
                    // Its colors come from the other's too.
                    $r->colorSet = true; $r->colorTarget = $rec['colors']; $r->colorShown = $rec['colors']; $r->colorFrame = $this->frameNumber - 1;
                }
            }
            $shown = $box;
            if ($r->matching) {
                $a = $r->animation();
                $v = []; $going = false;
                foreach ($r->match as $i => $ch) {
                    $ch->headFor($box[$i], $a, $now);
                    $ch->advance($now);
                    $going = $going || $ch->moving || $ch->pending;
                    $v[$i] = $ch->value;
                }
                if (!$going) {
                    $r->matching = false;
                } else {
                    $shown = [$v[0], $v[1], max($v[2], 0.0), max($v[3], 0.0)];
                    self::showAt($e, $shown);
                    $this->animate();
                }
            }
            $colors = $rec !== null && $rec['id'] === $e->id ? $rec['colors'] : null;
            $this->matched[$m] = ['frame' => $this->frameNumber, 'id' => $e->id, 'box' => $shown, 'colors' => $colors];
        }
    }

    /** Shows $e in the window's box $b, its content laid out at its size, as a transition's size lays it out on the way. */
    private static function showAt(Node $e, array $b): void
    {
        if ($b[2] == $e->w && $b[3] == $e->h) {
            self::shiftTree($e, $b[0] - $e->x, $b[1] - $e->y);
            return;
        }
        Layout::arrange($e, $b[0], $b[1], $b[2], $b[3]);
    }

    /** @internal Router: every node shows its colors at once the next time it paints, as one painted after a gap does: a node coming back with a page is not eased from the color of the node that held its place (mygo's forgetColors). */
    public function forgetColors(): void
    {
        foreach ($this->trans as $r) { $r->colorSet = false; }
    }

    /**
     * Moves $e's background and border colors as its transition asks: even
     * while the desktop asks for less motion, as changing colors moves
     * nothing. A color is [rgb, alpha]: no background is a transparent
     * one; a border of the theme's own (no border color) changes at once.
     */
    private function animateColors(Node $e, TransitionState $r, int $now): void
    {
        if (($r->properties() & TransitionState::PROP_COLORS) === 0 || $r->colorFrame === $this->frameNumber) {
            return;
        }
        $target = [$e->color === null ? [0, 0.0] : [$e->color, $e->alpha], $e->borderColor === null ? null : [$e->borderColor, 1.0]];
        if (!$r->colorSet || $r->colorFrame + 1 !== $this->frameNumber) {
            $r->colorMoving = false; // not shown in the frame before: its colors show at once
        } elseif ($target != $r->colorTarget && $this->instant()) {
            $r->colorMoving = false;
        } elseif ($target != $r->colorTarget) {
            $r->colorFrom = $r->colorShown; $r->colorMoving = true;
            $r->colorK = new MotionChannel(0.0, $now);
            $r->colorK->headFor(1.0, $r->animation(), $now);
        }
        $r->colorSet = true; $r->colorTarget = $target; $r->colorFrame = $this->frameNumber;
        $r->colorShown = $target;
        if ($r->colorMoving) {
            $r->colorK->advance($now);
            if (!$r->colorK->moving && !$r->colorK->pending) {
                $r->colorMoving = false;
            } else {
                $k = max(0.0, min($r->colorK->value, 1.0));
                $bg = Motion::mixPremultiplied($r->colorFrom[0], $target[0], $k);
                $border = $r->colorFrom[1] !== null && $target[1] !== null ? Motion::mixPremultiplied($r->colorFrom[1], $target[1], $k) : $target[1];
                $r->colorShown = [$bg, $border];
                [$e->color, $e->alpha] = $bg;
                if ($border !== null) { $e->borderColor = $border[0]; }
                $this->animate();
            }
        }
        if (($m = $r->t->match) !== null && isset($this->matched[$m]) && $this->matched[$m]['id'] === $e->id) {
            $this->matched[$m]['colors'] = $r->colorShown;
        }
    }

    /** Whether node $id's transition was moving it, or changing its colors, or its pose was moving it, in the frame before: false once it is where it goes (mygo's Transitioning). */
    public function transitioning(int $id): bool
    {
        $p = $this->poses[$id] ?? null;
        if ($p !== null && $p['frame'] + 1 >= $this->frameNumber && self::posing($p['ch'])) {
            return true;
        }
        $d = $this->dragRecs[$id] ?? null;
        if ($d !== null && $d->frame + 1 >= $this->frameNumber && $d->moving()) {
            return true; // a draggable node going to rest
        }
        $r = $this->trans[$id] ?? null;
        return $r !== null && $r->built + 1 >= $this->frameNumber && ($r->moving !== 0 || $r->colorMoving || $r->matching);
    }

    /** @var array<int, array{ch: list<MotionChannel>, frame: int}> the channels of each posed node's pose (Node::animatePose), and the last frame that posed it */
    public array $poses = [];
    /** @var array<int, ContentState> what each node with a content transition showed */
    public array $contents = [];

    /** @param list<MotionChannel> $ch */
    private static function posing(array $ch): bool
    {
        foreach ($ch as $c) {
            if ($c->moving || $c->pending) { return true; }
        }
        return false;
    }

    /** Heads $n's pose for the one the view asked for, as the frame is recorded, and sets where it shows (mygo's samplePose and showPose). */
    private function samplePose(Node $n): void
    {
        $now = $this->now;
        $to = $n->pose->vals();
        $r = $this->poses[$n->id] ?? null;
        if ($r === null || $r['frame'] + 1 < $this->frameNumber) {
            // Not posed in the frame before: it shows there at once.
            $r = ['ch' => array_map(fn(float $v) => new MotionChannel($v, $now), $to), 'frame' => 0];
        }
        $r['frame'] = $this->frameNumber;
        $still = $this->reduceMotion; $instant = $this->instant();
        foreach ($r['ch'] as $i => $ch) {
            if ($instant || ($still && $i < 4)) {
                $r['ch'][$i] = new MotionChannel($to[$i], $now);
                continue;
            }
            $ch->headFor($to[$i], $n->poseAnim, $now);
        }
        $this->poses[$n->id] = $r;
        $v = [];
        foreach ($r['ch'] as $i => $ch) {
            if ($ch->moving || $ch->pending) {
                $ch->advance($now); // one at rest stays where it is until headFor moves it on, which advances it first
            }
            $v[$i] = $ch->value;
        }
        $n->poseM = Affine::identity();
        if ($v[0] != 0 || $v[1] != 0 || $v[2] != 1 || $v[3] != 0) {
            $n->poseM = Affine::translation($v[0], $v[1])->mul(Affine::rotation($v[3]))->mul(Affine::scaling($v[2], $v[2]));
        }
        $n->poseFade = max(0.0, min($v[4], 1.0));
        if (self::posing($r['ch'])) {
            $this->animate();
        }
    }

    /**
     * Notes what $n shows now, from ($x, $y), in $color, as it paints, and
     * returns its record, how far its content changed, and whether it is
     * changing (mygo's contentStep); while it changes, frames keep coming.
     * @return array{0: ContentState, 1: float, 2: bool}
     */
    public function contentStep(Node $n, float $x, float $y, int $color): array
    {
        $now = $this->now;
        $r = $this->contents[$n->id] ?? null;
        if ($r === null || $r->frame + 1 < $this->frameNumber) {
            // Not painted in the frame before: it shows at once.
            $r = $this->contents[$n->id] = new ContentState();
            $r->frame = $this->frameNumber; $r->text = self::shownText($n); $r->svg = $n->svg; $r->image = $n->image; $r->draw = $n->draw;
            $r->k = new MotionChannel(1.0, $now);
            return [$r, 1.0, false];
        }
        if ($r->frame !== $this->frameNumber) {
            $r->frame = $this->frameNumber;
            $changed = $n->kind === Node::TEXT || $n->kind === Node::RICH ? self::shownText($n) !== $r->text : ($n->svg !== $r->svg || $n->image !== $r->image);
            if ($changed) {
                if ($this->instant()) {
                    $r->k = new MotionChannel(1.0, $now);
                } else {
                    $r->oldText = $r->text; $r->oldSvg = $r->svg; $r->oldImage = $r->image; $r->oldDraw = $r->draw;
                    $r->oldX = $x; $r->oldY = $y; $r->oldColor = $color;
                    $r->still = $this->reduceMotion;
                    $r->k = new MotionChannel(0.0, $now);
                    $r->k->headFor(1.0, $n->content->animation ?? ContentTransition::defaultAnimation(), $now);
                }
            }
            $r->text = self::shownText($n); $r->svg = $n->svg; $r->image = $n->image; $r->draw = $n->draw;
            $r->k->advance($now);
        }
        if (!$r->k->moving && !$r->k->pending) {
            $r->oldText = null; $r->oldSvg = null; $r->oldImage = null; $r->oldDraw = null;
            return [$r, 1.0, false];
        }
        $this->animationFrame(); // painting: the frame after is asked for here
        return [$r, max(0.0, min($r->k->value, 1.0)), true];
    }

    /** The text a text shows, a rich text's of its spans (one made rich by its style, or in a Selectable container). */
    private static function shownText(Node $n): ?string
    {
        return $n->kind === Node::RICH ? $n->plainText() : $n->text;
    }
}
