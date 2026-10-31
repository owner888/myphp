<?php
// Effort-slider redraws ozzy's "galaxy brain effort slider" demo (mygo's
// examples/effort-slider) in native UI: a card whose slider steps from Low
// to Max with a brain for a thumb, and past Max into Galaxy, where the
// brain bursts, the card shakes, the track fills with twinkling pixels and
// dotted rays stream out of the brain. Drag the brain, click the track or
// press the arrow keys. The demo is a web page, so the card draws as Chrome
// draws it: the system font without font smoothing (the only way glyphs
// draw), CSS's round corners, filter: blur() on the letters of the level
// as they change, which the painter has not: the letters are rasterized
// once (Context::renderGlyphs) and blurred on the CPU as they move.
// Run: ./run.sh effort-slider
declare(strict_types=1);

use MyPHP\{App, Theme as AppTheme, ThemeSource, Window, WindowOptions};
use MyPHP\Text\Glyph;
use MyPHP\UI\{Content, Context, Image, LinearGradient, Node, Painter, Path, TextStyle};
use MyPHP\UI\Svg\Path as SvgPath;

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

// The window shows the demo's frame: the card a little above the middle.
const WINDOW_W = 472;
const WINDOW_H = 258;
const CARD_W = 220;
const CARD_H = 129.5;
const CARD_Y = 62; // from the top of the window

// The card's parts, in points from its top left corner, as the demo's page lays them out.
const CARD_RADIUS = 8;
const TITLE_SIZE = 13.25;
const TITLE_BASELINE = 26;
const EFFORT_X = 12.2;
const VALUE_X = 54.6;
const HELP_X = 200;
const HELP_Y = 20.9;
const HELP_RADIUS = 6;
const HELP_MARK_DX = 0.3;
const LABEL_SIZE = 12;
const LABEL_BASELINE = 60.4;
const FASTER_X = 12.1;
const SMARTER_RIGHT = 208.1;
const TRACK_X = 12;
const TRACK_Y = 75;
const TRACK_W = 196;
const TRACK_H = 20;
const TRACK_RADIUS = 5;
// The ticks of the levels, 34.8 points apart; the second, the one recommended, is a bar.
const TICK_X = 23;
const TICK_GAP = 34.8;
const TICK_SIZE = 3.0;
const BAR_H = 10;
const RECOMMENDED_X = 24.6;
const RECOMMENDED_BASELINE = 114;
// The thumb's center goes 36 points a level from STOP_X.
const STOP_X = 20;
const STOP_GAP = 36;
const BRAIN_SIZE = 24;
const GALAXY_BRAIN = 32.0;
const GALAXY_BRAIN_Y = 85.1;
const BRAIN_Y = 85.5;
// The fill ends under the brain, short of its center.
const FILL_INSET = 10;

const LEVELS = ['Low', 'Medium', 'High', 'Extra', 'Max', 'Galaxy'];
const GALAXY = 5;

const PAGE_COLOR = 0x101010;
const CARD_COLOR = 0x171717;
const BORDER_COLOR = 0x262626;
const TRACK_COLOR = 0x262626;
const TITLE_COLOR = 0x767676;
const VALUE_COLOR = 0xe5e5e5;
const LABEL_COLOR = 0x6f6f6f;
const RECOMMEND_COLOR = 0x777777;
const DOT_COLOR = 0x525252;
const BAR_COLOR = 0x5f5f5f;
const BRAIN_FILL = 0xfd92ba;
const BRAIN_STROKE = 0x611836;
// The fill shows a gradient across the whole track, pink to blue violet, more opaque toward the thumb.
const FILL_FROM = 0x9f5a7a;
const FILL_TO = 0x6958a7;
const FILL_ALPHA = 0.071;

const MS = 1_000_000; // nanoseconds
// The thumb eases to a level as CSS's cubic-bezier(0.19, 1, 0.22, 1) does, in half a second.
const MOVE_TIME = 500 * MS;
// The label's letters come in one after another, rising, sharpening and
// fading in, as CSS's ease eases them; the old label goes up, blurs and
// fades out at once.
const IN_STAGGER = 14_500_000;
const IN_TIME = 320 * MS;
const OUT_DELAY = 25 * MS;
const OUT_TIME = 355 * MS;
const IN_Y = 3.0;
const IN_BLUR = 1.8;
const OUT_Y = 3.4;
const OUT_BLUR = 1.87;

// Galaxy's colors.
const GALAXY_STROKE = 0x22137c;
const GALAXY_FROM = 0x8b91fd;
const GALAXY_TO = 0xb781f3;
const GALAXY_TRACK = 0x0f0a21;
const GALAXY_TRACK2 = 0x3b2a78;
const GALAXY_LABEL = 0x8fd0f6;
// The colors of the pixels, the burst's and the rays'.
const PIXEL_COLORS = [0xf472b6, 0xc084fc, 0xa78bfa, 0x818cf8, 0x67e8f9, 0x7dd3fc, 0xf5f3ff, 0xe9d5ff];
const BURST_COLORS = [0x5b3cf0, 0x67e8f9, 0xffffff, 0xf472b6, 0xa78bfa];
const RAY_COLORS = [0x5fb4bd, 0x9a9aac, 0xb3a8f0];
const GALAXY_IN = 160 * MS;  // the brain's colors change
const GALAXY_OUT = 250 * MS; // everything fades on leaving
const BURST_TIME = 650 * MS;
const FLASH_TIME = 550 * MS;
const TRACK_IN = 50 * MS; // the pixels fill the track at once
const PIXEL_PITCH = 4;    // the pixels of the track, 3 points square
// How far the card jumps in the frames after Galaxy starts, a 60th of a second apart, as the demo's card does.
const SHAKE = [
    [0.75, 1], [-0.78, 0.3], [0.9, -1.03], [0.4, 0.37], [0.25, 0], [0.15, -0.2],
    [0.15, 0.32], [-0.5, -0.22], [-0.22, 0], [0.15, -0.25], [-0.3, 0.35],
    [-0.22, -0.15], [-0.15, -0.08], [-0.05, -0.02],
];

