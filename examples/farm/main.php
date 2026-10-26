<?php
// Farm is a little farming game in native UI (mygo's examples/farm): walk
// a farm with the keyboard, till the grass, plant seeds, water them and
// harvest what grows, through a camera that follows you, as days turn to
// night, with sound and music. The game plugin runs it, in fixed steps
// (Game::view), and its camera follows the farmer (Camera). Everything it
// draws and plays is made by its code: pixel art in a pixelated sheet drawn
// with Painter::imageRegion, tinted by the hour, and sounds synthesized for
// MyPHP\Audio.
//
// Arrows or WASD walk, Space or E uses the tool (or harvests what is ripe),
// 1 2 3 or Q choose the tool, + and - zoom, and M plays or stops the music.
// With a gamepad: the left stick or the d-pad walks, A uses the tool, the
// shoulder buttons choose it, and Start plays or stops the music.
// Run: ./run.sh farm
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\Audio\{Audio, Decoder, Player, Sound};
use MyPHP\Random\Rand;
use MyPHP\Plugins\Game\{Camera, Game, Input};
use MyPHP\Plugins\Game\Gamepad\{Button, Gamepad, State};
use MyPHP\UI\{Content, Context, Image, ImageOptions, Key, Painter, Span};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

// The world's fixed step, as Game::view runs it: 60 a second, whatever the display's rate, so that the game plays the same on every display.
const STEP_TIME = 1.0 / Game::RATE;
// The camera's zooms: pixels of the screen per pixel of art, in points. Not all are whole numbers: pixelated sampling keeps the art's pixels square at any.
const ZOOMS = [2, 2.5, 3, 4, 5];

// The map, in tiles.
const MAP_W = 40;
const MAP_H = 30;
// Grounds, what stands on them, directions, tools.
const GRASS = 0, PATH = 1, SOIL = 2, WATER = 3;
const NONE = 0, TREE = 1, BUSH = 2, ROCK = 3, FENCE = 4;
const DOWN = 0, UP = 1, LEFT = 2, RIGHT = 3;
const HOE = 0, SEEDS = 1, CAN = 2;
const TOOL_NAMES = ['Hoe', 'Seeds', 'Watering can'];
// What happened, for sounds.
const EV_STEP = 0, EV_TILL = 1, EV_PLANT = 2, EV_WATER = 3, EV_HARVEST = 4, EV_NOTHING = 5;
// A crop's last stage; how long a watered crop takes to grow a stage, in seconds; how fast the farmer walks, in tiles a second; how long a day lasts, in seconds.
const RIPE = 3;
const GROW_TIME = 4.0;
const SPEED = 4.5;
const DAY_LENGTH = 150.0;

// The sheet is 8×8 cells of 16 pixels, drawn here pixel by pixel so that
// the example needs no files: tiles, crops, the farmer and the toolbar's
// icons. Its cells:
const CELL = 16;
const C_GRASS = 0, C_GRASS2 = 1, C_FLOWERS = 2, C_PATH = 3, C_SOIL = 4, C_WET_SOIL = 5, C_WATER0 = 6, C_WATER1 = 7,
    C_TREE_TOP = 8, C_BUSH = 9, C_ROCK = 10, C_FENCE = 11, C_SEED = 12, C_SPROUT = 13, C_GROWING = 14, C_RIPE = 15,
    C_TREE_TRUNK = 16, C_HOE = 17, C_SEEDS = 18, C_CAN = 19, C_COIN = 20, C_FRAME = 21, C_DROP = 22,
    // The farmer: 3 frames walking down, up and sideways (facing right).
    C_DOWN = 24, C_UP = 27, C_SIDE = 30;

/** A square of the map: its ground, what stands on it, and the crop growing in its soil. */
final class Tile
{
    public int $ground = GRASS;
    /** Of grass: plain, tufts or flowers. */
    public int $variant = 0;
    public int $object = NONE;
    /** Watered soil. */
    public bool $wet = false;
    /** The crop's stage, 0 to RIPE, or -1. */
    public int $crop = -1;
    /** When the crop grows next, if wet. */
    public float $grows = 0;
}

/** The game's state, advanced in fixed steps of simulated time. */
final class World
{
    /** @var list<list<Tile>> by row */
    public array $tiles = [];
    /** The farmer, by the middle of their feet, in tiles, and where they were a step before. */
    public float $x = 20.5, $y = 15.8, $px = 20.5, $py = 15.8;
    public int $facing = DOWN;
    public bool $moving = false;
    /** The distance walked, for the frames and the footsteps. */
    public float $walked = 0;
    public int $tool = HOE;
    public int $coins = 0;
    /** The directions held, from the keys, by DOWN, UP, LEFT, RIGHT; where a gamepad's stick leans, each from -1 to 1. */
    public array $held = [false, false, false, false];
    public array $stick = [0.0, 0.0];
    /** The simulated time, in seconds. */
    public float $now = 0;
    /** @var list<int> what happened in the last steps, for sounds */
    public array $events = [];

