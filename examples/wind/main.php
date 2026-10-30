<?php
// Wind is the texture behind the composer of the Claude desktop app's
// Code tab, redrawn in native UI (mygo's examples/wind): a field of
// monospaced characters that a wind lights in passing gusts, curling,
// streaked and thinning around the content, while each character steps
// through a set of its own. Moving the pointer warms the air along its
// path; a click blows a ripple out from it, tinted, and every third click
// blows a heart.
//
// It draws as the app's WebGL does, with two shaders (UI\Shader) in Go's
// syntax: wind.kage works out the wind at two samples a cell, a pixel each
// of a canvas, and glyphs.kage lights each pixel of the glyphs' ink, from
// an atlas the system's text engine rasterizes (Context::renderGlyphs), by
// the wind's samples around it, more where its edge faces the wind. The
// CPU only moves the clock and the pointer's blows on, a few hundred
// numbers a frame. Every visit has a wind of its own, picked by a random
// seed; ⌘R starts another. After three minutes without input the wind
// settles and the texture fades out, to come back at the next move.
// Run: ./run.sh wind
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Canvas, Content, Context, DisplayList, Easing, Image, InputEvent, Key, Node, Painter, TextPainter, TextState, TextStyle, Theme};
use MyPHP\UI\Shader\{Options, Shader};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

const WINDOW_W = 1100;
const WINDOW_H = 720;

// The texture's randomness on the CPU: PCG hashes of whole numbers and
// value noise on them, as wind.kage computes them, for how far the wind
// has carried its gusts, and the hash of the visit's seed that picks its
// wind. The shaders compute the rest. Whole numbers are uint32s, as the
// shaders' uints: kept to 32 bits after each step.

const U32 = 0xffffffff;

/** pcg hashes $v, as PCG's RXS-M-XS output of one step from $v. */
function pcg(int $v): int
{
    $s = ($v * 747796405 + 2891336453) & U32;
    $w = ((($s >> (($s >> 28) + 4)) ^ $s) * 277803737) & U32;
    return ($w >> 22) ^ $w;
}

/** $h as a fraction from 0 to 1, from its top 24 bits. */
function unit(int $h): float
{
    return ($h >> 8) * (1.0 / 16777216.0);
}

/** The fade of the noises between lattice points, whose first and second derivatives are 0 at both ends. */
function quintic(float $f): float
{
    return $f * $f * $f * ($f * ($f * 6 - 15) + 10);
}

function mix(float $a, float $b, float $t): float
{
    return $a + ($b - $a) * $t;
}

/** The noises of a visit, hashed with its seed. */
final class Noises
{
    public function __construct(public readonly int $seed) {}

    /**
     * Value noise from -1 to 1: a random value at each lattice point,
     * blended between them. The lattice point is the hashes of its four
     * rows in its two layers, from which a corner's hash is its column's.
     */
    public function value(float $x, float $y, float $z, int $salt): float
    {
        [$fx, $fy, $fz] = [floor($x), floor($y), floor($z)];
        [$ix, $iy, $iz] = [(int)$fx, (int)$fy, (int)$fz];
        $z0 = pcg(($iz & U32) ^ $this->seed ^ $salt);
        $z1 = pcg((($iz + 1) & U32) ^ $this->seed ^ $salt);
        [$y0, $y1] = [$iy & U32, ($iy + 1) & U32];
        [$x0, $x1] = [$ix & U32, ($ix + 1) & U32];
        [$a, $b, $c, $d] = [pcg($y0 ^ $z0), pcg($y1 ^ $z0), pcg($y0 ^ $z1), pcg($y1 ^ $z1)];
        [$wx, $wy, $wz] = [quintic($x - $fx), quintic($y - $fy), quintic($z - $fz)];
        $v = static fn(int $h): float => unit(pcg($h)) * 2 - 1;
        return mix(
            mix(mix($v($x0 ^ $a), $v($x1 ^ $a), $wx), mix($v($x0 ^ $b), $v($x1 ^ $b), $wx), $wy),
            mix(mix($v($x0 ^ $c), $v($x1 ^ $c), $wx), mix($v($x0 ^ $d), $v($x1 ^ $d), $wx), $wy),
            $wz);
    }
}

/** Scrambles the bits of $v, as the app's integer hash. */
function mix32(int $v): int
{
    $v = (($v ^ ($v >> 16)) * 569420461) & U32;
    $v = (($v ^ ($v >> 15)) * 1935289751) & U32;
    return $v ^ ($v >> 15);
}

/** Numbers from 0 to 1, a sequence of its own for each seed and salt. @return Closure(): float */
function random(int $seed, string $salt): Closure
{
    $n = mix32($seed);
    for ($i = 0; $i < strlen($salt); $i++) {
        $n = mix32($n ^ ord($salt[$i]));
    }
    return function () use (&$n): float {
        $n = ($n + 0x9E3779B9) & U32;
        return mix32($n) / 4294967296;
    };
}