// The letters to blur are rasterized at SHEET_SCALE pixels a point, to be
// shrunk to the display's; each text has its pen SHEET_PAD points right of
// its cell's left and its baseline SHEET_BASELINE points from the top.
const SHEET_SCALE = 4;
const SHEET_PAD = 4;
const SHEET_BASELINE = 18;
const SHEET_H = 24;

// The brain of Lucide 0.4, whose hemispheres close, so that they fill: 24
// units square, stroked 2 units wide with round caps and joins.
const BRAIN_PATHS = [
    'M12 5a3 3 0 1 0-5.997.125 4 4 0 0 0-2.526 5.77 4 4 0 0 0 .556 6.588A4 4 0 1 0 12 18Z',
    'M12 5a3 3 0 1 1 5.997.125 4 4 0 0 1 2.526 5.77 4 4 0 0 1-.556 6.588A4 4 0 1 1 12 18Z',
    'M15 13a4.5 4.5 0 0 1-3-4 4.5 4.5 0 0 1-3 4',
    'M17.599 6.5a3 3 0 0 0 .399-1.375',
    'M6.003 5.125A3 3 0 0 0 6.401 6.5',
    'M3.477 10.896a4 4 0 0 1 .585-.396',
    'M19.938 10.5a4 4 0 0 1 .585.396',
    'M6 18a4 4 0 0 1-1.967-.516',
    'M19.967 17.484A4 4 0 0 1 18 18',
];

/** A font of the system's at $size points. */
function font(float $size, int $weight = 0): TextStyle
{
    $f = new TextStyle();
    $f->size = $size;
    if ($weight > 0) { $f->weight = $weight; }
    return $f;
}

/**
 * A text shaped letter by letter, as Chrome lays out the demo's labels,
 * whose letters animate each in its own element: each at its own advance,
 * without the kerning Core Text adds between them; or kerned, as a whole.
 */
final class Word
{
    /** @param list<list<Glyph>> $letters @param list<float> $x where each letter starts */
    public function __construct(public array $letters = [], public array $x = [], public float $width = 0) {}

    public static function letters(Context $c, string $s, TextStyle $f): self
    {
        $w = new self();
        foreach (mb_str_split($s) as $r) {
            $g = $c->shape($r, $f);
            $w->letters[] = $g;
            $w->x[] = $w->width;
            foreach ($g as $gl) { $w->width += $gl->advance; }
        }
        return $w;
    }

    public static function kerned(Context $c, string $s, TextStyle $f): self
    {
        $g = $c->shape($s, $f);
        $w = new self([$g], [0.0]);
        foreach ($g as $gl) { $w->width = max($w->width, $gl->x + $gl->advance); }
        return $w;
    }

    /** Draws it from $x on the baseline at $y. */
    public function draw(Painter $p, float $x, float $y, int $rgb, float $alpha = 1.0): void
    {
        foreach ($this->letters as $i => $g) { $p->glyphs($g, $x + $this->x[$i], $y, $rgb, $alpha); }
    }
}

/**
 * Texts rasterized at SHEET_SCALE, white coverage on nothing: letters, and
 * whole words as Core Text kerns them, each in a cell of its own.
 */
final class LetterSheet
{
    /** @var array<string, array{0: int, 1: string}> each text's cell: its width in points and its coverage, a byte a pixel */
    private array $cells = [];

    /** @param list<string> $words */
    public function __construct(Context $c, TextStyle $f, array $words)
    {
        foreach ($words as $w) {
            $this->add($c, $f, $w);
            foreach (mb_str_split($w) as $r) { $this->add($c, $f, $r); }
        }
    }

    private function add(Context $c, TextStyle $f, string $t): void
    {
        if (isset($this->cells[$t])) { return; }
        $g = $c->shape($t, $f);
        $adv = 0.0;
        foreach ($g as $gl) { $adv = max($adv, $gl->x + $gl->advance); }
        $w = (int)ceil($adv) + 2 * SHEET_PAD;
        $this->cells[$t] = [$w, $c->renderGlyphs($g, SHEET_PAD, SHEET_BASELINE, $w, SHEET_H, SHEET_SCALE)];
    }

