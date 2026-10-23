<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Game;

use MyPHP\UI\{InputEvent, Key, Painter};

/**
 * What a game's view keeps from frame to frame (mygo's loop): its input,
 * the time of the last frame and the time owed to steps, in nanoseconds.
 *
 * @internal
 */
final class Loop
{
    public Game $game;
    public Input $in;
    public ?int $last = null;
    public int $lag = 0;
    public bool $wantFocus = false;

    public function __construct()
    {
        $this->in = new Input;
    }

    public function input(InputEvent $ev): bool
    {
        $in = $this->in;
        switch ($ev->kind) {
            case InputEvent::KEY_DOWN:
            case InputEvent::KEY_UP:
                if ($ev->mods & (Key::COMMAND | Key::CONTROL | Key::OPTION) && $ev->kind === InputEvent::KEY_DOWN) {
                    return false; // the menus' shortcuts
                }
                $k = $ev->key;
                if ($ev->kind === InputEvent::KEY_DOWN) {
                    if (!$ev->repeat && !isset($in->held[$k])) {
                        $in->pressed[$k] = true;
                    }
                    $in->held[$k] = true;
                } elseif (isset($in->held[$k])) {
                    $in->released[$k] = true;
                    unset($in->held[$k]);
                }
                return true;
            case InputEvent::POINTER_DOWN:
            case InputEvent::POINTER_UP:
                [$in->x, $in->y] = [$ev->x, $ev->y];
                if ($ev->button >= 0 && $ev->button < 3) {
                    $down = $ev->kind === InputEvent::POINTER_DOWN;
                    if ($down && !$in->buttons[$ev->button]) {
                        $in->bPressed[$ev->button] = true;
                    } elseif (!$down && $in->buttons[$ev->button]) {
                        $in->bReleased[$ev->button] = true;
                    }
                    $in->buttons[$ev->button] = $down;
                }
                if ($ev->kind === InputEvent::POINTER_DOWN) {
                    $this->wantFocus = true;
                }
                return true;
            case InputEvent::POINTER_MOVE:
                [$in->x, $in->y] = [$ev->x, $ev->y];
                $in->inside = $ev->x >= 0 && $ev->y >= 0 && $ev->x < $in->w && $ev->y < $in->h;
                return true;
            case InputEvent::SCROLL:
                $in->wheelX += $ev->dx;
                $in->wheelY += $ev->dy;
                return true;
        }
        return false;
    }

    /** @param array{0: float, 1: float, 2: float, 3: float} $r */
    public function draw(Painter $p, array $r): void
    {
        $p->animationFrame();
        [$this->in->w, $this->in->h] = [$r[2], $r[3]];
        $step = intdiv(1_000_000_000, max(1, $this->game->rate()));
        $now = $p->now();
        if ($this->last !== null) {
            // Painting twice a frame, at its one time, runs no step twice.
            $this->lag += min(max($now - $this->last, 0), (int)(Game::MAX_CATCH_UP * 1e9));
        }
        $this->last = $now;
        while ($this->lag >= $step) {
            $this->game->update($this->in);
            $this->in->step();
            $this->lag -= $step;
        }
        $this->game->draw($p, $r, $this->lag / $step);
    }
}