function smoothstep(float $e0, float $e1, float $x): float
{
    $t = min(max(($x - $e0) / ($e1 - $e0), 0.0), 1.0);
    return $t * $t * (3 - 2 * $t);
}

/**
 * A visit's wind, which its seed picks: its look, the set of characters it
 * shows; the heading it blows toward, in radians with y down; its speed,
 * in DIPs a second; the length and width of a gust; and how far its path
 * bends, and the size of a bend.
 */
final class Wind
{
    public function __construct(
        public readonly int $seed,
        public readonly string $look,
        public readonly float $heading,
        public readonly float $speed,
        public readonly float $gustLength,
        public readonly float $gustWidth,
        public readonly float $bend,
        public readonly float $bendScale,
    ) {}

    /** The wind of $seed, with $look. */
    public static function of(int $seed, string $look): self
    {
        $next = random($seed, 'wind');
        $between = fn(float $lo, float $hi): float => $lo + ($hi - $lo) * $next();
        $heading = self::headingOf($next());
        return new self($seed, $look, $heading, $between(42, 64), $between(380, 560), $between(120, 190), $between(60, 110), $between(300, 440));
    }

    /**
     * The heading of a fraction $e: across the pane and down it, from 18°
     * to 42° below the horizontal, rightward for $e below 0.5 and leftward
     * above.
     */
    public static function headingOf(float $e): float
    {
        $lo = M_PI / 10;
        $a = $lo + (M_PI / 4.3 - $lo) * fmod($e * 2, 1);
        return $e < 0.5 ? $a : M_PI - $a;
    }

    /**
     * How far the wind has carried its gusts by its time, surging and
     * slackening as it goes; span() the span of its long swells over a
     * canvas of $cw×$ch DIPs, about one to it: what the app's vertex
     * shader computes, once a frame.
     */
    public function carried(float $time): float
    {
        return $this->speed * ($time + (new Noises($this->seed))->value($time * 0.09, 3.7, 0, 0x58));
    }

    public function span(float $cw, float $ch): float
    {
        return 0.58 * (abs(cos($this->heading)) * $cw + abs(sin($this->heading)) * $ch);
    }
}

// How the hand stirs the air: a click is a blow, whose front runs out
// from the pointer, and a moving pointer warms the air along its path.

// TAP_LIFE and WAKE_LIFE are how long a blow and a stretch of the path last, in seconds.
const TAP_LIFE = 3.6;
const WAKE_LIFE = 2.4;
// MAX_TAPS and MAX_WAKES are how many of each the air keeps.
const MAX_TAPS = 6;
const MAX_WAKES = 48;
// STIR and RIPPLE are how hard the pointer's moves and clicks stir the
// air, and SLOW_SPEED and FAST_SPEED the speeds of the pointer, in DIPs a
// second, between which a move goes from stirring nothing to stirring it all.
const STIR = 1;
const RIPPLE = 1;
const SLOW_SPEED = 60;
const FAST_SPEED = 800;
// A stretch of the path is laid once the pointer went MIN_STEP DIPs and
// MIN_GAP seconds from the last; a pause of REST_AFTER seconds starts the
// path anew; the pointer's speed follows its moves over SPEED_LAG seconds.
const MIN_STEP = 18;
const MIN_GAP = 0.035;
const REST_AFTER = 0.25;
const SPEED_LAG = 0.08;
// HEART_EVERY is how many clicks make a heart, a blow that runs out as a heart rather than a circle.
const HEART_EVERY = 3;

/** A click, or a stretch of the pointer's path: where, when and how hard; and for a stretch, which way it runs, with half its length. */
final class Blow
{
    public function __construct(
        public readonly float $x,
        public readonly float $y,
        public readonly float $at,
        public readonly float $force = 0,
        public readonly float $wayX = 0,
        public readonly float $wayY = 0,
    ) {}
}

/** What the pointer did, in DIPs of the pane and seconds of the texture's clock. */
final class Hand
{
    /** @var list<Blow> */
    public array $taps = [], $wakes = [];
    public int $clicks = 0;
    /** The pointer as it last moved, and as it was where the last stretch ended; $speed is its speed, smoothed. */
    public ?Blow $last = null, $from = null;
    public float $speed = 0;
    /** As of the frame: its time. */
    public float $now = 0;
    /**
     * And the warmth of the path, each stretch of it as the air feels it
     * now, for the shader's Wakes and Ways: its middle, how warm, one over
     * the square of how far that reaches; and which way it runs (a unit
     * vector) with half its length, and a 0.
     * @var list<float>
     */
    public array $warm = [], $ways = [];