    /** Lays out a farm: a field of grass with a pond, a path, trees around it and some bushes and rocks, and the farmer by the house. */
    public function __construct()
    {
        $rng = Rand::pcg(7, 11); // Go's math/rand/v2, as mygo's: the same farm
        for ($y = 0; $y < MAP_H; $y++) {
            for ($x = 0; $x < MAP_W; $x++) {
                $t = new Tile();
                $r = $rng->intN(10);
                $t->variant = $r < 2 ? 1 : ($r < 3 ? 2 : 0);
                if ($x < 2 || $y < 2 || $x >= MAP_W - 2 || $y >= MAP_H - 2) { $t->object = TREE; }
                $this->tiles[$y][$x] = $t;
            }
        }
        // A pond.
        for ($y = 5; $y < 10; $y++) {
            for ($x = 27; $x < 35; $x++) {
                $dx = $x - 30.5; $dy = $y - 7;
                if ($dx * $dx / 16 + $dy * $dy / 6 < 1) { $this->tiles[$y][$x]->ground = WATER; }
            }
        }
        // A path from the south edge to the middle.
        for ($y = 16; $y < MAP_H - 2; $y++) {
            $this->tiles[$y][20]->ground = PATH;
            $this->tiles[$y][21]->ground = PATH;
        }
        // A fence along the field's north side, with a gap.
        for ($x = 8; $x < 18; $x++) {
            if ($x !== 12) { $this->tiles[11][$x]->object = FENCE; }
        }
        for ($i = 0; $i < 25; $i++) {
            $x = 2 + $rng->intN(MAP_W - 4); $y = 2 + $rng->intN(MAP_H - 4);
            $t = $this->tiles[$y][$x];
            if ($t->ground === GRASS && $t->object === NONE && ($x < 18 || $x > 23)) { $t->object = [BUSH, ROCK][$rng->intN(2)]; }
        }
    }

    /** Whether the farmer cannot stand on tile ($x, $y). */
    public function solid(int $x, int $y): bool
    {
        if ($x < 0 || $y < 0 || $x >= MAP_W || $y >= MAP_H) { return true; }
        $t = $this->tiles[$y][$x];
        return $t->ground === WATER || $t->object !== NONE;
    }

    /** Whether the farmer's feet, a box 0.6 by 0.3 tiles, overlap a solid tile at ($x, $y). */
    public function blocked(float $x, float $y): bool
    {
        $hw = 0.3; $hh = 0.15;
        foreach ([[$x - $hw, $y - $hh], [$x + $hw, $y - $hh], [$x - $hw, $y + $hh], [$x + $hw, $y + $hh]] as [$px, $py]) {
            if ($this->solid((int)floor($px), (int)floor($py))) { return true; }
        }
        return false;
    }

    /** Advances the world by $dt seconds. */
    public function step(float $dt): void
    {
        $this->now += $dt;
        $this->px = $this->x; $this->py = $this->y;
        $dx = ($this->held[RIGHT] ? 1 : 0) - ($this->held[LEFT] ? 1 : 0);
        $dy = ($this->held[DOWN] ? 1 : 0) - ($this->held[UP] ? 1 : 0);
        if ($dx !== 0 && $dy !== 0) { $dx *= M_SQRT1_2; $dy *= M_SQRT1_2; }
        if ($dx == 0 && $dy == 0) { [$dx, $dy] = $this->stick; } // a gamepad's stick, as far as it leans
        $this->moving = $dx != 0 || $dy != 0;
        if ($this->moving) {
            $this->facing = match (true) {
                abs($dy) >= abs($dx) && $dy > 0 => DOWN,
                abs($dy) >= abs($dx) => UP,
                $dx < 0 => LEFT,
                default => RIGHT,
            };
            // Each axis on its own, so that the farmer slides along walls.
            $before = $this->walked;
            $nx = $this->x + $dx * SPEED * $dt;
            if (!$this->blocked($nx, $this->y)) { $this->walked += abs($nx - $this->x); $this->x = $nx; }
            $ny = $this->y + $dy * SPEED * $dt;
            if (!$this->blocked($this->x, $ny)) { $this->walked += abs($ny - $this->y); $this->y = $ny; }
            // A footstep every 0.6 tiles.
            if (floor($this->walked / 0.6) != floor($before / 0.6)) { $this->events[] = EV_STEP; }
        }
        foreach ($this->tiles as $row) {
            foreach ($row as $t) {
                if ($t->crop >= 0 && $t->crop < RIPE && $t->wet && $this->now >= $t->grows) {
                    $t->crop++;
                    $t->wet = false;
                }
            }
        }
    }

    /** The tile in front of the farmer. @return array{0: int, 1: int} */
    public function target(): array
    {
        $x = (int)floor($this->x); $y = (int)floor($this->y);
        return match ($this->facing) {
            DOWN => [$x, $y + 1],
            UP => [$x, $y - 1],
            LEFT => [$x - 1, $y],
            default => [$x + 1, $y],
        };
    }

    /** Uses the tool on the tile in front of the farmer, or harvests a ripe crop there whatever the tool. */
    public function use(): void
    {
        [$x, $y] = $this->target();
        if ($x < 0 || $y < 0 || $x >= MAP_W || $y >= MAP_H) { $this->events[] = EV_NOTHING; return; }
        $t = $this->tiles[$y][$x];
        if ($t->crop === RIPE) {
            $t->crop = -1;
            $this->coins += 15;
            $this->events[] = EV_HARVEST;
        } elseif ($this->tool === HOE && $t->ground === GRASS && $t->object === NONE) {
            $t->ground = SOIL;
            $this->events[] = EV_TILL;
        } elseif ($this->tool === SEEDS && $t->ground === SOIL && $t->crop < 0) {
            $t->crop = 0;
            if ($t->wet) { $t->grows = $this->now + GROW_TIME; }
            $this->events[] = EV_PLANT;
        } elseif ($this->tool === CAN && $t->ground === SOIL) {
            if (!$t->wet) { $t->wet = true; $t->grows = $this->now + GROW_TIME; }
            $this->events[] = EV_WATER;
        } else {
            $this->events[] = EV_NOTHING;
        }
    }

