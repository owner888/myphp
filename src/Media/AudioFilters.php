<?php
declare(strict_types=1);

namespace MyPHP\Media;

use RuntimeException;

/**
 * What a list of audio filters (mpv's af, IINA's Audio Filters) does to
 * the sound, as MyPHP does it without mpv: FFmpeg's filters of these
 * names, by their options, in their order, as af_biquads.c, af_volume.c
 * and af_pan.c compute them.
 * - volume of volume (a factor, or dB): the sound times it;
 * - equalizer of f, t, w and g (a peaking band), bass and treble of g, f,
 *   t and w (the low and the high shelf), highpass and lowpass of f, p, t
 *   and w (one pole, or two): biquads;
 * - pan of a layout (mono, stereo, or Nc) and each output channel a sum of
 *   the input's (c0=0.5*c0+0.5*c1, c1=c0, c0<c0+c1 normalized).
 * A filter of another name, or of what it cannot do, throws: IINA's
 * "Error occurred when setting filters".
 *
 * @internal Player, Native\Renderers
 */
final class AudioFilters
{
    /** FFmpeg's width types: Hz, Q, octaves, slope, kHz. */
    private const WIDTHS = ['h' => 'h', 'q' => 'q', 'o' => 'o', 's' => 's', 'k' => 'k'];

    /**
     * The stages, in order: ['gain', factor], ['biquad', type, frequency,
     * gain dB, width type, width, poles], ['pan', channels out, rows of
     * each output channel's factors of the input's].
     * @var list<array<int, mixed>>
     */
    public array $stages = [];

    /** @param list<Filter> $filters */
    public function __construct(array $filters)
    {
        foreach ($filters as $f) {
            $what = $f->what() ?? throw self::wrong($f, 'its graph is not one filter');
            [$name, $p] = $what;
            $this->stages[] = match ($name) {
                'volume' => ['gain', self::factor($p['volume'] ?? $p['0'] ?? '1', $f)],
                'equalizer' => ['biquad', 'peak', self::num($p, ['f', 'frequency', '0'], null, $f), self::num($p, ['g', 'gain', '3'], 0, $f), self::width($p, 'q', $f), self::num($p, ['w', 'width', '2'], 1, $f), 2],
                'bass', 'lowshelf' => ['biquad', 'low', self::num($p, ['f', 'frequency'], 100, $f), self::num($p, ['g', 'gain', '0'], 0, $f), self::width($p, 'q', $f), self::num($p, ['w', 'width'], 0.5, $f), 2],
                'treble', 'highshelf' => ['biquad', 'high', self::num($p, ['f', 'frequency'], 3000, $f), self::num($p, ['g', 'gain', '0'], 0, $f), self::width($p, 'q', $f), self::num($p, ['w', 'width'], 0.5, $f), 2],
                'highpass', 'lowpass' => ['biquad', $name === 'highpass' ? 'hp' : 'lp', self::num($p, ['f', 'frequency', '0'], $name === 'highpass' ? 3000 : 500, $f), 0.0,
                    self::width($p, 'q', $f), self::num($p, ['w', 'width'], 0.707, $f), self::poles($p, $f)],
                'pan' => self::pan($p['args'] ?? $p['0'] ?? '', $f),
                default => throw self::wrong($f, 'MyPHP has no filter of this name'),
            };
            $last = $this->stages[array_key_last($this->stages)];
            if ($last[0] === 'biquad' && $last[2] <= 0) { throw self::wrong($f, 'its frequency is no more than 0'); }
        }
    }

    /** Whether the filters do anything. */
    public function any(): bool
    {
        return $this->stages !== [];
    }

    /**
     * Biquad stage $s at $rate: [b0, b1, b2, a1, a2], normalized (a0 1),
     * vDSP's order; null past half the rate (FFmpeg bypasses it).
     * @param array<int, mixed> $s
     * @return ?array{0: float, 1: float, 2: float, 3: float, 4: float}
     */
    public static function coefficients(array $s, int $rate): ?array
    {
        [, $type, $f, $g, $wt, $width, $poles] = $s;
        $w0 = 2 * M_PI * $f / $rate;
        if ($w0 > M_PI) {
            return null;
        }
        $a = 10 ** ($g / 40);
        [$sn, $cs] = [sin($w0), cos($w0)];
        $alpha = match ($wt) {
            'h' => $sn / (2 * $f / $width),
            'k' => $sn / (2 * $f / ($width * 1000)),
            'o' => $sn * sinh(log(2) / 2 * $width * $w0 / $sn),
            's' => $sn / 2 * sqrt(($a + 1 / $a) * (1 / $width - 1) + 2),
            default => $sn / (2 * $width),
        };
        $sa = 2 * sqrt($a) * $alpha;
        [$b0, $b1, $b2, $a0, $a1, $a2] = match ($type) {
            'peak' => [1 + $alpha * $a, -2 * $cs, 1 - $alpha * $a, 1 + $alpha / $a, -2 * $cs, 1 - $alpha / $a],
            'low' => [$a * (($a + 1) - ($a - 1) * $cs + $sa), 2 * $a * (($a - 1) - ($a + 1) * $cs), $a * (($a + 1) - ($a - 1) * $cs - $sa),
                ($a + 1) + ($a - 1) * $cs + $sa, -2 * (($a - 1) + ($a + 1) * $cs), ($a + 1) + ($a - 1) * $cs - $sa],
            'high' => [$a * (($a + 1) + ($a - 1) * $cs + $sa), -2 * $a * (($a - 1) + ($a + 1) * $cs), $a * (($a + 1) + ($a - 1) * $cs - $sa),
                ($a + 1) - ($a - 1) * $cs + $sa, 2 * (($a - 1) - ($a + 1) * $cs), ($a + 1) - ($a - 1) * $cs - $sa],
            'hp' => $poles === 1 ? [(1 + exp(-$w0)) / 2, -(1 + exp(-$w0)) / 2, 0.0, 1.0, -exp(-$w0), 0.0]
                : [(1 + $cs) / 2, -(1 + $cs), (1 + $cs) / 2, 1 + $alpha, -2 * $cs, 1 - $alpha],
            'lp' => $poles === 1 ? [1 - exp(-$w0), 0.0, 0.0, 1.0, -exp(-$w0), 0.0]
                : [(1 - $cs) / 2, 1 - $cs, (1 - $cs) / 2, 1 + $alpha, -2 * $cs, 1 - $alpha],
        };
        return [$b0 / $a0, $b1 / $a0, $b2 / $a0, $a1 / $a0, $a2 / $a0];
    }