    /**
     * Text $t blurred by $sigma points at $scale pixels a point, in $rgb at
     * $alpha, and where its top left corner is from the text's pen on its
     * baseline, in points; null for a text not on the sheet.
     * @return array{0: Image, 1: float, 2: float}|null
     */
    public function blurred(string $t, float $scale, float $sigma, int $rgb, float $alpha): ?array
    {
        if (!isset($this->cells[$t])) { return null; }
        [$cw, $sheet] = $this->cells[$t];
        // The cell, shrunk to the display's pixels: each pixel the mean of the sheet's under it.
        $k = SHEET_SCALE / $scale;
        $nw = (int)ceil($cw * $scale); $nh = (int)ceil(SHEET_H * $scale);
        $pad = (int)ceil(3 * $sigma * $scale);
        $w = $nw + 2 * $pad; $h = $nh + 2 * $pad;
        $cov = array_fill(0, $w * $h, 0.0);
        $sw = $cw * SHEET_SCALE; $sh = SHEET_H * SHEET_SCALE;
        for ($y = 0; $y < $nh; $y++) {
            $sy0 = $y * $k;
            for ($x = 0; $x < $nw; $x++) {
                $sx0 = $x * $k; $sum = 0.0; $area = 0.0;
                for ($sy = (int)$sy0; $sy < $sy0 + $k && $sy < $sh; $sy++) {
                    $fy = min($sy + 1, $sy0 + $k) - max($sy, $sy0);
                    for ($sx = (int)$sx0; $sx < $sx0 + $k && $sx < $sw; $sx++) {
                        // The share of the sheet's pixel under the display's.
                        $a = (min($sx + 1, $sx0 + $k) - max($sx, $sx0)) * $fy;
                        $sum += $a * ord($sheet[$sy * $sw + $sx]);
                        $area += $a;
                    }
                }
                if ($area > 0) { $cov[($y + $pad) * $w + $x + $pad] = $sum / $area / 255; }
            }
        }
        gaussian($cov, $w, $h, $sigma * $scale);
        [$r, $g, $b] = [$rgb >> 16 & 0xff, $rgb >> 8 & 0xff, $rgb & 0xff];
        $px = '';
        foreach ($cov as $a) {
            $a *= $alpha;
            $px .= chr((int)($r * $a + 0.5)) . chr((int)($g * $a + 0.5)) . chr((int)($b * $a + 0.5)) . chr((int)(255 * $a + 0.5));
        }
        return [Image::fromPixels($w, $h, $px), -SHEET_PAD - $pad / $scale, -SHEET_BASELINE - $pad / $scale];
    }
}

/** Blurs the $w × $h coverage in place by $sigma pixels, rows then columns. @param list<float> $cov */
function gaussian(array &$cov, int $w, int $h, float $sigma): void
{
    if ($sigma < 0.01) { return; }
    $r = (int)ceil(3 * $sigma);
    $kernel = []; $sum = 0.0;
    for ($i = 0; $i <= 2 * $r; $i++) {
        $d = $i - $r;
        $kernel[$i] = exp(-$d * $d / (2 * $sigma * $sigma));
        $sum += $kernel[$i];
    }
    foreach ($kernel as $i => $kv) { $kernel[$i] = $kv / $sum; }
    $tmp = $cov;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $v = 0.0;
            foreach ($kernel as $i => $kv) {
                $xx = $x + $i - $r;
                if ($xx >= 0 && $xx < $w) { $v += $kv * $cov[$y * $w + $xx]; }
            }
            $tmp[$y * $w + $x] = $v;
        }
    }
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $v = 0.0;
            foreach ($kernel as $i => $kv) {
                $yy = $y + $i - $r;
                if ($yy >= 0 && $yy < $h) { $v += $kv * $tmp[$yy * $w + $x]; }
            }
            $cov[$y * $w + $x] = $v;
        }
    }
}

/** CSS's cubic-bezier(x1, y1, x2, y2) timing function. */
function cubicBezier(float $x1, float $y1, float $x2, float $y2): Closure
{
    $curve = static fn(float $a, float $b, float $s): float => 3 * $a * $s * (1 - $s) * (1 - $s) + 3 * $b * $s * $s * (1 - $s) + $s * $s * $s;
    return static function (float $t) use ($curve, $x1, $y1, $x2, $y2): float {
        if ($t <= 0 || $t >= 1) { return min(max($t, 0.0), 1.0); }
        // Newton's method for the s at which the curve is at t, then bisection where it stalls.
        $s = $t;
        for ($i = 0; $i < 8; $i++) {
            $x = $curve($x1, $x2, $s) - $t;
            $d = 3 * $x1 * (1 - $s) * (1 - $s) + 6 * ($x2 - $x1) * $s * (1 - $s) + 3 * (1 - $x2) * $s * $s;
            if (abs($x) < 1e-7 || abs($d) < 1e-6) { break; }
            $s -= $x / $d;
        }
        $lo = 0.0; $hi = 1.0;
        for ($i = 0; $i < 40; $i++) {
            $x = $curve($x1, $x2, $s);
            if (abs($x - $t) < 1e-7) { break; }
            if ($x < $t) { $lo = $s; } else { $hi = $s; }
            $s = ($lo + $hi) / 2;
        }
        return $curve($y1, $y2, $s);
    };
}

/** The 32 bits of $a times $b, without the float a 64-bit product can overflow to. */
function mul32(int $a, int $b): int
{
    return ($a * ($b & 0xffff) + ((($a * ($b >> 16)) & 0xffff) << 16)) & 0xffffffff;
}