    /** How light it is, from 0 at midnight to 1 by day, and the hour. @return array{0: float, 1: int} */
    public function daylight(): array
    {
        // The game starts at 8 in the morning.
        $t = fmod($this->now / DAY_LENGTH + 8.0 / 24, 1);
        $h = $t * 24;
        // Dark from 20 to 5, with an hour of dusk and of dawn.
        $light = match (true) {
            $h >= 21 || $h < 5 => 0.0,
            $h >= 20 => 21 - $h,
            $h < 6 => $h - 5,
            default => 1.0,
        };
        return [$light, (int)$h];
    }
}

/** The sheet's pixels, premultiplied RGBA, 8 cells a row. */
final class Pixels
{
    public string $rgba;

    public function __construct() { $this->rgba = str_repeat("\0", 8 * CELL * 8 * CELL * 4); }
}

/** Draws into one cell of the sheet. */
final class Cell
{
    public function __construct(private readonly Pixels $img, private readonly int $ox, private readonly int $oy) {}

    public static function at(Pixels $img, int $i): self { return new self($img, $i % 8 * CELL, intdiv($i, 8) * CELL); }

    /** @param array{0: int, 1: int, 2: int, 3?: int} $c */
    public function px(int $x, int $y, array $c): void
    {
        if ($x < 0 || $y < 0 || $x >= CELL || $y >= CELL) { return; }
        $a = $c[3] ?? 255;
        $i = (($this->oy + $y) * 8 * CELL + $this->ox + $x) * 4;
        $px = &$this->img->rgba; // written in place
        $px[$i] = chr(intdiv($c[0] * $a, 255)); $px[$i + 1] = chr(intdiv($c[1] * $a, 255)); $px[$i + 2] = chr(intdiv($c[2] * $a, 255)); $px[$i + 3] = chr($a);
    }

    public function rect(int $x, int $y, int $w, int $h, array $c): void
    {
        for ($j = $y; $j < $y + $h; $j++) { for ($i = $x; $i < $x + $w; $i++) { $this->px($i, $j, $c); } }
    }

    /** Pixel art given as rows of characters, each a color of $pal; spaces are transparent. @param array<string, array> $pal */
    public function rows(array $pal, string ...$art): void
    {
        foreach ($art as $y => $row) {
            for ($x = 0; $x < strlen($row); $x++) {
                if (isset($pal[$row[$x]])) { $this->px($x, $y, $pal[$row[$x]]); }
            }
        }
    }
}

function rgb(int $hex): array { return [$hex >> 16 & 0xff, $hex >> 8 & 0xff, $hex & 0xff]; }

