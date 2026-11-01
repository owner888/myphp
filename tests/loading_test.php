<?php
// The loading plugin, as mygo's plugins/loading: loading.dev's 29
// indicators, each in its box at every point of its cycle, moving in
// cycles and asking for frames as it does; still where the desktop asks
// for less motion; paused where it is, going on from there; in the color
// given, else the theme's text color; and its easing curves.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Plugins\Loading\{Drawing, Loading, Options, Spinner};
use MyPHP\UI\{Context, DisplayList, Path, Preferences};

/** A harness showing $s alone, labelled by its name, with the options $o gives at each frame. */
$loadingTester = function (Spinner $s, \Closure $o, ?int &$now) {
    return poseTester(function (Context $c) use ($s, $o) {
        $c->column()->fill()->center();
        ($s->new)($c, $o())->label($s->name);
        $c->end();
    }, $now, 64, 64);
};
/** What a frame paints: its quads and transforms. */
$picture = function (Headless $h): string {
    $h->frame(1);
    $l = $h->paint();
    return $l->copyOut(0, $l->n) . $l->transformBytes();
};
/**
 * Where each quad a frame paints lies in the window, but the window's
 * background: its outline through its transform; a path's quad, its curves
 * and the reach of its stroke, as a headless list draws a path as its
 * bounds (rotated, those would stand past what it covers).
 * @return list<array{0: float, 1: float, 2: float, 3: float, 4: int}> x0 y0 x1 y1 rgb
 */
$outline = function (array $f): array { // a quad's outline: its corners, rounded by its radius
    $r = min($f[8], $f[2] / 2, $f[3] / 2);
    $pts = [];
    foreach ([[$f[0] + $r, $f[1] + $r], [$f[0] + $f[2] - $r, $f[1] + $r], [$f[0] + $r, $f[1] + $f[3] - $r], [$f[0] + $f[2] - $r, $f[1] + $f[3] - $r]] as [$x, $y]) {
        for ($k = 0; $k < ($r > 0 ? 32 : 1); $k++) { $pts[] = [$x + $r * cos($k * M_PI / 16), $y + $r * sin($k * M_PI / 16)]; }
    }
    return $pts;
};
$inks = function (Headless $h) use ($outline): array {
    $h->noParts = true;
    $l = $h->painted() ?? $h->paint();
    $paths = [];
    $l->pathQuad = function (Path $p, float $width) use ($l, &$paths): array {
        if (($b = $p->bounds()) === null) { return []; }
        $pts = []; $at = [0.0, 0.0];
        foreach ($p->cmds as $c) {
            $n = match ($c[0]) { Path::MOVE, Path::LINE => 1, Path::QUAD => 2, Path::CUBE => 3, default => 0 };
            if ($n === 0) { continue; }
            $ctl = array_merge([$at], array_map(fn($i) => [$c[1 + 2 * $i], $c[2 + 2 * $i]], range(0, $n - 1)));
            for ($k = $c[0] === Path::MOVE ? 16 : 1; $k <= 16; $k++) { // de Casteljau at sixteenths
                $q = $ctl;
                while (count($q) > 1) { $q = array_map(fn($i) => [$q[$i][0] + ($q[$i + 1][0] - $q[$i][0]) * $k / 16, $q[$i][1] + ($q[$i + 1][1] - $q[$i][1]) * $k / 16], range(0, count($q) - 2)); }
                $pts[] = $q[0];
            }
            $at = $ctl[$n];
        }
        $paths[$l->n] = [$pts, $width > 0 ? $p->reach($width) : 0.0];
        return [[$b[0], $b[1], $b[2] - $b[0], $b[3] - $b[1], 0, 0]];
    };
    $h->frame(1);
    $h->paint();
    $q = unpack('g*', $l->copyOut(0, $l->n));
    $t = unpack('g*', $l->transformBytes()) ?: [];
    $out = [];
    for ($i = 0; $i < $l->n; $i++) {
        $f = array_slice($q, $i * 16, 16);
        if ($f[9] < 0 || $f[7] <= 0 || ($f[9] < DisplayList::KIND_STRIDE && $f[2] >= $h->w && $f[3] >= $h->h)) { continue; } // a slot of the quad before; nothing shown; the window's background
        $e = (int)floor($f[9] / DisplayList::KIND_STRIDE);
        [$a, $b, $c, $d, $x, $y] = $e > 0 ? array_slice($t, $e * DisplayList::FLOATS_PER_TRANSFORM, 6) : [1, 0, 0, 1, 0, 0];
        [$pts, $reach] = $paths[$i] ?? [$outline($f), 0.0];
        $xs = $ys = [];
        foreach ($pts as [$px, $py]) { $xs[] = $a * $px + $c * $py + $x; $ys[] = $b * $px + $d * $py + $y; }
        $scale = sqrt(abs($a * $d - $b * $c));
        $out[] = [min($xs) - $reach * $scale, min($ys) - $reach * $scale, max($xs) + $reach * $scale, max($ys) + $reach * $scale, ((int)round($f[4] * 255) << 16) | ((int)round($f[5] * 255) << 8) | (int)round($f[6] * 255)];
    }
    return $out;
};