    /** A factor written as 2, 0.5 or 6dB. */
    private static function factor(string $v, Filter $f): float
    {
        if (preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*dB\s*$/i', $v, $m) === 1) {
            return 10 ** ((float)$m[1] / 20);
        }
        if (!is_numeric($v) || (float)$v < 0) { throw self::wrong($f, "\"$v\" is no volume (MyPHP takes no expressions)"); }
        return (float)$v;
    }

    /** @param array<string, string> $p @param list<string> $keys */
    private static function num(array $p, array $keys, ?float $default, Filter $f): float
    {
        foreach ($keys as $k) {
            if (isset($p[$k]) && $p[$k] !== '') {
                if (!is_numeric($p[$k])) { throw self::wrong($f, "\"{$p[$k]}\" is no number"); }
                return (float)$p[$k];
            }
        }
        return $default ?? throw self::wrong($f, $keys[0] . ' is needed');
    }

    /** @param array<string, string> $p */
    private static function width(array $p, string $default, Filter $f): string
    {
        $t = $p['t'] ?? $p['width_type'] ?? $default;
        return self::WIDTHS[$t] ?? throw self::wrong($f, "no width type \"$t\"");
    }

    /** @param array<string, string> $p */
    private static function poles(array $p, Filter $f): int
    {
        $n = (int)self::num($p, ['p', 'poles'], 2, $f);
        return in_array($n, [1, 2], true) ? $n : throw self::wrong($f, 'its poles are 1 or 2');
    }

    /**
     * FFmpeg's pan of $args: a layout, then `|` each output channel's sum
     * of the input's ("=" as it is, "<" normalized to a sum of 1).
     * @return array{0: string, 1: int, 2: list<list<float>>}
     */
    private static function pan(string $args, Filter $f): array
    {
        $parts = array_map('trim', explode('|', $args));
        $layout = array_shift($parts);
        $n = match (true) {
            $layout === 'mono' => 1,
            $layout === 'stereo' => 2,
            preg_match('/^(\d)c$/', $layout, $m) === 1 => (int)$m[1],
            default => throw self::wrong($f, "\"$layout\" is no layout MyPHP knows (mono, stereo, Nc)"),
        };
        $rows = array_fill(0, $n, []);
        foreach ($parts as $part) {
            if (preg_match('/^c(\d+)\s*([=<])\s*(.+)$/', $part, $m) !== 1 || (int)$m[1] >= $n) {
                throw self::wrong($f, "\"$part\" is no output channel's sum");
            }
            $row = [];
            foreach (preg_split('/(?=[+-])/', str_replace(' ', '', $m[3]), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $term) {
                if (preg_match('/^([+-]?)(?:(\d*\.?\d+)\*)?c(\d+)$/', $term, $t) !== 1) {
                    throw self::wrong($f, "\"$term\" is no channel times a factor");
                }
                $k = ($t[2] !== '' ? (float)$t[2] : 1.0) * ($t[1] === '-' ? -1 : 1);
                $row[(int)$t[3]] = ($row[(int)$t[3]] ?? 0.0) + $k;
            }
            if ($m[2] === '<' && ($sum = array_sum(array_map('abs', $row))) > 1) { $row = array_map(fn($k) => $k / $sum, $row); }
            $dense = array_fill(0, $row === [] ? 0 : max(array_keys($row)) + 1, 0.0);
            foreach ($row as $c => $k) { $dense[$c] = $k; }
            $rows[(int)$m[1]] = $dense;
        }
        return ['pan', $n, $rows];
    }

    private static function wrong(Filter $f, string $why): RuntimeException
    {
        return new RuntimeException("{$f->string()}: $why");
    }
}
