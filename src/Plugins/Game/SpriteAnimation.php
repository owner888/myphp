<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Game;

use MyPHP\UI\{Image, ImageOptions, Painter};

/**
 * Frame-timed sprite sheet animation (inspired by Flutter Flame's SpriteAnimation).
 */
final class SpriteAnimation
{
    public int $currentIndex = 0;
    private float $timer = 0.0;
    public bool $playing = true;

    /**
     * @param list<array{0: float, 1: float, 2: float, 3: float}> $frames Source rects [sx, sy, sw, sh]
     */
    public function __construct(
        public readonly Image $image,
        public readonly array $frames,
        public float $stepTime = 0.1,
        public bool $loop = true,
    ) {}

    /**
     * Advances the animation clock by $dt seconds.
     */
    public function step(float $dt): void
    {
        $n = count($this->frames);
        if (!$this->playing || $n <= 1 || $this->stepTime <= 0.0) {
            return;
        }
        $this->timer += $dt;
        while ($this->timer >= $this->stepTime) {
            $this->timer -= $this->stepTime;
            if ($this->currentIndex + 1 < $n) {
                $this->currentIndex++;
            } elseif ($this->loop) {
                $this->currentIndex = 0;
            } else {
                $this->playing = false;
                break;
            }
        }
    }

    /**
     * Resets the animation to its first frame and starts playing.
     */
    public function reset(): self
    {
        $this->currentIndex = 0;
        $this->timer = 0.0;
        $this->playing = true;
        return $this;
    }

    /**
     * Whether a non-looping animation has reached its final frame.
     */
    public function done(): bool
    {
        return !$this->loop && !$this->playing;
    }

    /**
     * Returns the current frame's source rect [sx, sy, sw, sh].
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function currentRect(): array
    {
        if ($this->frames === []) {
            return [0.0, 0.0, (float)$this->image->w, (float)$this->image->h];
        }
        return $this->frames[$this->currentIndex] ?? $this->frames[0];
    }

    /**
     * Draws the current animation frame into $dst [x, y, w, h].
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $dst
     */
    public function draw(Painter $p, array $dst, ImageOptions $options = new ImageOptions): void
    {
        $p->imageRegion($this->image, $this->currentRect(), $dst, $options);
    }
}