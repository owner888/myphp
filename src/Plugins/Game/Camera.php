<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Game;

use Closure;
use MyPHP\UI\{Painter, Transform};

/**
 * A camera looking at a 2D world (mygo's game.Camera): the world's point
 * ($x, $y) shows at the middle of the view, $zoom times as large (0 is 1),
 * turned $rotation degrees, as raylib's Camera2D. World units are points at
 * a zoom of 1. Rects are [x, y, w, h].
 */
final class Camera
{
    public function __construct(
        public float $x = 0,
        public float $y = 0,
        public float $zoom = 0,
        public float $rotation = 0,
    ) {}

    private function z(): float
    {
        return $this->zoom == 0 ? 1.0 : $this->zoom;
    }

    /** The transform from the world to the window for the view $r, with the origin (0, 0): $p->transform($cam->transform($r), 0, 0, $draw). draw() is that, snapped and clipped. @param array{0: float, 1: float, 2: float, 3: float} $r */
    public function transform(array $r): Transform
    {
        $z = $this->z();
        $t = Transform::translation(-$this->x, -$this->y);
        if ($this->rotation != 0) {
            $t = $t->then(Transform::rotation(-$this->rotation));
        }
        return $t->then(Transform::scaling($z, $z))->then(Transform::translation($r[0] + $r[2] / 2, $r[1] + $r[3] / 2));
    }

    /**
     * Draws what $fn draws in world coordinates through the camera into $r,
     * clipped to it. Unturned, the world moves by whole pixels of the
     * screen, so that the edges of tiles drawn at whole world units stay on
     * them, while parts of bitmaps, which Painter::imageRegion places where
     * they are under a transform, glide.
     * @param array{0: float, 1: float, 2: float, 3: float} $r
     */
    public function draw(Painter $p, array $r, Closure $fn): void
    {
        $t = $this->transform($r);
        if ($this->rotation == 0) {
            $z = $this->z();
            $s = $p->scale();
            $snap = fn(float $v): float => round($v * $s) / $s;
            $t = Transform::scaling($z, $z)->then(Transform::translation($snap($r[0] + $r[2] / 2 - $this->x * $z), $snap($r[1] + $r[3] / 2 - $this->y * $z)));
        }
        $p->clip($r, fn() => $p->transform($t, 0, 0, $fn));
    }

    /** The world's point at ($x, $y) of the view $r, from its top-left corner, as Input::pointer() gives it. @param array{0: float, 1: float, 2: float, 3: float} $r @return array{0: float, 1: float} */
    public function toWorld(array $r, float $x, float $y): array
    {
        $z = $this->z();
        $dx = ($x - $r[2] / 2) / $z; $dy = ($y - $r[3] / 2) / $z;
        if ($this->rotation != 0) {
            $a = deg2rad($this->rotation);
            [$dx, $dy] = [$dx * cos($a) - $dy * sin($a), $dx * sin($a) + $dy * cos($a)];
        }
        return [$this->x + $dx, $this->y + $dy];
    }

    /** The part of the world the camera shows in the view $r: the bounds of it when turned. A game draws only the tiles in it. @param array{0: float, 1: float, 2: float, 3: float} $r @return array{0: float, 1: float, 2: float, 3: float} */
    public function visible(array $r): array
    {
        $xs = $ys = [];
        foreach ([[0, 0], [$r[2], 0], [0, $r[3]], [$r[2], $r[3]]] as [$cx, $cy]) {
            [$xs[], $ys[]] = $this->toWorld($r, $cx, $cy);
        }
        return [min($xs), min($ys), max($xs) - min($xs), max($ys) - min($ys)];
    }
}