/** A number from 0 to 1 that only depends on its arguments. */
function hash3(int $a, int $b, int $c): float
{
    $h = mul32($a & 0xffffffff, 0x9e3779b1) ^ mul32($b & 0xffffffff, 0x85ebca77) ^ mul32($c & 0xffffffff, 0xc2b2ae3d);
    $h ^= $h >> 15;
    $h = mul32($h, 0x2c1b3c6d);
    $h ^= $h >> 12;
    $h = mul32($h, 0x297a2d39);
    $h ^= $h >> 15;
    return ($h & 0xffffff) / 0xffffff;
}

/** How lit a pixel seeded by $a and $b is at $t seconds: it blinks on and off at a pace of its own. */
function twinkle(int $a, int $b, float $t): float
{
    $period = 0.7 + 1.1 * hash3($a, $b, 1);
    $k = fmod($t / $period + hash3($a, $b, 2), 1);
    // Lit for part of each period, easing in and out.
    $on = 0.35 + 0.4 * hash3($a, $b, 3);
    return $k > $on ? 0.0 : sin($k / $on * M_PI);
}

/** How bright a pixel seeded by $a and $b is at $t seconds, from 0 to 1, waving at a pace of its own. */
function shimmer(int $a, int $b, float $t): float
{
    $period = 0.5 + hash3($a, $b, 1);
    return 0.5 + 0.5 * sin(2 * M_PI * ($t / $period + hash3($a, $b, 2)));
}

function smoothstep(float $lo, float $hi, float $x): float
{
    $k = min(max(($x - $lo) / ($hi - $lo), 0.0), 1.0);
    return $k * $k * (3 - 2 * $k);
}

/** $a mixed $k of the way to $b. */
function mix(int $a, int $b, float $k): int
{
    $out = 0;
    foreach ([16, 8, 0] as $sh) {
        $out |= (int)round(($a >> $sh & 0xff) + (($b >> $sh & 0xff) - ($a >> $sh & 0xff)) * $k) << $sh;
    }
    return $out;
}

/**
 * Adds a rectangle with corners rounded by circles of radius $r, as CSS's
 * border-radius rounds them (the painter's rounded rectangles curve
 * continuously, as AppKit's do).
 */
function roundRect(Path $p, float $x, float $y, float $w, float $h, float $r): Path
{
    $k = 1 - 0.5522847498;
    return $p->moveTo($x + $r, $y)->lineTo($x + $w - $r, $y)
        ->cubeTo($x + $w - $r * $k, $y, $x + $w, $y + $r * $k, $x + $w, $y + $r)->lineTo($x + $w, $y + $h - $r)
        ->cubeTo($x + $w, $y + $h - $r * $k, $x + $w - $r * $k, $y + $h, $x + $w - $r, $y + $h)->lineTo($x + $r, $y + $h)
        ->cubeTo($x + $r * $k, $y + $h, $x, $y + $h - $r * $k, $x, $y + $h - $r)->lineTo($x, $y + $r)
        ->cubeTo($x, $y + $r * $k, $x + $r * $k, $y, $x + $r, $y)->close();
}

/** The paths of $ds, 24 units square, as a Path $size points square centered on ($cx, $cy). @param list<SvgPath> $paths */
function icon(array $paths, float $cx, float $cy, float $size): Path
{
    $out = new Path();
    $s = $size / 24; $ox = $cx - 12 * $s; $oy = $cy - 12 * $s;
    foreach ($paths as $sp) {
        $pts = $sp->pts; $i = 0;
        foreach ($sp->ops as $op) {
            switch ($op) {
                case SvgPath::MOVE: $out->moveTo($ox + $pts[$i] * $s, $oy + $pts[$i + 1] * $s); $i += 2; break;
                case SvgPath::LINE: $out->lineTo($ox + $pts[$i] * $s, $oy + $pts[$i + 1] * $s); $i += 2; break;
                case SvgPath::CUBIC:
                    $out->cubeTo($ox + $pts[$i] * $s, $oy + $pts[$i + 1] * $s, $ox + $pts[$i + 2] * $s, $oy + $pts[$i + 3] * $s, $ox + $pts[$i + 4] * $s, $oy + $pts[$i + 5] * $s);
                    $i += 6;
                    break;
                case SvgPath::CLOSE: $out->close(); break;
            }
        }
    }
    return $out;
}

/** The demo's state: the level chosen, and the moves and changes still animating; times in nanoseconds. */
final class Slider
{
    /** The level sliderBase sets, 0 to 5. */
    public float $value = 0;
    /** The level the thumb goes to, where it was when it set off, and when. */
    public int $level = 0;
    public float $from = 0;
    public int $movedAt = 0;
    /** The level the label showed before, and when it changed. */
    public int $prev = 0, $changedAt = 0;
    /** When the slider went Galaxy, and left it. */
    public int $galaxyAt = 0, $galaxyLeft = 0;
    /** While the pointer holds the thumb, it stays under the pointer, at $held levels, and the level is the nearest; let go, it eases there. */
    public bool $holding = false;
    public float $held = 0;

    /** What is shaped and rasterized, the first frame. */
    public ?LetterSheet $sheet = null;
    /** @var array<string, Word> */
    public array $words = [];
    /** @var list<Glyph> */
    public array $helpMark = [];
    public array $helpMetrics = [];
    /** @var list<SvgPath> */
    public array $brain;