/** The sheet's pixels: tiles, crops, the farmer and the toolbar's icons. */
function sheet(): Image
{
    $img = new Pixels();
    $rng = Rand::pcg(1, 2);
    [$grass, $grassDark, $grassLight] = [rgb(0x6abe30), rgb(0x4b9a2a), rgb(0x8fd14f)];
    $speckle = function (Cell $c, array $base, array ...$spots) use ($rng): void {
        $c->rect(0, 0, CELL, CELL, $base);
        for ($i = 0; $i < 18; $i++) { $c->px($rng->intN(CELL), $rng->intN(CELL), $spots[$rng->intN(count($spots))]); }
    };
    $speckle(Cell::at($img, C_GRASS), $grass, $grassDark, $grassLight);
    $g2 = Cell::at($img, C_GRASS2);
    $speckle($g2, $grass, $grassDark, $grassLight);
    foreach ([[3, 5], [4, 4], [5, 5], [10, 11], [11, 10], [12, 11]] as [$x, $y]) { $g2->px($x, $y, $grassDark); $g2->px($x, $y + 1, $grassDark); }
    $fl = Cell::at($img, C_FLOWERS);
    $speckle($fl, $grass, $grassDark, $grassLight);
    foreach ([[4, 4, 0xfbf236], [11, 6, 0xffffff], [6, 11, 0xd95763], [12, 12, 0xfbf236]] as [$x, $y, $hex]) {
        foreach ([[0, 0], [-1, 0], [1, 0], [0, -1], [0, 1]] as [$dx, $dy]) { $fl->px($x + $dx, $y + $dy, rgb($hex)); }
        $fl->px($x, $y, rgb(0xdf7126));
    }
    $speckle(Cell::at($img, C_PATH), rgb(0xd9a066), rgb(0xc08a52), rgb(0xe8b77e));
    $soil = Cell::at($img, C_SOIL);
    $speckle($soil, rgb(0x8f563b), rgb(0x7a4630), rgb(0x9c6545));
    for ($y = 2; $y < CELL; $y += 4) { $soil->rect(1, $y, 14, 1, rgb(0x6e3f2a)); }
    $wet = Cell::at($img, C_WET_SOIL);
    $speckle($wet, rgb(0x5a3426), rgb(0x4a2a1e), rgb(0x663c2c));
    for ($y = 2; $y < CELL; $y += 4) { $wet->rect(1, $y, 14, 1, rgb(0x3e2218)); }
    foreach ([C_WATER0, C_WATER1] as $f => $i) {
        $w = Cell::at($img, $i);
        $w->rect(0, 0, CELL, CELL, rgb(0x5b6ee1));
        for ($y = 2; $y < CELL; $y += 5) {
            $x = ($y * 3 + $f * 4) % CELL;
            $w->rect($x, $y, 4, 1, rgb(0x8ea3f2));
            $w->rect(($x + 8) % CELL, $y + 2, 3, 1, rgb(0x4a5bc8));
        }
    }
    [$leaf, $leafDark, $leafLight] = [rgb(0x37946e), rgb(0x2a6b50), rgb(0x5fb68a)];
    Cell::at($img, C_TREE_TOP)->rows(['g' => $leaf, 'd' => $leafDark, 'l' => $leafLight],
        '                ',
        '     gggggg     ',
        '   ggllgggggg   ',
        '  gglllggggggg  ',
        ' ggllgggggggdgg ',
        ' gglggggggggddg ',
        'gggggggggggdddgg',
        'ggggggggggddddgg',
        'gggggggggdddddgg',
        'ggdgggggddddddgg',
        ' gddggddddddddg ',
        ' ggddddddddddgg ',
        '  ggddddddddgg  ',
        '   gggddddggg   ',
        '     gggggg     ',
        '                ');
    Cell::at($img, C_TREE_TRUNK)->rows(['b' => rgb(0x8f563b), 'd' => rgb(0x663931), 's' => [0, 0, 0, 70]],
        '      bbbb      ',
        '      bbdb      ',
        '      bbdb      ',
        '      bbdb      ',
        '      bbdb      ',
        '     bbbddb     ',
        '    bbb  ddb    ',
        '   ssssssssss   ');
    Cell::at($img, C_BUSH)->rows(['g' => $leaf, 'd' => $leafDark, 'l' => $leafLight, 'r' => rgb(0xd95763), 's' => [0, 0, 0, 60]],
        '',
        '',
        '',
        '     gggggg     ',
        '   ggllggggggg  ',
        '  gglgggrgggdgg ',
        '  gggggggggddgg ',
        ' ggrgggggdddrdgg',
        ' gggggggddddddgg',
        ' ggggggdddddddgg',
        '  ggddddddddddg ',
        '   ggdddddddgg  ',
        '    sssssssss   ');
    Cell::at($img, C_ROCK)->rows(['g' => rgb(0x9badb7), 'd' => rgb(0x696a6a), 'l' => rgb(0xcbdbfc), 's' => [0, 0, 0, 60]],
        '', '', '', '', '',
        '      gggg      ',
        '    gglllggg    ',
        '   gglgggggdg   ',
        '  gglggggggddg  ',
        '  ggggggggdddg  ',
        '  gggggddddddg  ',
        '   gdddddddddg  ',
        '    sssssssss   ');
    Cell::at($img, C_FENCE)->rows(['b' => rgb(0xd9a066), 'd' => rgb(0x8f563b)],
        '', '', '',
        '  bb        bb  ',
        '  bd        bd  ',
        'bbbbbbbbbbbbbbbb',
        'dddddddddddddddd',
        '  bd        bd  ',
        '  bd        bd  ',
        'bbbbbbbbbbbbbbbb',
        'dddddddddddddddd',
        '  bd        bd  ',
        '  bd        bd  ',
        '  bd        bd  ');
    $crop = ['s' => rgb(0xeec39a), 'g' => rgb(0x6abe30), 'd' => rgb(0x37946e), 'r' => rgb(0xd95763), 'o' => rgb(0xac3232), 'h' => rgb(0xf7a6b0)];
    Cell::at($img, C_SEED)->rows($crop,
        '', '', '', '', '', '', '',
        '     s    s     ',
        '                ',
        '        s       ',
        '   s            ',
        '           s    ');
    Cell::at($img, C_SPROUT)->rows($crop,
        '', '', '', '', '', '',
        '       g g      ',
        '       ggg      ',
        '        g       ',
        '        g       ',
        '        d       ');
    Cell::at($img, C_GROWING)->rows($crop,
        '', '', '',
        '      g   g     ',
        '     ggg ggg    ',
        '      ggggg     ',
        '    g  ggg  g   ',
        '    ggggggggg   ',
        '      ggggg     ',
        '        g       ',
        '        d       ',
        '        d       ');
    Cell::at($img, C_RIPE)->rows($crop,
        '',
        '      g   g     ',
        '     ggg ggg    ',
        '      ggdgg     ',
        '     rrrrrrr    ',
        '    rrhrrrrrr   ',
        '   rrhrrrrrrrr  ',
        '   rrrrrrrrrrr  ',
        '   rrrrrrrrror  ',
        '   rrrrrrrrroo  ',
        '    rrrrrrrroo  ',
        '     rrooooo    ',
        '       ddd      ');
    Cell::at($img, C_HOE)->rows(['m' => rgb(0x9badb7), 'l' => rgb(0xcbdbfc), 'b' => rgb(0x8f563b), 'd' => rgb(0x663931)],
        '',
        '   mmmmm        ',
        '  mllllmm       ',
        '  mmmmmbmm      ',
        '        bd      ',
        '         bd     ',
        '          bd    ',
        '           bd   ',
        '            bd  ',
        '             bd ',
        '              b ');
    Cell::at($img, C_SEEDS)->rows(['p' => rgb(0xeec39a), 'd' => rgb(0xd9a066), 'g' => rgb(0x6abe30), 's' => rgb(0x663931)],
        '',
        '     dddddd     ',
        '     pppppp     ',
        '    pppppppp    ',
        '   pppggppppp   ',
        '   ppgggggppp   ',
        '   pppgggpppp   ',
        '   pppppppppp   ',
        '   pppppppppp   ',
        '   ppsppsppsp   ',
        '   pppppppppp   ',
        '    dddddddd    ');
    Cell::at($img, C_CAN)->rows(['m' => rgb(0x5b6ee1), 'l' => rgb(0x8ea3f2), 'd' => rgb(0x3f3f74)],
        '',
        '',
        '      mmmmm     ',
        '     m     m    ',
        ' m   mmmmmmm    ',
        '  m mllmmmmmm   ',
        '   mmlmmmmmmm   ',
        '    mlmmmmmmmm  ',
        '    mmmmmmmmmm  ',
        '    mmmmmmmmdd  ',
        '    dddddddddd  ');
    Cell::at($img, C_COIN)->rows(['y' => rgb(0xfbf236), 'o' => rgb(0xdf7126), 'l' => rgb(0xffffff)],
        '',
        '',
        '     oooooo     ',
        '    oyyyyyyo    ',
        '   oyllyyyyyo   ',
        '   oylyyoyyyo   ',
        '   oyyyyoyyyo   ',
        '   oyyyyoyyyo   ',
        '   oyyyyoyyyo   ',
        '   oyyyyyyyyo   ',
        '    oyyyyyyo    ',
        '     oooooo     ');
    $frame = Cell::at($img, C_FRAME);
    $white = [255, 255, 255, 220];
    for ($i = 0; $i < 4; $i++) {
        foreach ([[$i, 0], [0, $i], [15 - $i, 0], [15, $i], [$i, 15], [0, 15 - $i], [15 - $i, 15], [15, 15 - $i]] as [$x, $y]) { $frame->px($x, $y, $white); }
    }
    Cell::at($img, C_DROP)->rows(['b' => rgb(0x8ea3f2), 'l' => rgb(0xffffff)],
        '', '', '',
        '       b        ',
        '      bbb       ',
        '     bbbbb      ',
        '     blbbb      ',
        '     bbbbb      ',
        '      bbb       ');
    farmer($img);
    return Image::fromPixels(8 * CELL, 8 * CELL, $img->rgba)->pixelated();
}