    /**
     * Notes that the pointer moved to ($x, $y) at $t. A pause starts the
     * path anew; the app's does too as the pointer leaves the pane, which
     * MyPHP does not tell.
     */
    public function move(float $x, float $y, float $t): void
    {
        if ($this->last === null || $this->from === null || $t - $this->last->at > REST_AFTER) {
            $this->last = $this->from = new Blow($x, $y, $t);
            $this->speed = 0;
            return;
        }
        $dt = max($t - $this->last->at, 1.0 / 240);
        $this->speed += (hypot($x - $this->last->x, $y - $this->last->y) / $dt - $this->speed) * min(1, $dt / SPEED_LAG);
        $this->last = new Blow($x, $y, $t);
        $l = hypot($x - $this->from->x, $y - $this->from->y);
        if ($l < MIN_STEP || $t - $this->from->at < MIN_GAP) {
            return;
        }
        $force = STIR * smoothstep(0, 1, ($this->speed - SLOW_SPEED) / (FAST_SPEED - SLOW_SPEED));
        $from = $this->from;
        $this->from = $this->last;
        if ($force < 0.04) {
            return;
        }
        $this->wakes = self::keepLast($this->wakes, MAX_WAKES - 1);
        $this->wakes[] = new Blow(($x + $from->x) / 2, ($y + $from->y) / 2, $t, $force * $l / ($l + 90), ($x - $from->x) / 2, ($y - $from->y) / 2);
    }

    /** Notes a click at ($x, $y) at $t: every HEART_EVERY-th is a heart. */
    public function tap(float $x, float $y, float $t): void
    {
        $this->clicks++;
        $force = $this->clicks % HEART_EVERY === 0 ? -1.15 * RIPPLE : RIPPLE;
        $this->taps = self::keepLast($this->taps, MAX_TAPS - 1);
        $this->taps[] = new Blow($x, $y, $t, $force);
    }

    /** Whether a blow or a stretch of the path still stirs the air at $t; rippling() whether a blow does. */
    public function busy(float $t): bool
    {
        return $this->rippling($t) || self::anyAfter($this->wakes, $t - WAKE_LIFE);
    }

    public function rippling(float $t): bool
    {
        return self::anyAfter($this->taps, $t - TAP_LIFE);
    }

    /**
     * Readies the hand for a frame at $t: blows and stretches that died
     * down go, and each stretch's warmth is worked out, rising quickly and
     * falling slowly, reaching further as it ages, and weaker the older
     * the path behind it.
     */
    public function frame(float $t): void
    {
        $this->now = $t;
        $this->taps = self::keepAfter($this->taps, $t - TAP_LIFE);
        $this->wakes = self::keepAfter($this->wakes, $t - WAKE_LIFE);
        $this->warm = $this->ways = [];
        $older = MAX_WAKES - count($this->wakes);
        foreach ($this->wakes as $i => $w) {
            $age = max(0, $t - $w->at);
            $heat = $w->force * min(1, ($i + 1 + $older) / 8) * (1 - exp(-$age / 0.05)) * exp(-$age / 0.65);
            if ($heat < 0.002) {
                continue;
            }
            $reach = 50 + 40 * $age;
            $l = hypot($w->wayX, $w->wayY);
            $d = $l == 0 ? 1 : $l;
            array_push($this->warm, $w->x, $w->y, $heat, 1 / ($reach * $reach));
            array_push($this->ways, $w->wayX / $d, $w->wayY / $d, $l, 0.0);
        }
    }

    /** @param list<Blow> $b @return list<Blow> */
    private static function keepLast(array $b, int $n): array
    {
        return count($b) > $n ? array_slice($b, count($b) - $n) : $b;
    }

    /** @param list<Blow> $b @return list<Blow> */
    private static function keepAfter(array $b, float $t): array
    {
        return array_values(array_filter($b, fn(Blow $x) => $x->at > $t));
    }

    /** @param list<Blow> $b */
    private static function anyAfter(array $b, float $t): bool
    {
        foreach ($b as $x) {
            if ($x->at > $t) {
                return true;
            }
        }
        return false;
    }
}

// The characters an atlas holds, ASCII's printable ones and a middle dot, sixteen to a row.
const CHARSET = " !\"#$%&'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~·";

// The sets of characters a visit shows, each a sequence that a cell steps through from a place of its own.
const LOOKS = [
    ['mixed', 'ab0cAde1fEgh2iGjk3lHmn4oKpq5rMst6uRvw7xSyz8nT9eW'],
    ['lettered', 'abcd3efgRhijk7lmnAopqr0stuEvwxy5zaeKiou2gcS9'],
    ['figured', '0a1b2C3d4e5F6g7h8i9J0k1m2N3p4q5R6s7t8U9v0w1X2y3z'],
];

// The glyphs: GLYPH_SIZE DIPs, in a light weight on screens of 1.5 pixels
// a DIP or more and a regular one on others, where a light stroke would
// break up; in the system's monospaced font.
const GLYPH_SIZE = 9;
const LIGHT_WEIGHT = 300;
const FAMILY = 'ui-monospace, Menlo, Consolas, monospace';
// INK_FROM is the coverage from which a pixel counts in a glyph's box.
const INK_FROM = 8;