    public Closure $moveEase, $labelEase, $backOut;
    /** @var array<int, Image> soft round glows by step and color: the painter has no blur of shapes */
    private array $glows = [];

    public function __construct()
    {
        $this->brain = array_map(SvgPath::parse(...), BRAIN_PATHS);
        $this->moveEase = cubicBezier(0.19, 1, 0.22, 1);
        $this->labelEase = cubicBezier(0.25, 0.1, 0.25, 1);
        // Eases to 1 a little past it, as CSS's cubic-bezier(0.34, 1.56, 0.64, 1) does.
        $this->backOut = cubicBezier(0.34, 1.56, 0.64, 1);
    }

    /** Shapes the card's texts and rasterizes the label's letters, once. */
    public function prepare(Context $c): void
    {
        if ($this->sheet !== null) { return; }
        $title = font(TITLE_SIZE); $label = font(LABEL_SIZE);
        $this->sheet = new LetterSheet($c, $title, LEVELS);
        $this->words['Effort'] = Word::kerned($c, 'Effort', $title);
        foreach (['Faster', 'Smarter', 'Recommended'] as $w) { $this->words[$w] = Word::kerned($c, $w, $label); }
        foreach (LEVELS as $l) {
            $this->words["\0" . $l] = Word::letters($c, $l, $title);
            $this->words["\1" . $l] = Word::kerned($c, $l, $title);
        }
        $help = font(8.8, 600);
        $this->helpMark = $c->shape('?', $help);
        $this->helpMetrics = $c->fontMetrics($help);
    }

    /** Where the thumb is at $t, in levels, and whether it is still moving. @return array{0: float, 1: bool} */
    public function thumb(int $t): array
    {
        if ($this->holding) { return [$this->held, false]; }
        $k = ($t - $this->movedAt) / MOVE_TIME;
        if ($this->movedAt === 0 || $k >= 1) { return [(float)$this->level, false]; }
        return [$this->from + ($this->level - $this->from) * ($this->moveEase)(max($k, 0.0)), true];
    }

    /** Sends the thumb to level $l at $t. */
    public function setLevel(int $l, int $t): void
    {
        if ($l === $this->level) { return; }
        [$this->from] = $this->thumb($t);
        $this->prev = $this->level; $this->changedAt = $t;
        if ($l === GALAXY) {
            $this->galaxyAt = $t; $this->galaxyLeft = 0;
        } elseif ($this->level === GALAXY) {
            $this->galaxyLeft = $t;
        }
        $this->level = $l; $this->movedAt = $t;
        $this->value = $l;
    }

    /** Puts the thumb under the pointer, at $pos levels, at $t. */
    public function hold(float $pos, int $t): void
    {
        $this->holding = true;
        $this->held = min(max($pos, 0.0), (float)GALAXY);
        $this->setLevel((int)round($this->held), $t);
    }

    /** Lets the thumb go at $t, to ease from where it was held to its level. */
    public function release(int $t): void
    {
        if ($this->holding) {
            $this->holding = false;
            $this->from = $this->held; $this->movedAt = $t;
        }
    }

    /** How far into Galaxy the slider is at $t: 0 out of it, 1 in it, and between while the brain changes. */
    public function galaxyLevel(int $t): float
    {
        if ($this->galaxyAt === 0) { return 0.0; }
        if ($this->galaxyLeft !== 0) { return max(1 - ($t - $this->galaxyLeft) / GALAXY_OUT, 0.0); }
        return min(($t - $this->galaxyAt) / GALAXY_IN, 1.0);
    }

    /** How far the card is off its place at $t. @return array{0: float, 1: float} */
    public function shakeAt(int $t): array
    {
        if ($this->galaxyAt === 0 || $this->galaxyLeft !== 0) { return [0.0, 0.0]; }
        $f = ($t - $this->galaxyAt) / 1e9 * 60;
        $i = (int)$f;
        if ($f < 0 || $i >= count(SHAKE)) { return [0.0, 0.0]; }
        $a = SHAKE[$i]; $b = SHAKE[$i + 1] ?? [0, 0];
        $k = $f - $i;
        return [$a[0] + ($b[0] - $a[0]) * $k, $a[1] + ($b[1] - $a[1]) * $k];
    }