/** Draws the farmer walking: 3 frames down, up and sideways. */
function farmer(Pixels $img): void
{
    $pal = [
        'h' => rgb(0xe8c15a), 'H' => rgb(0xb48a2f), 'k' => rgb(0xf2c79a), 'e' => rgb(0x222034),
        'r' => rgb(0x8f563b), 'b' => rgb(0x4a7bd1), 'B' => rgb(0x30559c), 'p' => rgb(0x6b4a2f), 's' => rgb(0x3b2a20),
        'S' => [0, 0, 0, 60],
    ];
    $head = [
        'down' => ['     hhhhhh     ', '    hhhhhhhh    ', '  HHHHHHHHHHHH  ', '     kkkkkk     ', '     kekkek     ', '     kkkkkk     '],
        'up' => ['     hhhhhh     ', '    hhhhhhhh    ', '  HHHHHHHHHHHH  ', '     rrrrrr     ', '     rrrrrr     ', '     krrrrk     '],
        'side' => ['     hhhhhh     ', '    hhhhhhhh    ', '   HHHHHHHHHHHHH', '    rrrkkkk     ', '    rrkkkekk    ', '    rrkkkkk     '],
    ];
    $body = [
        'down' => ['    bbbbbbbb    ', '   kbbbbbbbbk   ', '   kbBbbbbBbk   ', '    bbbbbbbb    ', '    pppppppp    '],
        'side' => ['     bbbbbb     ', '     bbbbbbb    ', '     bbkbbbb    ', '     bbkbbb     ', '     pppppp     '],
    ];
    $body['up'] = $body['down'];
    $legs = [
        ['    pp    pp    ', '    ss    ss    ', '   SSSSSSSSSS   '],
        ['    pp     pp   ', '    ss     ss   ', '   SSSSSSSSSS   '],
        ['   pp     pp    ', '   ss     ss    ', '   SSSSSSSSSS   '],
    ];
    foreach (['down', 'up', 'side'] as $d => $name) {
        for ($f = 0; $f < 3; $f++) {
            Cell::at($img, C_DOWN + $d * 3 + $f)->rows($pal, '', '', ...$head[$name], ...$body[$name], ...$legs[$f]);
        }
    }
}

/** A cell of the sheet, drawn flipped and tinted as the game needs. */
final class Sprite
{
    public bool $flipX = false;
    public ?int $tint = null;

    public function __construct(public readonly int $cell) {}

    /** Draws it stretched into $dst, a part of the sheet (imageRegion). */
    public function draw(Painter $p, Image $sheet, array $dst): void
    {
        $p->imageRegion($sheet, [$this->cell % 8 * CELL, intdiv($this->cell, 8) * CELL, CELL, CELL], $dst, new ImageOptions(flipX: $this->flipX, tint: $this->tint));
    }
}

