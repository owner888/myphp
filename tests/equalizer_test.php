<?php
// IINA's equalizer (Native\Equalizer, FFmpeg's equalizer a band, as
// IINA's filter for mpv): each band's biquad, its gain at its frequency
// and none far from it, the bands of no gain and those past half the rate
// left out; on macOS, vDSP's cascade over interleaved channels as the
// biquads' own difference equation gives it, kept across buffers, and
// the system's decoder giving a Matroska track's AAC as float PCM.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Native\Equalizer;

$response = function (array $sections, float $f, int $rate): float { // |H| of the cascade at $f
    $w = 2 * M_PI * $f / $rate; $h = 1.0;
    foreach ($sections as [$b0, $b1, $b2, $a1, $a2]) {
        $nr = $b0 + $b1 * cos($w) + $b2 * cos(2 * $w); $ni = -$b1 * sin($w) - $b2 * sin(2 * $w);
        $dr = 1 + $a1 * cos($w) + $a2 * cos(2 * $w); $di = -$a1 * sin($w) - $a2 * sin(2 * $w);
        $h *= sqrt(($nr ** 2 + $ni ** 2) / ($dr ** 2 + $di ** 2));
    }
    return $h;
};
$db = fn(float $h): float => 20 * log10($h);
same([], Equalizer::coefficients(array_fill(0, 10, 0.0), 48000), 'flat: no band filters');
check(Equalizer::flat([0, 0.0, 0, 0, 0, 0, 0, 0, 0, 0]) && !Equalizer::flat([0, 0, 0, 0, 0, 0.5, 0, 0, 0, 0]), 'flat is none of a gain');
$one = Equalizer::coefficients([0, 0, 0, 0, 0, 6, 0, 0, 0, 0], 48000);
same(1, count($one), 'a band of a gain: one biquad');
check(abs($db($response($one, 1000, 48000)) - 6) < 0.01, 'its gain at its frequency: ' . round($db($response($one, 1000, 48000)), 3) . ' dB');
check(abs($db($response($one, 32, 48000))) < 0.3 && abs($db($response($one, 16000, 48000))) < 0.3, 'none far from it');
$cut = Equalizer::coefficients([-12, 0, 0, 0, 0, 0, 0, 0, 0, 0], 44100);
check(abs($db($response($cut, 32, 44100)) + 12) < 0.01, 'a cut of 12 dB at 32 Hz');
same(1, count(Equalizer::coefficients([0, 0, 0, 0, 0, 0, 0, 0, 3, 3], 22050)), 'past half the rate (16 kHz of 22.05 kHz) a band is none, as FFmpeg bypasses it');
$err = null;
try { Equalizer::coefficients([1, 2, 3], 48000); } catch (\RuntimeException $e) { $err = $e->getMessage(); }
check($err !== null, "not ten bands: $err");

if (PHP_OS_FAMILY !== 'Darwin') {
    echo "  skipped: vDSP and the system's decoder (macOS)\n";
    return;
}
$gains = [3, -2, 4, 0, 1.5, -6, 2, 5, -3, 6];
$rate = 48000; $ch = 2; $frames = 3000;
mt_srand(7);
$x = []; for ($i = 0; $i < $frames * $ch; $i++) { $x[] = mt_rand(-1000, 1000) / 2000; }
$ref = $x; // the cascade by its difference equation, each channel strided, in doubles
foreach (Equalizer::coefficients($gains, $rate) as [$b0, $b1, $b2, $a1, $a2]) {
    for ($c = 0; $c < $ch; $c++) {
        $x1 = $x2 = $y1 = $y2 = 0.0;
        for ($n = $c; $n < $frames * $ch; $n += $ch) {
            $in = $ref[$n]; $y = $b0 * $in + $b1 * $x1 + $b2 * $x2 - $a1 * $y1 - $a2 * $y2;
            $x2 = $x1; $x1 = $in; $y2 = $y1; $y1 = $y; $ref[$n] = $y;
        }
    }
}
$eq = new Equalizer($gains, $rate, $ch);
$half = intdiv($frames, 2) * $ch; // in two buffers: its state goes on from one to the next
$out = array_values(unpack('g*', $eq->process(pack('g*', ...array_slice($x, 0, $half)), intdiv($frames, 2)) . $eq->process(pack('g*', ...array_slice($x, $half)), $frames - intdiv($frames, 2))));
$max = 0.0; foreach ($out as $i => $v) { $max = max($max, abs($v - $ref[$i])); }
check($max < 1e-3, "vDSP's cascade over interleaved stereo, in two buffers, as the difference equation in doubles: $max at most apart (vDSP's state in floats, as FFmpeg's float filter's)");

$m = new \MyPHP\Media\Matroska(__DIR__ . '/testdata/clip.mkv');
$a = array_values(array_filter($m->tracks(), fn($t) => $t->type === \MyPHP\Media\Track::AUDIO))[0];
$d = new \MyPHP\Native\AudioDecoder(\MyPHP\Native\CoreMedia::format($a));
$total = 0; $bytes = 0;
while (($p = $m->next()) !== null) { if ($p->track === $a) { [$pcm, $n] = $d->decode($p); $total += $n; $bytes += strlen($pcm); } }
check(abs($total / $a->sampleRate - 2.0) < 0.15 && $bytes === $total * 2 * 4 && $d->channels === 2, "the system's decoder: the AAC track's frames as float PCM ($total, {$d->channels} channels)");