    /** Draws the card with its top left corner at ($ox, $oy), as it is at $t, and reports whether it is still animating. */
    public function drawCard(Painter $p, float $ox, float $oy, int $t): bool
    {
        [$pos, $moving] = $this->thumb($t);
        $g = $this->galaxyLevel($t);
        $secs = ($t - $this->galaxyAt) / 1e9;
        if ($g > 0) { $moving = true; }
        [$dx, $dy] = $this->shakeAt($t);
        $ox += $dx; $oy += $dy;

        // The card, as CSS rounds it, with a 1 point border, on Tailwind's shadow-lg.
        $card = [$ox, $oy, CARD_W, CARD_H];
        $p->shadow($card, CARD_RADIUS, 0, 10, 15, -3, 0x000000, 0.1);
        $p->shadow($card, CARD_RADIUS, 0, 4, 6, -4, 0x000000, 0.1);
        $p->fillPath(roundRect(new Path(), $ox, $oy, CARD_W, CARD_H, CARD_RADIUS), BORDER_COLOR);
        $p->fillPath(roundRect(new Path(), $ox + 1, $oy + 1, CARD_W - 2, CARD_H - 2, CARD_RADIUS - 1), CARD_COLOR);

        $this->words['Effort']->draw($p, $ox + EFFORT_X, $oy + TITLE_BASELINE, TITLE_COLOR);
        if ($this->drawLabel($p, $ox + VALUE_X, $oy + TITLE_BASELINE, $t)) { $moving = true; }
        $this->drawHelp($p, $ox + HELP_X, $oy + HELP_Y, TITLE_COLOR);
        $this->words['Faster']->draw($p, $ox + FASTER_X, $oy + LABEL_BASELINE, LABEL_COLOR);
        $smarter = $this->words['Smarter'];
        $smarter->draw($p, $ox + SMARTER_RIGHT - $smarter->width, $oy + LABEL_BASELINE, LABEL_COLOR);

        // The track, and the fill up to the thumb.
        $p->fillPath(roundRect(new Path(), $ox + TRACK_X, $oy + TRACK_Y, TRACK_W, TRACK_H, TRACK_RADIUS), TRACK_COLOR);
        $thumbX = STOP_X + STOP_GAP * $pos;
        $end = $thumbX - FILL_INSET;
        if ($end > TRACK_X) {
            $w = $end - TRACK_X;
            $fill = roundRect(new Path(), $ox + TRACK_X, $oy + TRACK_Y, $w, TRACK_H, min(TRACK_RADIUS, $w / 2));
            $p->fillPathGradient($fill, new LinearGradient(FILL_FROM, 1, FILL_TO, 1, 90, end: TRACK_W / $w, oklab: true));
            $p->fillPathGradient($fill, new LinearGradient(TRACK_COLOR, 1 - FILL_ALPHA, TRACK_COLOR, 0, 90));
        }
        $gt = $g;
        if ($this->galaxyLeft === 0 && $this->galaxyAt !== 0) { $gt = min(($t - $this->galaxyAt) / TRACK_IN, 1.0); }
        $this->drawGalaxyTrack($p, $ox, $oy, $thumbX, $gt, $secs);

        // The ticks.
        for ($i = 0; $i < count(LEVELS); $i++) {
            $x = $ox + TICK_X + TICK_GAP * $i;
            if ($i === 1) {
                $p->fillPath(roundRect(new Path(), $x - TICK_SIZE / 2, $oy + TRACK_Y + TRACK_H / 2 - BAR_H / 2, TICK_SIZE, BAR_H, TICK_SIZE / 2), mix(BAR_COLOR, 0xc8c4dc, $g));
                continue;
            }
            $p->fillPath((new Path())->circle($x, $oy + TRACK_Y + TRACK_H / 2, TICK_SIZE / 2), DOT_COLOR, 1 - $g);
        }
        $this->words['Recommended']->draw($p, $ox + RECOMMENDED_X, $oy + RECOMMENDED_BASELINE, RECOMMEND_COLOR);

        // The brain, and in Galaxy its rays and glow behind it, the burst
        // and the flash over it. In Galaxy the brain grows by a third, a
        // little past it at first.
        $grow = 1 + (GALAXY_BRAIN / BRAIN_SIZE - 1) * ($this->backOut)($g);
        $bx = $ox + $thumbX; $by = $oy + BRAIN_Y + (GALAXY_BRAIN_Y - BRAIN_Y) * $g;
        if ($g > 0) {
            $this->drawRays($p, $bx, $by, $g, $secs);
            $this->drawGlow($p, $bx, $by, 34, 0x7c74ff, 0.4 * $g);
        }
        if ($g < 1) {
            $size = BRAIN_SIZE * $grow;
            $p->fillPath(icon(array_slice($this->brain, 0, 2), $bx, $by, $size), BRAIN_FILL);
            $p->strokePath(icon($this->brain, $bx, $by, $size), 2 * $size / 24, BRAIN_STROKE);
        }
        if ($g > 0) {
            // The brain in Galaxy's colors, g of the way there.
            $size = BRAIN_SIZE * $grow;
            $p->fillPathGradient(icon(array_slice($this->brain, 0, 2), $bx, $by, $size), new LinearGradient(GALAXY_FROM, $g, GALAXY_TO, $g, 135, oklab: true));
            $p->strokePath(icon($this->brain, $bx, $by, $size), 2 * $size / 24, GALAXY_STROKE, $g);
            $this->drawSparkles($p, $bx, $by, $g, $secs);
        }
        if ($this->galaxyLeft === 0 && $this->galaxyAt !== 0) {
            $since = $t - $this->galaxyAt;
            $this->drawBurst($p, $bx, $by, $since / BURST_TIME);
            $k = $since / FLASH_TIME;
            if ($k < 1) { $this->drawGlow($p, $bx, $by, 44, 0xf4f2ff, 0.6 * (1 - $k) ** 1.6); }
        }
        return $moving;
    }

