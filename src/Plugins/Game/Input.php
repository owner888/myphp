<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Game;

/**
 * What a game's player did, as a step sees it (mygo's game.Input): the
 * keys and pointer buttons held, and those pressed and released since the
 * step before, even if pressed and released between two steps. Keys are
 * Key codes, by position: Key::W is the key in W's place whatever the
 * layout, as games want for WASD.
 */
final class Input
{
    /** @var array<int, true> @internal */
    public array $held = [], $pressed = [], $released = [];
    /** @internal */
    public float $x = 0, $y = 0, $wheelX = 0, $wheelY = 0, $w = 0, $h = 0;
    /** @internal */
    public bool $inside = false, $focused = false;
    /** @var array{0: bool, 1: bool, 2: bool} @internal */
    public array $buttons = [false, false, false], $bPressed = [false, false, false], $bReleased = [false, false, false];

    /** Whether key $k is held. */
    public function down(int $k): bool { return isset($this->held[$k]); }

    /** Whether $k was pressed since the step before. */
    public function pressed(int $k): bool { return isset($this->pressed[$k]); }

    /** Whether $k was released since the step before. */
    public function released(int $k): bool { return isset($this->released[$k]); }

    /** Where the pointer is in the view, from its top-left corner, and whether it is over the view. @return array{0: float, 1: float, 2: bool} */
    public function pointer(): array { return [$this->x, $this->y, $this->inside]; }

    /** Whether pointer button $b (0 the primary, 1 the secondary, 2 the middle) is held. */
    public function pointerDown(int $b): bool { return $b >= 0 && $b < 3 && $this->buttons[$b]; }

    /** Whether button $b was pressed since the step before. */
    public function pointerPressed(int $b): bool { return $b >= 0 && $b < 3 && $this->bPressed[$b]; }

    /** Whether button $b was released since the step before. */
    public function pointerReleased(int $b): bool { return $b >= 0 && $b < 3 && $this->bReleased[$b]; }

    /** How far the wheel or touchpad scrolled since the step before, in points, down and right positive. @return array{0: float, 1: float} */
    public function wheel(): array { return [$this->wheelX, $this->wheelY]; }

    /** The view's size. @return array{0: float, 1: float} */
    public function size(): array { return [$this->w, $this->h]; }

    /** Whether the view has the keyboard, which it loses with its window or to another node: a game pauses without it, as the player is elsewhere. */
    public function focused(): bool { return $this->focused; }

    /** Forgets what happened before this step. @internal */
    public function step(): void
    {
        $this->pressed = $this->released = [];
        $this->bPressed = $this->bReleased = [false, false, false];
        $this->wheelX = $this->wheelY = 0;
    }

    /** Lets go of everything held, as when the window loses the keyboard, whose releases never come. @internal */
    public function releaseAll(): void
    {
        foreach ($this->held as $k => $_) {
            $this->released[$k] = true;
        }
        $this->held = [];
        foreach ($this->buttons as $b => $down) {
            if ($down) {
                $this->bReleased[$b] = true;
            }
        }
        $this->buttons = [false, false, false];
    }
}