/**
 * The charset rasterized for cells of cellW×cellH pixels, white on
 * transparent, which glyphs.kage reads; each character's box, where its
 * coverage reaches INK_FROM (left, top, right and bottom, in pixels of its
 * cell); and the characters the font does not draw a cell wide.
 */
final class Atlas
{
    public readonly int $cellH;
    public readonly Image $bitmap;
    /** @var list<array{0: float, 1: float, 2: float, 3: float}> */
    public array $boxes = [];
    /** @var list<bool> */
    public array $missing = [];

    /**
     * Rasterizes the charset for cells of $cellW pixels at $scale pixels a
     * DIP, as large as GLYPH_SIZE allows in a cell with a pixel to spare
     * either side, its baseline placed to center the font's ascent and
     * descent, and each glyph centered as the zero's advance. It shapes and
     * rasterizes with the text system of $c (shape, renderGlyphs), so the
     * view makes it.
     */
    public function __construct(Context $c, public readonly int $cellW, float $scale)
    {
        $this->cellH = $cellH = 2 * $cellW;
        $weight = LIGHT_WEIGHT;
        if ($scale < 1.5) {
            $weight += 100;
        }
        $font = static function (float $size) use ($weight): TextStyle {
            $f = new TextStyle();
            [$f->family, $f->size, $f->weight] = [FAMILY, $size, $weight];
            return $f;
        };
        $ratio = 0.6;
        if (count($g = $c->shape('0', $font(100))) === 1) {
            $ratio = $g[0]->advance / 100;
        }
        $px = min(round(GLYPH_SIZE * $scale), floor(($cellW - 2) / $ratio));
        $f = $font($px / $scale);
        $m = $c->fontMetrics($f);
        $base = round(($cellH - ($m['ascent'] + $m['descent']) * $scale) / 2 + $m['ascent'] * $scale);
        $zero = 0.0;
        if (count($g = $c->shape('0', $f)) === 1) {
            $zero = $g[0]->advance * $scale;
        }
        $left = floor(($cellW - $zero) / 2);

        // Each character into its cell of the sheet, white coverage, a byte a pixel.
        $chars = mb_str_split(CHARSET);
        [$w, $h] = [16 * $cellW, intdiv(count($chars) + 15, 16) * $cellH];
        $sheet = str_repeat("\0", $w * $h);
        // The coverage of a cell as renderGlyphs makes it, ceil(size × scale) wide, which may be a pixel more than the cell.
        $stride = (int)ceil($cellW / $scale * $scale);
        foreach ($chars as $i => $r) {
            $g = $c->shape($r, $f);
            $this->missing[$i] = count($g) !== 1 || abs($g[0]->advance * $scale - $zero) > 0.5;
            [$ox, $oy] = [$i % 16 * $cellW, intdiv($i, 16) * $cellH];
            [$x0, $y0, $x1, $y1] = [$cellW, $cellH, -1, -1];
            if (!$this->missing[$i]) {
                $cov = $c->renderGlyphs($g, $left / $scale, $base / $scale, $cellW / $scale, $cellH / $scale, $scale);
                for ($y = 0; $y < $cellH; $y++) {
                    $row = substr($cov, $y * $stride, $cellW);
                    $sheet = substr_replace($sheet, $row, ($oy + $y) * $w + $ox, $cellW);
                    for ($x = 0; $x < $cellW; $x++) {
                        if (ord($row[$x]) >= INK_FROM) {
                            [$x0, $y0, $x1, $y1] = [min($x0, $x), min($y0, $y), max($x1, $x), max($y1, $y)];
                        }
                    }
                }
            }
            $this->boxes[$i] = [(float)$x0, (float)$y0, (float)$x1, (float)$y1];
        }
        // White, premultiplied: every channel the coverage.
        $this->bitmap = Image::fromPixels($w, $h, pack('N*', ...array_map(fn(int $a) => $a * 0x01010101, unpack('C*', $sheet))));
    }

    /** The characters of $look that the atlas draws, as indexes of the charset. @return list<int> */
    public function sequence(string $look): array
    {
        $chars = LOOKS[0][1];
        foreach (LOOKS as [$name, $set]) {
            if ($name === $look) {
                $chars = $set;
            }
        }
        $charset = mb_str_split(CHARSET);
        $seq = [];
        foreach (mb_str_split($chars) as $r) {
            $i = array_search($r, $charset, true);
            if ($i !== false && $i > 0 && !$this->missing[$i]) {
                $seq[] = $i;
            }
        }
        if ($seq === []) {
            $seq[] = array_search('.', $charset, true);
        }
        return $seq;
    }
}

// The grid: cells CELL_SIZE DIPs wide and twice as high, at least MIN_COLS
// by MIN_ROWS of them, and no more than MAX_PIXELS pixels in all.
const CELL_SIZE = 6.5;
const MIN_COLS = 24;
const MIN_ROWS = 8;
const MAX_PIXELS = 21e6;