    /** Draws the level's label, and the last one while it goes, with the pen at $x on the baseline at $y, and reports whether they move. */
    private function drawLabel(Painter $p, float $x, float $y, int $t): bool
    {
        $since = $t - $this->changedAt;
        $cur = LEVELS[$this->level];
        if ($this->changedAt === 0 || $since >= OUT_DELAY + OUT_TIME && $since >= IN_STAGGER * (mb_strlen($cur) - 1) + IN_TIME) {
            $this->words["\0" . $cur]->draw($p, $x, $y, self::labelColorOf($cur));
            return false;
        }
        // Draws $str with its pen at $x in $rgb, $k opaque, as glyphs or blurred.
        $text = function (string $str, array $glyphs, float $x, int $rgb, float $k, float $dy, float $blur) use ($p, $y): void {
            if ($k <= 0) { return; }
            if ($blur * $p->scale() < 0.05) {
                $p->glyphs($glyphs, $x, $y + $dy, $rgb, $k);
                return;
            }
            $b = $this->sheet->blurred($str, $p->scale(), $blur, $rgb, $k);
            if ($b !== null) {
                [$img, $bx, $by] = $b;
                [$bw, $bh] = $img->size();
                $p->image($img, [$x + $bx, $y + $dy + $by, $bw / $p->scale(), $bh / $p->scale()], Node::FILL_BOX);
            }
        };
        // The old label goes as a whole, kerned, as a text of its own.
        $old = LEVELS[$this->prev];
        $k = ($this->labelEase)(($since - OUT_DELAY) / OUT_TIME);
        $text($old, $this->words["\1" . $old]->letters[0], $x, self::labelColorOf($old), 1 - $k, -OUT_Y * $k, OUT_BLUR * $k);
        // The new one comes letter by letter.
        $word = $this->words["\0" . $cur];
        foreach (mb_str_split($cur) as $i => $r) {
            $k = ($this->labelEase)(($since - IN_STAGGER * $i) / IN_TIME);
            $text($r, $word->letters[$i], $x + $word->x[$i], self::labelColorOf($cur), $k, IN_Y * (1 - $k), IN_BLUR * (1 - $k));
        }
        return true;
    }

    /** The color of a level's label: Galaxy's is sky blue. */
    private static function labelColorOf(string $label): int
    {
        return $label === LEVELS[GALAXY] ? GALAXY_LABEL : VALUE_COLOR;
    }

    /** Draws the help button, a question mark in a circle of 1 point, centered on ($cx, $cy). */
    private function drawHelp(Painter $p, float $cx, float $cy, int $rgb): void
    {
        $p->strokePath((new Path())->circle($cx, $cy, HELP_RADIUS - 0.5), 1, $rgb);
        // The mark, centered in its line as CSS centers it.
        $g = $this->helpMark;
        $m = $this->helpMetrics;
        $p->glyphs($g, $cx + HELP_MARK_DX - ($g[0]->advance ?? 0) / 2, $cy + ($m['ascent'] - $m['descent']) / 2, $rgb);
    }

    /**
     * Draws the track's fill in Galaxy, up to the thumb at $thumbX: deep
     * violet, with a grid of pixels shimmering, dim far from the brain and
     * bright next to it.
     */
    private function drawGalaxyTrack(Painter $p, float $ox, float $oy, float $thumbX, float $g, float $secs): void
    {
        $end = $thumbX - FILL_INSET;
        if ($end <= TRACK_X || $g <= 0) { return; }
        $p->fillPathGradient(roundRect(new Path(), $ox + TRACK_X, $oy + TRACK_Y, $end - TRACK_X, TRACK_H, TRACK_RADIUS), new LinearGradient(GALAXY_TRACK, $g, GALAXY_TRACK2, $g, 90, oklab: true));
        $cols = (int)(($thumbX - TRACK_X - 2) / PIXEL_PITCH);
        for ($c = 0; $c < $cols; $c++) {
            $x = TRACK_X + 0.5 + $c * PIXEL_PITCH;
            $u = ($x - TRACK_X) / ($thumbX - TRACK_X); // toward the brain
            for ($r = 0; $r < 5; $r++) {
                if (hash3($c, $r, 0) > 0.45 + $u) { continue; } // gaps, far from the brain
                $bright = 0.12 + 0.88 * smoothstep(0.3, 0.95, $u);
                $a = $bright * (0.5 + 0.5 * shimmer($c, $r, $secs)) * $g;
                if ($a < 0.03) { continue; }
                $color = PIXEL_COLORS[(int)(hash3($c, $r, 4) * count(PIXEL_COLORS)) % count(PIXEL_COLORS)];
                $p->fill([$ox + $x, $oy + TRACK_Y + 0.5 + $r * PIXEL_PITCH, 3, 3], $color, 0.8, $a);
            }
        }
    }

    /** Draws the dotted rays streaming out of the brain centered on ($cx, $cy). */
    private function drawRays(Painter $p, float $cx, float $cy, float $g, float $secs): void
    {
        $rays = 16; $from = 19;
        for ($i = 0; $i < $rays; $i++) {
            $angle = ($i + 0.4 * hash3($i, 0, 5)) * 2 * M_PI / $rays;
            $length = 10 + 26 * hash3($i, 0, 6) * hash3($i, 0, 12);
            [$sin, $cos] = [sin($angle), cos($angle)];
            for ($d = $from; $d < $from + $length; $d += 2) {
                $k = ($d - $from) / $length; // along the ray
                $lit = 0.4 + 0.6 * twinkle($i, $d, $secs * 1.6);
                $a = (1 - $k) * 0.65 * $lit * $g;
                if ($a < 0.03) { continue; }
                $color = RAY_COLORS[(int)(hash3($i, $d, 7) * count(RAY_COLORS)) % count(RAY_COLORS)];
                $p->fill([$cx + $d * $cos - 0.5, $cy + $d * $sin - 0.5, 1, 1], $color, 0, $a);
            }
        }
    }