$all = Loading::all();
same(29, count($all), 'loading.dev\'s 29 indicators');
same(29, count(array_unique(array_map(fn(Spinner $s) => $s->name, $all))), 'each named once');

// Each in its box, of its aspect, at every eighth of its cycle, and nothing outside it.
$o = new Options(size: 40, duration: 1.0);
foreach ($all as $s) {
    $h = $loadingTester($s, fn() => $o, $now);
    $r = $h->rect($h->byLabel($s->name));
    $aspect = ['Bouncing dots' => 1.15, 'Linear dots' => 0.9375][$s->name] ?? 1.0;
    same([40.0, 40 * $aspect], [$r[3], $r[2]], "{$s->name}: 40 high, {$aspect} as wide");
    $outside = [];
    for ($step = 0; $step < 8; $step++) {
        $drawn = $inks($h);
        check(count($drawn) > 0, "{$s->name} at $step/8: draws");
        foreach ($drawn as [$x0, $y0, $x1, $y1]) {
            if ($x0 < $r[0] - 2 || $y0 < $r[1] - 2 || $x1 > $r[0] + $r[2] + 2 || $y1 > $r[1] + $r[3] + 2) { $outside[] = "$step/8: " . json_encode(array_map(fn($v) => round($v, 1), [$x0, $y0, $x1, $y1])); }
        }
        $now += 125_000_000;
    }
    same([], $outside, "{$s->name}: nothing outside its box " . json_encode($r));
}

// Moving, asking for frames, the same two cycles later.
$o = new Options(duration: 1.0);
foreach ($all as $s) {
    $h = $loadingTester($s, fn() => $o, $now);
    $asks = $h->rt->paintAsks;
    $start = $picture($h);
    check($h->rt->paintAsks > $asks, "{$s->name}: asks for frames");
    $now += 300_000_000;
    $a = $picture($h);
    $now += 2_000_000_000;
    $b = $picture($h);
    check($start !== $a, "{$s->name}: moves 0.3 cycles in");
    same(bin2hex($a), bin2hex($b), "{$s->name}: the same two cycles later");
}

// Less motion: still, asking for no frames, drawing.
foreach ($all as $s) {
    $h = $loadingTester($s, fn() => new Options(), $now);
    $h->rt->preferences = new Preferences(reduceMotion: true);
    $asks = $h->rt->paintAsks;
    $still = $picture($h);
    same($asks, $h->rt->paintAsks, "{$s->name}: asks for no frames at rest");
    $now += 1_000_000_000;
    check($still === $picture($h), "{$s->name}: still at rest");
    check(count($inks($h)) > 0, "{$s->name}: draws at rest");
}

// Paused: where it is, going on from there.
foreach ($all as $s) {
    $paused = false;
    $h = $loadingTester($s, function () use (&$paused) { return new Options(duration: 1.0, paused: $paused); }, $now);
    $now += 300_000_000;
    $paused = true;
    $held = $picture($h);
    $asks = $h->rt->paintAsks;
    $now += 400_000_000;
    check($held === $picture($h) && $asks === $h->rt->paintAsks, "{$s->name}: still and asking for nothing paused");
    $paused = false;
    check($held === $picture($h), "{$s->name}: where it stopped as it goes on");
    $now += 200_000_000;
    check($held !== $picture($h), "{$s->name}: goes on");
}

// The color given, else the theme's text.
foreach ([null, 0xff0000] as $color) {
    $text = 0;
    $h = poseTester(function (Context $c) use ($color, &$text) {
        $text = $c->theme->text;
        $c->column()->fill()->center();
        Loading::morph($c, new Options(size: 40, color: $color));
        $c->end();
    }, $now, 64, 64);
    $colors = array_values(array_unique(array_map(fn($q) => $q[4], $inks($h))));
    same([$color ?? $text], $colors, sprintf('drawn in %06x', $color ?? $text));
}

// loading.dev's curves: ease-in-out, and Cascade's overshooting.
foreach ([[0, 0], [0.5, 0.5], [1, 1], [0.25, 0.1291]] as [$t, $want]) {
    closeFloat($want, Drawing::easeInOut($t), "ease-in-out($t)");
}
check(Drawing::overshoot(0.1) < 0 && Drawing::overshoot(0.9) > 1, 'overshoot below 0 and past 1');