// The clock: a frame every FRAME_TIME seconds, or RIPPLE_TIME while a
// click's blow runs out; the wind blows for BUDGET seconds after the last
// thing the user did and settles over SETTLE_TIME, gathers speed again
// over RESUME_TIME, and the texture comes in over REVEAL_TIME seconds of
// wind and fades in over FADE_TIME.
const FRAME_TIME = 0.040;
const RIPPLE_TIME = 0.010;
const BUDGET = 180;
const SETTLE_TIME = 8;
const RESUME_TIME = 3;
const REVEAL_TIME = 4;
const FADE_TIME = 3.0;
// STRENGTH is how strong the canvas shows: its light is drawn HEADROOM
// times weaker, leaving room for the ripples' color above it.
const STRENGTH = 0.26;
const HEADROOM = 3;

// INK is the plain glyphs' color, GLOW_LIGHT and GLOW_DARK the ripples' on a light and a dark theme.
const INK = 0x898781;
const GLOW_LIGHT = [0, 0.25, 1];
const GLOW_DARK = [0.1, 0.68, 1];

/** The cells of a pane: their size and count, and where the first lies, in pixels; the grid is centered on the pane and overhangs it. */
final class Grid
{
    public function __construct(
        public readonly int $cellW,
        public readonly int $cellH,
        public readonly int $cols,
        public readonly int $rows,
        public readonly int $left,
        public readonly int $top,
        public readonly float $scale,
    ) {}

    /** The grid of a pane of $w×$h pixels at $scale pixels a DIP, or null for a pane too small for one. */
    public static function of(int $w, int $h, float $scale): ?self
    {
        if ($w <= 0 || $h <= 0 || $scale <= 0) {
            return null;
        }
        $cw = max(5, (int)round(CELL_SIZE * $scale));
        [$cols, $rows] = [intdiv($w + $cw - 1, $cw), intdiv($h + 2 * $cw - 1, 2 * $cw)];
        if ($cols < MIN_COLS || $rows < MIN_ROWS) {
            return null;
        }
        if (($k = $cols * $cw * $rows * 2 * $cw / MAX_PIXELS) > 1) {
            [$cols, $rows] = [(int)($cols / sqrt($k)), (int)($rows / sqrt($k))];
        }
        return new self($cw, 2 * $cw, $cols, $rows, (int)round(($w - $cols * $cw) / 2), (int)round(($h - $rows * 2 * $cw) / 2), $scale);
    }

    /** The middle of a cell's column and row in the pane, in DIPs. */
    public function cellX(int $cx): float
    {
        return ($this->left + ($cx + 0.5) * $this->cellW) / $this->scale;
    }

    public function cellY(int $cy): float
    {
        return ($this->top + ($cy + 0.5) * $this->cellH) / $this->scale;
    }
}

/** The shaders: the wind's field, and the glyphs it lights, compiled once. */
final class Shaders
{
    public readonly Shader $wind, $glyphs;

    public function __construct()
    {
        $this->wind = Shader::compile(file_get_contents(__DIR__ . '/wind.kage'));
        $this->glyphs = Shader::compile(file_get_contents(__DIR__ . '/glyphs.kage'));
    }
}

/** The glyphs a pane shows, blown by a wind. */
final class Texture
{
    public Wind $w;
    /** @var list<string> the looks of the last visits, latest first */
    public array $recent = [];
    /** @var array<string, Atlas> the atlases by what they are drawn for: the cells' width and the scale */
    public array $atlases = [];
    /** @var array<string, array{0: int, 1: float}> the atlases a draw asked for, which the view makes next (Context::shape and renderGlyphs are the view's) */
    public array $asked = [];
    public Hand $hand;
    /** The clock, in nanoseconds: when the texture started, which the hand's times count from, its last frame, and when it first showed. */
    public ?int $start = null, $last = null, $shown = null;
    /**
     * The wind's time; the seconds the texture moved; when it last began
     * to come in, when the wind last resumed, and until when it blows; its
     * energy; and that it has settled.
     */
    public float $windTime = 0, $active = 0, $revealFrom = 0, $resumedAt = 0, $until = 0, $energy = 0;
    public bool $calm = false;
    /** Draws a frame that does not move, while the desktop asks for less motion. */
    public bool $still = false;
    /**
     * The frame shown: the wind's field, drawn by wind.kage, one pixel a
     * sample, and the uniforms of the shaders (windValues for wind.kage,
     * glyphValues for glyphs.kage), for the grid drawn.
     */
    public ?Canvas $field = null;
    /** @var ?array<string, int|float|list<int|float>> */
    public ?array $windValues = null, $glyphValues = null;
    public ?Grid $drawn = null;
    /** When the frame shown was worked out, on which theme and whether still; and whether the field is drawn for it. */
    public ?int $rendered = null;
    public bool $dark = false, $stillDrawn = false, $fieldDone = false;

    /** A texture blown by the wind of $seed, drawn with $sh. */
    public function __construct(int $seed, public readonly Shaders $sh)
    {
        $this->reseed($seed);
    }

