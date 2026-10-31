<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Game;

use MyPHP\UI\{Context, InputEvent, Node, Painter};

/**
 * What games need beyond what apps do, opt-in, out of MyPHP's core UI
 * (mygo's plugins/game): a game that view() runs in a native UI node,
 * which updates its world in fixed steps with the player's input and
 * draws it every frame, as Ebitengine's Update and Draw; a camera that
 * looks at a 2D world (Camera), shown pixel-perfect at any size
 * (integerFit). Gamepad\Gamepad reads controllers.
 *
 *     final class Farm extends Game
 *     {
 *         public float $x = 0, $y = 0;
 *         public function update(Input $in): void { if ($in->down(Key::D)) { $this->x += 2; } }
 *         public function draw(Painter $p, array $r, float $alpha): void
 *         {
 *             (new Camera($this->x, $this->y, zoom: 3))->draw($p, $r, fn() => drawWorld($p));
 *         }
 *     }
 *
 *     Content::view(function (Context $c) use ($farm) { Game::view($c, $farm)->grow = 1; })
 */
abstract class Game
{
    /** How many steps a second view() runs a game, unless it overrides rate(). */
    public const RATE = 60;
    /** The most time steps catch up on after a pause, as a window out of sight, in seconds (MonoGame's MaxElapsedTime). */
    public const MAX_CATCH_UP = 0.25;

    /** Advances the game one step, of 1/rate() seconds, with the input since the step before. */
    abstract public function update(Input $in): void;

    /**
     * Draws the game into $r, [x, y, w, h]. $alpha, from 0 to 1, is how far
     * the frame's time is into the next step: a game may draw what moves
     * that far between its last two places, for motion smoother than its
     * steps.
     * @param array{0: float, 1: float, 2: float, 3: float} $r
     */
    abstract public function draw(Painter $p, array $r, float $alpha): void;

    /** How many steps a second the game runs. */
    public function rate(): int
    {
        return self::RATE;
    }

    /**
     * A node that runs $g: update() in fixed steps of its rate, with the
     * keys, pointer and wheel the node takes as Input, and draw() every
     * frame, as long as it shows (mygo's game.View). It takes the keyboard
     * focus as it appears and when clicked, and lets go of what was held
     * when it or its window loses the keyboard. Size it as any node, as
     * with $node->grow = 1 or size().
     */
    public static function view(Context $c, self $g): Node
    {
        $e = $c->column();
        $c->end();
        $e->focusable()->autoFocus();
        $rt = $c->runtime;
        $l = $rt?->local($e->id, fn() => new Loop) ?? new Loop;
        $l->game = $g;
        if ($l->wantFocus) {
            $l->wantFocus = false;
            $rt?->focusOn($e->id);
        }
        $l->in->focused = $c->focused($e) && ($rt?->windowFocus ?? true);
        if (!$l->in->focused) {
            $l->in->releaseAll();
        }
        return $e->handleInput(fn(InputEvent $ev): bool => $l->input($ev))
            ->draw($l->draw(...));
    }

    /**
     * Where a canvas of $w × $h pixels shows in $r as large as it can with a
     * whole number of the screen's pixels for each of its own, at least
     * one, centered (letterboxed): pixel-perfect scaling, for $p's screen,
     * as Godot's viewport stretch and Stardew Valley draw pixel art. Returns
     * [the rect, that number]. A game draws its world into a UI\Canvas of a
     * logical size, as 480×270, and shows it there:
     *
     *     [$dst] = Game::integerFit($p, 480, 270, $r);
     *     $p->image($world->bitmap(), $dst, Node::FILL_BOX);
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $r
     * @return array{0: array{0: float, 1: float, 2: float, 3: float}, 1: int}
     */
    public static function integerFit(Painter $p, int $w, int $h, array $r): array
    {
        if ($w <= 0 || $h <= 0) {
            return [[0.0, 0.0, 0.0, 0.0], 0];
        }
        $s = $p->scale();
        $k = max((int)min($r[2] * $s / $w, $r[3] * $s / $h), 1);
        $dw = $w * $k / $s; $dh = $h * $k / $s;
        // On whole pixels of the screen, as the snapped image is.
        $x = (int)(($r[0] + ($r[2] - $dw) / 2) * $s + 0.5) / $s;
        $y = (int)(($r[1] + ($r[3] - $dh) / 2) * $s + 0.5) / $s;
        return [[$x, $y, $dw, $dh], $k];
    }
}