    /** Draws the ring of pixels bursting out of the brain centered on ($cx, $cy), $k into the burst. */
    private function drawBurst(Painter $p, float $cx, float $cy, float $k): void
    {
        if ($k <= 0 || $k >= 1) { return; }
        $n = 28;
        $e = 1 - (1 - $k) ** 3;
        for ($i = 0; $i < $n; $i++) {
            $angle = ($i + 0.3 * hash3($i, 1, 8)) * 2 * M_PI / $n;
            $r = 11 + (6 + 12 * hash3($i, 1, 9)) * $e;
            $x = $cx + $r * cos($angle) * 1.05; $y = $cy + $r * sin($angle) * 0.95;
            $p->fill([$x - 1, $y - 1, 2, 2], BURST_COLORS[$i % count(BURST_COLORS)], 0, 1 - $k * $k);
        }
    }

    /** Draws the pixels glinting on the brain. */
    private function drawSparkles(Painter $p, float $cx, float $cy, float $g, float $secs): void
    {
        for ($i = 0; $i < 10; $i++) {
            $x = $cx - 10 + 20 * hash3($i, 2, 10);
            $y = $cy - 9 + 18 * hash3($i, 2, 11);
            $a = twinkle($i, 99, $secs * 1.3) * $g;
            if ($a < 0.05) { continue; }
            $p->fill([$x - 0.75, $y - 0.75, 1.5, 1.5], $i % 3 === 0 ? 0x7dd3fc : 0xffffff, 0, $a);
        }
    }

    /** Draws a soft glow in $rgb at opacity $a, $r points in radius, centered on ($cx, $cy), from a bitmap made once for 32 steps of opacity. */
    private function drawGlow(Painter $p, float $cx, float $cy, float $r, int $rgb, float $a): void
    {
        $steps = 32;
        $step = (int)($a * $steps + 0.5);
        if ($step <= 0) { return; }
        $key = $step << 24 | $rgb;
        if (!isset($this->glows[$key])) {
            $n = 96; $alpha = $step / $steps; $px = '';
            [$cr, $cg, $cb] = [$rgb >> 16 & 0xff, $rgb >> 8 & 0xff, $rgb & 0xff];
            for ($y = 0; $y < $n; $y++) {
                for ($x = 0; $x < $n; $x++) {
                    $dx = ($x + 0.5) / $n * 2 - 1; $dy = ($y + 0.5) / $n * 2 - 1;
                    $d2 = $dx * $dx + $dy * $dy;
                    $v = $alpha * exp(-$d2 * 4.5) * max(0, 1 - $d2);
                    $px .= chr((int)($cr * $v + 0.5)) . chr((int)($cg * $v + 0.5)) . chr((int)($cb * $v + 0.5)) . chr((int)(255 * $v + 0.5));
                }
            }
            $this->glows[$key] = Image::fromPixels($n, $n, $px);
        }
        $p->image($this->glows[$key], [$cx - $r, $cy - $r, 2 * $r, 2 * $r], Node::FILL_BOX);
    }
}

function view(Context $c, Slider $s): void
{
    $s->prepare($c);
    [$w, $h] = $c->size();
    // Centered across, on whole points so that the card's edges are crisp.
    $ox = round(($w - CARD_W) / 2); $oy = round(CARD_Y + ($h - WINDOW_H) / 2);
    $c->column(0, 0, 1, PAGE_COLOR)->fill()->draw(function (Painter $p, array $r) use ($s, $ox, $oy): void {
        if ($s->drawCard($p, $ox, $oy, $p->now())) { $p->animationFrame(); }
    });
      // The title bar, under the traffic lights, drags the window.
      $c->column()->absolute()->left(0)->top(0)->size($w, max($c->titleBar()['height'], 32))->dragWindow();
      $c->end();
      // The slider takes the keys, as assistive technology sees it, and
      // the pointer across the track; its content box spans the thumb's
      // travel. A drag moves the thumb with the pointer rather than from
      // level to level.
      $sb = $c->sliderBase($s->value, 0, GALAXY, 1)->focusRing(false)->absolute()
          ->left($ox + TRACK_X)->top($oy + TRACK_Y - 6)->size(TRACK_W, TRACK_H + 12)->paddingX(STOP_X - TRACK_X);
      $t = $c->now();
      if ($c->changed($sb) && !$s->holding) { $s->setLevel((int)round($s->value), $t); }
      if ($c->dragged($sb) !== null) {
          [$x] = $c->pointerPosition($sb);
          $s->hold(($x - (STOP_X - TRACK_X)) / STOP_GAP, $t);
      } else {
          $s->release($t);
      }
    $c->end();
}

$s = new Slider();
App::setName('Effort');
// The demo is dark whatever the system's appearance, and so are the window controls over it.
AppTheme::setSource(ThemeSource::Dark);
App::whenReady(function () use ($s) {
    // The card floats on the page's background, under the traffic lights.
    new Window(new WindowOptions(title: 'Effort', width: WINDOW_W, height: WINDOW_H, titleBarStyle: 'hidden', backgroundColor: '#101010',
        content: Content::view(fn(Context $c) => view($c, $s))));
});
App::run();