/** The sound of a step of the music, made as it plays: a slow, gentle loop in C major, chords under a pentatonic melody. */
final class Tune implements Decoder
{
    public const RATE = 22050;
    public const BEAT = 0.5; // seconds: 120 beats a minute
    public const BEATS = 32;
    // The melody, in semitones above C5 for each half beat, -1 resting.
    private const MELODY = [
        7, -1, 4, -1, 2, 4, 7, -1, 9, -1, 7, -1, 4, -1, -1, -1,
        2, -1, 4, -1, 7, 4, 2, -1, 0, -1, 2, -1, 4, -1, -1, -1,
        7, -1, 9, -1, 12, -1, 9, 7, 4, -1, 7, -1, 9, -1, -1, -1,
        12, -1, 9, -1, 7, 4, 2, -1, 4, -1, 2, -1, 0, -1, -1, -1,
    ];
    // The chords, a bar of 4 beats each: C, Am, F, G, as roots in semitones above C3 and their thirds and fifths.
    private const CHORDS = [[0, 4, 7], [-3, 0, 4], [-7, -3, 0], [-5, -1, 2], [0, 4, 7], [-3, 0, 4], [-7, -3, 0], [-5, -1, 2]];

    private int $pos = 0;

    public function sampleRate(): int { return self::RATE; }
    public function channels(): int { return 2; }
    public function len(): int { return (int)(self::BEATS * self::BEAT * self::RATE); }
    public function setPosition(int $frame): void { $this->pos = $frame; }

    private static function freq(int $semi, float $base): float { return $base * 2 ** ($semi / 12); }

    public function read(int $frames): array
    {
        $out = [];
        $end = min($this->len(), $this->pos + $frames);
        for (; $this->pos < $end; $this->pos++) {
            $sec = $this->pos / self::RATE;
            $beat = $sec / self::BEAT;
            // The melody: a soft plucked tone for each of its half beats.
            $lead = 0.0;
            $i = (int)($beat * 2);
            if (self::MELODY[$i] >= 0) {
                $nt = $sec - $i * self::BEAT / 2;
                $f = self::freq(self::MELODY[$i], 523.25);
                $lead = (sin(2 * M_PI * $f * $nt) + 0.3 * sin(4 * M_PI * $f * $nt)) * exp(-$nt * 4) * min($nt * 200, 1);
            }
            // The chord of the bar, swelling and fading.
            $bar = (int)($beat / 4);
            $swell = sin(M_PI * ($sec - $bar * 4 * self::BEAT) / (4 * self::BEAT));
            $pad = 0.0;
            foreach (self::CHORDS[$bar] as $semi) {
                $f = self::freq($semi, 130.81);
                $pad += sin(2 * M_PI * $f * $sec) + 0.25 * sin(2 * M_PI * 2 * $f * $sec);
            }
            $pad *= $swell / 3;
            $out[] = 0.18 * $lead + 0.12 * $pad;
            $out[] = 0.13 * $lead + 0.15 * $pad;
        }
        return $out;
    }
}

/** The game's effects, synthesized once, and its music, made as it plays. */
final class Sounds
{
    private const SFX_RATE = 32000;
    /** @var array<int, Sound> by event */
    private array $sfx = [];
    public Player $music;
    private Rand $rng;

    public function __construct()
    {
        $rng = $this->rng = Rand::pcg(3, 5);
        $noise = fn(): float => $rng->float64() * 2 - 1;
        $this->sfx = [
            EV_STEP => self::synth(0.07, fn(float $t) => $noise() * exp(-$t * 60) * 0.5),
            EV_TILL => self::synth(0.18, fn(float $t) => (0.6 * sin(2 * M_PI * 90 * $t * (1 - $t * 2)) + 0.5 * $noise()) * exp(-$t * 25)),
            EV_PLANT => self::synth(0.12, fn(float $t) => sin(2 * M_PI * (700 - 2500 * $t) * $t) * exp(-$t * 30) * 0.5),
            EV_WATER => self::synth(0.35, fn(float $t) => $noise() * (0.5 + 0.5 * sin(2 * M_PI * 23 * $t)) * exp(-$t * 8) * 0.25),
            EV_HARVEST => self::synth(0.5, self::chime(...)),
            EV_NOTHING => self::synth(0.06, fn(float $t) => sin(2 * M_PI * 160 * $t) * exp(-$t * 50) * 0.3),
        ];
        // The first bar is an intro: then the rest loops.
        $this->music = Audio::newPlayer(new Tune())->setLoopRange(4 * Tune::BEAT, 0)->setVolume(0.35);
    }

    /** A sound of $d seconds from $f, a function of the time. */
    private static function synth(float $d, Closure $f): Sound
    {
        $s = [];
        $n = (int)($d * self::SFX_RATE);
        for ($i = 0; $i < $n; $i++) {
            $t = $i / self::SFX_RATE;
            $s[] = $f($t) * min($t / 0.002, ($d - $t) / 0.01, 1); // no click at either end
        }
        return Audio::newSound(self::SFX_RATE, 1, $s);
    }

    /** Two bell-like notes, a fifth apart. */
    private static function chime(float $t): float
    {
        $bell = static fn(float $f, float $t): float => $t < 0 ? 0.0 : (sin(2 * M_PI * $f * $t) + 0.4 * sin(2 * M_PI * $f * 2.76 * $t) * exp(-$t * 12)) * exp(-$t * 7);
        return 0.3 * ($bell(1046.5, $t) + $bell(1568, $t - 0.09));
    }

    /** Plays the sounds of what happened, a little different each time, as games vary effects that play often. @param list<int> $events */
    public function play(array $events, float $pan): void
    {
        foreach ($events as $e) {
            $volume = match ($e) { EV_STEP => 0.35, EV_NOTHING => 0.6, default => 1.0 };
            $this->sfx[$e]->newPlayer()->setVolume($volume)->setPan($pan)->setSpeed(0.94 + $this->rng->float64() * 0.12)->play();
        }
    }
}

