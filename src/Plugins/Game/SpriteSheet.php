<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Game;

use MyPHP\UI\{Image, ImageOptions, Painter};

/**
 * Slices a texture atlas / sprite sheet Image into a uniform grid of tiles or frames
 * (inspired by Flutter Flame's SpriteSheet), for zero-copy drawing via Painter::imageRegion().
 *
 *     $sheet = new SpriteSheet($image, tileWidth: 16, tileHeight: 16);
 *     $sheet->drawTile($p, col: 2, row: 0, dst: [100, 100, 32, 32]);
 *     $walkAnim = $sheet->animation(row: 1, fromCol: 0, count: 4, stepTime: 0.12);
 */
final class SpriteSheet
{
    public readonly int $columns;
    public readonly int $rows;

    public function __construct(
        public readonly Image $image,
        public readonly int $tileWidth,
        public readonly int $tileHeight,
        public readonly int $margin = 0,
        public readonly int $spacing = 0,
    ) {
        $tw = max(1, $this->tileWidth + $this->spacing);
        $th = max(1, $this->tileHeight + $this->spacing);
        $this->columns = max(1, intdiv(max(0, $this->image->w - $this->margin * 2 + $this->spacing), $tw));
        $this->rows = max(1, intdiv(max(0, $this->image->h - $this->margin * 2 + $this->spacing), $th));
    }

    /**
     * Returns the pixel source rect [sx, sy, sw, sh] for the tile at ($col, $row).
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function rect(int $col, int $row): array
    {
        $sx = $this->margin + $col * ($this->tileWidth + $this->spacing);
        $sy = $this->margin + $row * ($this->tileHeight + $this->spacing);
        return [(float)$sx, (float)$sy, (float)$this->tileWidth, (float)$this->tileHeight];
    }

    /**
     * Returns the pixel source rect [sx, sy, sw, sh] for a linear tile index (row-major).
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function rectByIndex(int $index): array
    {
        $col = $index % $this->columns;
        $row = intdiv($index, $this->columns);
        return $this->rect($col, $row);
    }

    /**
     * Draws the tile at ($col, $row) into $dst [x, y, w, h].
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $dst
     */
    public function drawTile(Painter $p, int $col, int $row, array $dst, ImageOptions $options = new ImageOptions): void
    {
        $p->imageRegion($this->image, $this->rect($col, $row), $dst, $options);
    }

    /**
     * Creates a sequenced SpriteAnimation from $count frames starting at ($fromCol, $row).
     */
    public function animation(int $row, int $fromCol, int $count, float $stepTime = 0.1, bool $loop = true): SpriteAnimation
    {
        $frames = [];
        for ($i = 0; $i < $count; $i++) {
            $col = $fromCol + $i;
            $r = $row + intdiv($col, $this->columns);
            $c = $col % $this->columns;
            $frames[] = $this->rect($c, $r);
        }
        return new SpriteAnimation($this->image, $frames, $stepTime, $loop);
    }
}