    /** Starts a new visit, with the wind of $seed, in a look the last visits did not show. */
    public function reseed(int $seed): void
    {
        $look = self::pickLook($seed, $this->recent);
        $this->recent = array_slice([$look, ...$this->recent], 0, 3);
        $this->w = Wind::of($seed, $look);
        $this->windTime = $seed / 4294967296 * 64;
        [$this->active, $this->revealFrom, $this->resumedAt, $this->until] = [0.0, 0.0, -RESUME_TIME, BUDGET];
        [$this->energy, $this->calm] = [1.0, false];
        [$this->last, $this->shown] = [null, null];
        $this->hand = new Hand();
    }

    /** The look of $seed among those not in $recent, or not the latest of them. @param list<string> $recent */
    public static function pickLook(int $seed, array $recent): string
    {
        $fresh = $notLast = $all = [];
        foreach (LOOKS as [$name]) {
            $all[] = $name;
            if (!in_array($name, $recent, true)) {
                $fresh[] = $name;
            }
            if ($recent === [] || $recent[0] !== $name) {
                $notLast[] = $name;
            }
        }
        $pick = $fresh !== [] ? $fresh : ($notLast !== [] ? $notLast : $all);
        return $pick[(int)(random($seed, 'look')() * count($pick))];
    }

    /** The seconds since the texture started, as the hand counts them. */
    public function clock(int $now): float
    {
        $this->start ??= $now;
        return ($now - $this->start) / 1e9;
    }

    /** Has the wind blow again for its whole budget, after the user did something: settled, it comes in anew. */
    public function wake(): void
    {
        if ($this->calm) {
            $this->calm = false;
            [$this->revealFrom, $this->resumedAt, $this->until] = [$this->active, $this->active, $this->active + BUDGET];
            $this->last = null;
        } elseif ($this->active < $this->until) {
            $this->until = $this->active + BUDGET;
        }
    }

    /** move() and tap() note the pointer's moves and clicks at ($x, $y) in the pane, in DIPs, at $now (nanoseconds). */
    public function move(float $x, float $y, int $now): void
    {
        if ($this->still) {
            return;
        }
        $this->hand->move($x, $y, $this->clock($now));
        $this->wake();
    }

    public function tap(float $x, float $y, int $now): void
    {
        if ($this->still) {
            return;
        }
        $this->hand->tap($x, $y, $this->clock($now));
        $this->wake();
    }

    /** Moves the clock to $now: the wind blows as long as its budget lasts, then settles, the texture fading with it. */
    public function step(int $now): void
    {
        $dt = $this->last === null ? 0.0 : min(max(($now - $this->last) / 1e9, 0.0), 0.25);
        $this->last = $now;
        $this->active += $dt;
        $this->energy = 1 - smoothstep(0, 1, ($this->active - $this->until) / SETTLE_TIME);
        $speed = smoothstep(0, 1, ($this->active - $this->resumedAt) / RESUME_TIME);
        $this->windTime += $dt * $this->energy * $speed;
    }

    /** Makes the atlases a draw asked for, with the text system of $c. */
    public function makeAtlases(Context $c): void
    {
        foreach ($this->asked as $key => [$cellW, $scale]) {
            $this->atlases[$key] = new Atlas($c, $cellW, $scale);
        }
        $this->asked = [];
    }

    /**
     * Paints the texture in $r, the pane, with $p, moving it on when its
     * next frame is due: the shaders draw it, the wind's field into a
     * canvas of a pixel a sample, then the glyphs, which read it.
     * @param array{0: float, 1: float, 2: float, 3: float} $r
     */
    public function draw(Painter $p, array $r, bool $dark): void
    {
        $scale = $p->scale();
        $g = Grid::of((int)round($r[2] * $scale), (int)round($r[3] * $scale), $scale);
        if ($g === null) {
            return;
        }
        $key = "$g->cellW@$scale";
        $a = $this->atlases[$key] ?? null;
        if ($a === null) {
            // The next frame's view makes it.
            $this->asked[$key] = [$g->cellW, $scale];
            $p->animationFrame();
            return;
        }
        $now = $p->now();
        $moving = !$this->still && !$this->calm;
        // A frame may paint this more than once, and other repaints do too,
        // as the caret blinks: the texture moves on at its own pace.
        $due = $this->windValues === null || $g != $this->drawn || $dark !== $this->dark || $this->still !== $this->stillDrawn || $this->shown === null
            || $now !== $this->rendered && ($moving && ($this->last === null || ($now - $this->last) / 1e9 >= $this->interval($now) / 2)
                || ($now - $this->shown) / 1e9 < FADE_TIME + FRAME_TIME);
        if ($due) {
            $this->shown ??= $now;
            if ($moving) {
                $this->step($now);
            }
            $this->uniforms($g, $a, $r, $dark, $now);
            [$this->rendered, $this->dark, $this->stillDrawn, $this->drawn, $this->fieldDone] = [$now, $dark, $this->still, $g, false];
            if ($moving && $this->energy == 0 && !$this->hand->busy($this->clock($now))) {
                $this->calm = true;
            }
        }

        [$w, $h] = [$g->cols, 2 * $g->rows];
        if ($this->field === null || $this->field->w !== $w || $this->field->h !== $h) {
            [$this->field, $this->fieldDone] = [new Canvas($w, $h), false];
        }
        if (!$this->fieldDone) {
            $this->fieldDone = true;
            $values = $this->windValues;
            $this->field->draw($p, fn(Painter $cp) => $this->sh->wind->draw($cp, [0, 0, $w, $h], new Options(uniforms: $values)));
        }
        $k = 1 / $scale;
        $dst = [$r[0] + $g->left * $k, $r[1] + $g->top * $k, $g->cols * $g->cellW * $k, $g->rows * $g->cellH * $k];
        $o = new Options(uniforms: $this->glyphValues, images: [$this->field->bitmap(), $a->bitmap]);
        $p->clip($r, fn() => $this->sh->glyphs->draw($p, $dst, $o));

        if ($moving) {
            $p->after($this->interval($now));
        } elseif (($now - $this->shown) / 1e9 < FADE_TIME) {
            $p->after(FRAME_TIME);
        }
    }