/** Where the camera looks along an axis to follow a point at $at, within a world as long as $world in a view as long as $view. */
function follow(float $at, float $world, float $view): float
{
    return $world <= $view ? $world / 2 : min(max($at, $view / 2), $world - $view / 2);
}

/** The game Game::view runs: update() steps the world with the input, draw() draws it. */
final class Farm extends Game
{
    public World $w;
    public Image $sheet;
    public int $zoom = 2;
    public bool $music = false;
    /** The last state of the first gamepad, to tell the buttons pressed since. */
    private State $pad;

    /** $snd plays the game's sounds; tests play none. */
    public function __construct(public readonly ?Sounds $snd = null)
    {
        $this->w = new World();
        $this->sheet = sheet();
        $this->pad = new State();
        if ($snd !== null) { $this->setMusic(true); }
    }

    public function setMusic(bool $on): void
    {
        $this->music = $on;
        if ($this->snd === null) { return; }
        $on ? $this->snd->music->play() : $this->snd->music->pause();
    }

    /** The keys and the gamepad walk and act, then what happened sounds. */
    public function update(Input $in): void
    {
        $w = $this->w;
        $w->held = [
            DOWN => $in->down(Key::DOWN) || $in->down(Key::S),
            UP => $in->down(Key::UP) || $in->down(Key::W),
            LEFT => $in->down(Key::LEFT) || $in->down(Key::A),
            RIGHT => $in->down(Key::RIGHT) || $in->down(Key::D),
        ];
        match (true) {
            $in->pressed(Key::SPACE), $in->pressed(Key::E), $in->pressed(Key::RETURN) => $w->use(),
            $in->pressed(Key::N1) => $w->tool = HOE,
            $in->pressed(Key::N2) => $w->tool = SEEDS,
            $in->pressed(Key::N3) => $w->tool = CAN,
            $in->pressed(Key::Q), $in->pressed(Key::TAB) => $w->tool = ($w->tool + 1) % 3,
            $in->pressed(Key::EQUAL) => $this->zoom = min($this->zoom + 1, count(ZOOMS) - 1),
            $in->pressed(Key::MINUS) => $this->zoom = max($this->zoom - 1, 0),
            $in->pressed(Key::M) => $this->setMusic(!$this->music),
            default => null,
        };
        $this->readPad();
        $w->step(STEP_TIME);
        if ($this->snd !== null && $w->events !== []) { $this->snd->play($w->events, 0); }
        $w->events = [];
    }

    /** The first gamepad: its stick or d-pad walks, and its buttons act as the keys do when just pressed. */
    private function readPad(): void
    {
        $pads = Gamepad::pads();
        if ($pads === []) {
            $this->w->stick = [0.0, 0.0];
            $this->pad = new State();
            return;
        }
        $s = $pads[0]->state();
        [$x, $y] = $s->leftStick();
        if ($s->pressed(Button::Left)) { $x = -1; } elseif ($s->pressed(Button::Right)) { $x = 1; }
        if ($s->pressed(Button::Up)) { $y = -1; } elseif ($s->pressed(Button::Down)) { $y = 1; }
        $m = hypot($x, $y);
        if ($m > 1) { $x /= $m; $y /= $m; }
        $this->w->stick = [(float)$x, (float)$y];
        match (true) {
            $s->justPressed($this->pad, Button::South) => $this->w->use(),
            $s->justPressed($this->pad, Button::RightShoulder) => $this->w->tool = ($this->w->tool + 1) % 3,
            $s->justPressed($this->pad, Button::LeftShoulder) => $this->w->tool = ($this->w->tool + 2) % 3,
            $s->justPressed($this->pad, Button::Start) => $this->setMusic(!$this->music),
            default => null,
        };
        $this->pad = $s;
    }

    /** The color the world takes at the light of the hour: the night is blue. */
    public function tint(): int
    {
        [$light] = $this->w->daylight();
        $mix = fn(float $night, float $day): int => (int)($night + ($day - $night) * $light);
        return $mix(80, 255) << 16 | $mix(95, 255) << 8 | $mix(160, 255);
    }

    /**
     * Draws the world through a camera that follows the farmer, within the
     * map, and the HUD over it. $alpha places the farmer between their last
     * two steps, for motion as smooth as the display's frames.
     */
    public function draw(Painter $p, array $r, float $alpha): void
    {
        $w = $this->w;
        $fx = $w->px + ($w->x - $w->px) * $alpha;
        $fy = $w->py + ($w->y - $w->py) * $alpha;
        $zoom = ZOOMS[$this->zoom];
        $cam = new Camera(follow($fx * CELL, MAP_W * CELL, $r[2] / $zoom), follow($fy * CELL, MAP_H * CELL, $r[3] / $zoom), $zoom);
        $tint = $this->tint();
        $cam->draw($p, $r, fn() => $this->drawWorld($p, $tint, $fx, $fy));
        $this->drawHUD($p, $r);
    }