    /** The seconds between frames at $now. */
    public function interval(int $now): float
    {
        return $this->hand->rippling($this->clock($now)) ? RIPPLE_TIME : FRAME_TIME;
    }

    /**
     * Works out the shaders' uniforms for a frame of grid $g in pane $r, at
     * $now: the wind's time and its reveal, the hand's blows, and the
     * light, as the app's vertex shaders and its code around them do.
     * @param array{0: float, 1: float, 2: float, 3: float} $r
     */
    private function uniforms(Grid $g, Atlas $a, array $r, bool $dark, int $now): void
    {
        $reveal = smoothstep(0, 1, ($this->active - $this->revealFrom) / REVEAL_TIME) * $this->energy;
        if ($this->still) {
            $reveal = 1.0;
            $this->hand = new Hand();
        }
        $h = $this->hand;
        $h->frame($this->clock($now));
        $k = 1 / $g->scale;
        $wt = $this->windTime;
        [$cw, $ch] = [$g->cols * $g->cellW * $k, $g->rows * $g->cellH * $k];

        $taps = [];
        foreach ($h->taps as $tp) {
            array_push($taps, $tp->x, $tp->y, $tp->at, $tp->force);
        }
        $this->windValues = [
            'Seed' => $this->w->seed,
            'Time' => $wt,
            'Reveal' => $reveal,
            'Heading' => [cos($this->w->heading), sin($this->w->heading)],
            'Carried' => $this->w->carried($wt),
            'Span' => $this->w->span($cw, $ch),
            'GustLength' => $this->w->gustLength,
            'GustWidth' => $this->w->gustWidth,
            'Bend' => $this->w->bend,
            'BendScale' => $this->w->bendScale,
            'PaneSize' => [$r[2], $r[3]],
            'Origin' => [$g->left * $k, $g->top * $k],
            'Step' => [$g->cellW * $k, $g->cellH * $k / 2],
            'Now' => $h->now,
            'TapCount' => count($h->taps),
            'Taps' => $taps,
            'WakeCount' => intdiv(count($h->warm), 4),
            'Wakes' => $h->warm,
            'Ways' => $h->ways,
        ];

        $seq = $a->sequence($this->w->look);
        $boxes = [];
        foreach ($seq as $c) {
            array_push($boxes, ...$a->boxes[$c]);
        }
        // The canvas shows HEADROOM times as strong as its plain light, and
        // fades in with the pane holding it, both over FADE_TIME.
        $fade = 1.0;
        if (($since = ($now - $this->shown) / 1e9) < FADE_TIME) {
            $e = Easing::cubicBezier(0, 0, 0.58, 1)($since / FADE_TIME); // CSS's ease-out
            $fade = $e * $e;
        }
        $this->glyphValues = [
            'Seed' => $this->w->seed,
            'Time' => $wt,
            'Grid' => [$g->cellW, $g->cellH, $g->cols, $g->rows],
            'Cells' => [$g->cellX(0), $g->cellY(0), $g->cellW * $k, $g->cellH * $k],
            'Seq' => $seq,
            'SeqLen' => count($seq),
            'Boxes' => $boxes,
            'Now' => $h->now,
            'TapCount' => count($h->taps),
            'Taps' => $taps,
            'Ink' => [(INK >> 16 & 0xff) / 255, (INK >> 8 & 0xff) / 255, (INK & 0xff) / 255],
            'InkAlpha' => 1.0,
            'Glow' => $dark ? GLOW_DARK : GLOW_LIGHT,
            'Opacity' => min(1, STRENGTH * HEADROOM) * $fade,
        ];
    }
}

/** The window's state: the texture, and the draft in the composer. */
final class WindApp
{
    public Texture $tex;
    /** The composer's text, and what the texture last saw of it: typing keeps the wind blowing. */
    public TextState $draft;
    public string $typed = '';
    /** @var list<array{0: float, 1: float, 2: float, 3: float}> where the controls were in the last frame, in the pane: a press there is theirs, and blows no ripple */
    public array $controls = [];

    public function __construct(int $seed, Shaders $sh)
    {
        $this->tex = new Texture($seed, $sh);
        $this->draft = new TextState();
    }

    /** Whether ($x, $y) in the pane lies on a control. */
    public function onControl(float $x, float $y): bool
    {
        foreach ($this->controls as [$rx, $ry, $rw, $rh]) {
            if ($x >= $rx && $x < $rx + $rw && $y >= $ry && $y < $ry + $rh) {
                return true;
            }
        }
        return false;
    }
}

function view(Context $c, WindApp $s): void
{
    $t = $c->theme;
    // While the desktop asks for less motion, the texture shows the wind as it stands.
    $s->tex->still = $c->preferences()->reduceMotion;
    if ($c->shortcut(Key::R, Key::COMMAND)) {
        $s->tex->reseed(random_int(0, U32));
    }
    if ($s->draft->text !== $s->typed) {
        $s->typed = $s->draft->text;
        $s->tex->wake();
    }
    $s->tex->makeAtlases($c);
    [$w] = $c->size();

    $rt = $c->runtime;
    $pane = $c->column(0, 0, 0, $t->background)->fill()->align(Node::CENTER);
    $pane->draw(function (DisplayList $list, Node $n, Theme $th, TextPainter $text) use ($s, $t, $rt): void {
        $s->tex->draw(new Painter($list, $text, $th, $rt), [$n->x, $n->y, $n->w, $n->h], $t->dark);
    });
    // The pointer stirs the air wherever it is over the pane, but for the
    // controls, which take it themselves.
    $pane->handleInput(function (InputEvent $ev) use ($s): bool {
        switch ($ev->kind) {
            case InputEvent::POINTER_MOVE:
                $calm = $s->tex->calm;
                $s->tex->move($ev->x, $ev->y, hrtime(true));
                // A frame, to wake the texture as it rests.
                return $calm;
            case InputEvent::POINTER_DOWN:
                if ($ev->button === 0 && !$s->onControl($ev->x, $ev->y)) {
                    $s->tex->tap($ev->x, $ev->y, hrtime(true));
                    return true;
                }
                break;
            case InputEvent::KEY_DOWN:
                $s->tex->wake();
                break;
        }
        return false;
    });
      // The card's middle lies where the texture is calmest, 45% down.
      $c->spacer()->grow(45);
      $c->column(0, 12)->width(min(640, $w - 48));
        $input = composer($c, $s->draft, 'Describe a task or ask a question', 'Task');
        $s->controls = [$c->bounds($input)];
        $c->text('Move the pointer to warm the air, click to blow a ripple (every third a heart), ⌘R for another wind.', 12, $t->textMuted)->textAlign(Node::CENTER);
      $c->end();
      $c->spacer()->grow(55);
      $c->row()->alignSelf(Node::END)->padding(0, 14, 10, 0);
        $c->text(sprintf('%s/%08x', $s->tex->w->look, $s->tex->w->seed), 11, $t->textMuted)->font(FAMILY);
      $c->end();
    $c->end();
}

/**
 * The composer's field, as mygo's TextInput with Padding(14, 16),
 * Radius(14), the surface and a soft shadow: input() keeps a look of its
 * own, so the field is a bare input (textInputBase) in a box of that look,
 * its border as an input's (the accent focused, darker hovered), which
 * paints the placeholder while the field is empty. The box is the field's
 * bounds.
 */
function composer(Context $c, TextState $state, string $placeholder, string $label): Node
{
    $t = $c->theme;
    $box = $c->key('draft')->row()->padding(14, 16)->round(14)->background($t->surface)->shadow(0, 4, 18, 0, 0x000000, 0x0f / 255);
    $box->draw(function (DisplayList $list, Node $n, Theme $th, TextPainter $text) use ($state, $placeholder): void {
        if ($state->display() === '') { // nothing typed, nor composing in an input method
            (new Painter($list, $text, $th))->text($n->x + $n->padL(), $n->y + $n->padT(), $placeholder, $th->fontSize, $th->textMuted);
        }
    });
      $in = $c->textInputBase($state)->grow(1)->label($label);
      $box->border($c->focused($in) ? $t->accent : ($c->hovered($box) ? Theme::mix($t->border, $t->text, 0.25) : $t->border), 1);
    $c->end();
    return $box;
}

$s = new WindApp(random_int(0, U32), new Shaders());
App::setName('Wind');
App::whenReady(function () use ($s): void {
    new Window(new WindowOptions(title: 'Wind', width: WINDOW_W, height: WINDOW_H, content: Content::view(fn(Context $c) => view($c, $s))));
});
App::run();