    /**
     * Draws the map in the art's pixels: the ground, crops, then what
     * stands, row by row with the farmer among them, at ($fx, $fy), so that
     * what stands south of another covers it.
     */
    private function drawWorld(Painter $p, int $tint, float $fx, float $fy): void
    {
        $w = $this->w;
        $put = function (int $i, float $x, float $y) use ($p, $tint): void {
            $s = new Sprite($i);
            $s->tint = $tint;
            $s->draw($p, $this->sheet, [$x, $y, CELL, CELL]);
        };
        $waterFrame = (int)($w->now * 2) % 2 === 1 ? C_WATER1 : C_WATER0;
        foreach ($w->tiles as $y => $row) {
            foreach ($row as $x => $t) {
                $tx = $x * CELL; $ty = $y * CELL;
                match ($t->ground) {
                    GRASS => $put([C_GRASS, C_GRASS2, C_FLOWERS][$t->variant], $tx, $ty),
                    PATH => $put(C_PATH, $tx, $ty),
                    SOIL => $put($t->wet ? C_WET_SOIL : C_SOIL, $tx, $ty),
                    WATER => $put($waterFrame, $tx, $ty),
                };
                if ($t->crop >= 0) { $put(C_SEED + $t->crop, $tx, $ty); }
            }
        }
        // The tile the tool would act on.
        [$tx, $ty] = $w->target();
        if ($tx >= 0 && $ty >= 0 && $tx < MAP_W && $ty < MAP_H) { $put(C_FRAME, $tx * CELL, $ty * CELL); }
        $feet = (int)floor($fy);
        foreach ($w->tiles as $y => $row) {
            foreach ($row as $x => $t) {
                $tx = $x * CELL; $ty = $y * CELL;
                match ($t->object) {
                    TREE => [$put(C_TREE_TRUNK, $tx, $ty), $put(C_TREE_TOP, $tx, $ty - CELL + 4)],
                    BUSH => $put(C_BUSH, $tx, $ty),
                    ROCK => $put(C_ROCK, $tx, $ty),
                    FENCE => $put(C_FENCE, $tx, $ty),
                    default => null,
                };
            }
            if ($y === $feet) { $this->farmer($tint)->draw($p, $this->sheet, [$fx * CELL - CELL / 2, $fy * CELL - CELL + 2, CELL, CELL]); }
        }
    }

    /**
     * The farmer's sprite: their frame of walking, facing where they go,
     * flipped to face left. They move smoothly: under the camera's
     * transform, sprites are where they are, not on whole pixels.
     */
    private function farmer(int $tint): Sprite
    {
        $w = $this->w;
        $frame = $w->moving ? 1 + (int)($w->walked / 0.35) % 2 : 0;
        $s = new Sprite(match ($w->facing) { DOWN => C_DOWN, UP => C_UP, default => C_SIDE } + $frame);
        $s->flipX = $w->facing === LEFT;
        $s->tint = $tint;
        return $s;
    }

    /** Draws, over the world, the coins and the hour, and the toolbar. */
    private function drawHUD(Painter $p, array $r): void
    {
        $w = $this->w;
        $panel = 0x14121e; $panelAlpha = 0.75;
        $white = 0xffffff; $muted = 0xc8c8d7;
        [, $hour] = $w->daylight();
        $p->fill([$r[0] + 12, $r[1] + 12, 168, 40], $panel, 10, $panelAlpha);
        (new Sprite(C_COIN))->draw($p, $this->sheet, [$r[0] + 18, $r[1] + 16, 32, 32]);
        $p->text($r[0] + 54, $r[1] + 21, (string)$w->coins, 18, $white);
        $p->text($r[0] + 110, $r[1] + 23, sprintf('%02d:00', $hour), 15, $muted);
        // The toolbar, at the bottom, the tool in use framed.
        $slot = 52; $gap = 8;
        $bw = 3 * $slot + 2 * $gap + 16;
        $bx = $r[0] + ($r[2] - $bw) / 2; $by = $r[1] + $r[3] - $slot - 50;
        $p->fill([$bx, $by, $bw, $slot + 16], $panel, 12, $panelAlpha);
        foreach ([C_HOE, C_SEEDS, C_CAN] as $i => $icon) {
            $x = $bx + 8 + $i * ($slot + $gap);
            $box = [$x, $by + 8, $slot, $slot];
            if ($i === $w->tool) {
                $p->fill($box, 0xffffff, 8, 0.15);
                $p->stroke($box, 0xfbf236, 8, 2);
            }
            (new Sprite($icon))->draw($p, $this->sheet, [$x + 4, $by + 12, $slot - 8, $slot - 8]);
            $p->text($x + 4, $by + 10, (string)($i + 1), 11, $muted);
        }
        $hint = TOOL_NAMES[$w->tool] . ' · Space uses it · 1 2 3 or Q switch · + − zoom · M music';
        $label = new Span($hint, 12, color: $muted);
        [$tw, $th] = $p->measureText(0, $label);
        $p->fill([$r[0] + ($r[2] - $tw) / 2 - 10, $r[1] + $r[3] - 30, $tw + 20, $th + 10], $panel, ($th + 10) / 2, $panelAlpha);
        $p->richText($r[0] + ($r[2] - $tw) / 2, $r[1] + $r[3] - 25, 0, $label);
    }
}

function view(Context $c, Farm $g): void
{
    Game::view($c, $g)->fill()->background(0x4b9a2a)->label('Farm');
}

App::setName('Farm');
$g = new Farm(new Sounds());
App::whenReady(function () use ($g) {
    new Window(new WindowOptions(title: 'Farm', width: 960, height: 640, content: Content::view(fn(Context $c) => view($c, $g))));
});
App::run();
