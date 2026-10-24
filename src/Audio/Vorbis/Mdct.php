<?php
declare(strict_types=1);

namespace MyPHP\Audio\Vorbis;

/**
 * The inverse MDCT of Vorbis I (section 1.3.2): N outputs of N/2
 * coefficients, y[n] = Σ X[k] cos(2π/N (n + 1/2 + N/4)(k + 1/2)), through
 * a DCT-IV of N/2 points, itself a complex FFT of N/4 points between two
 * twiddles; the DCT-IV's symmetries unfold it into the N outputs.
 * @internal
 */
final class Mdct
{
    /** @var array<int, array> by N/2: the twiddles and the FFT's bit reversal and roots */
    private static array $plans = [];

    /** @param list<float> $x N/2 coefficients @return list<float> N samples */
    public static function inverse(array $x): array
    {
        $m = count($x);
        $u = self::dct4($x);
        // y[n] for n + M/2 = j: u[j] below M, -u[2M-1-j] up to 2M, -u[j-2M] beyond.
        $y = [];
        $h = $m >> 1;
        for ($j = $h; $j < $m; $j++) { $y[] = $u[$j]; }
        for ($j = $m; $j < 2 * $m; $j++) { $y[] = -$u[2 * $m - 1 - $j]; }
        for ($j = 2 * $m; $j < 2 * $m + $h; $j++) { $y[] = -$u[$j - 2 * $m]; }
        return $y;
    }

    /** The DCT-IV of $x: u[n] = Σ x[k] cos(π/M (n + 1/2)(k + 1/2)). @param list<float> $x @return list<float> */
    public static function dct4(array $x): array
    {
        $m = count($x);
        $q = $m >> 1;
        $plan = self::$plans[$m] ??= self::plan($m);
        [$cw, $sw, $rev, $rootRe, $rootIm, $pc, $ps] = $plan;
        // Pre-twiddle the pairs x[2k] + i x[M-1-2k] by exp(-iπ(4k+1)/(4M)), in bit-reversed order for the FFT.
        $re = array_fill(0, $q, 0.0); $im = array_fill(0, $q, 0.0);
        for ($k = 0; $k < $q; $k++) {
            $a = $x[2 * $k]; $b = $x[$m - 1 - 2 * $k];
            $c = $cw[$k]; $s = $sw[$k];
            $t = $rev[$k];
            $re[$t] = $a * $c + $b * $s;
            $im[$t] = $b * $c - $a * $s;
        }
        // A radix-2 FFT of Q points, exp(-2πi nk/Q).
        for ($size = 2; $size <= $q; $size <<= 1) {
            $half = $size >> 1; $stride = intdiv($q, $size);
            for ($start = 0; $start < $q; $start += $size) {
                for ($j = 0, $w = 0; $j < $half; $j++, $w += $stride) {
                    $wr = $rootRe[$w]; $wi = $rootIm[$w];
                    $i1 = $start + $j; $i2 = $i1 + $half;
                    $tr = $re[$i2] * $wr - $im[$i2] * $wi;
                    $ti = $re[$i2] * $wi + $im[$i2] * $wr;
                    $re[$i2] = $re[$i1] - $tr; $im[$i2] = $im[$i1] - $ti;
                    $re[$i1] += $tr; $im[$i1] += $ti;
                }
            }
        }
        // Post-twiddle by exp(-iπn/M), and the outputs at 2n and M-1-2n.
        $u = array_fill(0, $m, 0.0);
        for ($n = 0; $n < $q; $n++) {
            $c = $pc[$n]; $s = $ps[$n];
            $r = $re[$n] * $c + $im[$n] * $s;
            $i = $im[$n] * $c - $re[$n] * $s;
            $u[2 * $n] = $r;
            $u[$m - 1 - 2 * $n] = -$i;
        }
        return $u;
    }

    private static function plan(int $m): array
    {
        $q = $m >> 1;
        $cw = []; $sw = [];
        for ($k = 0; $k < $q; $k++) { $a = M_PI * (4 * $k + 1) / (4 * $m); $cw[] = cos($a); $sw[] = sin($a); }
        $bits = (int)round(log($q, 2));
        $rev = [];
        for ($k = 0; $k < $q; $k++) {
            $r = 0;
            for ($b = 0; $b < $bits; $b++) { $r |= (($k >> $b) & 1) << ($bits - 1 - $b); }
            $rev[] = $r;
        }
        $rootRe = []; $rootIm = [];
        for ($k = 0; $k < max(1, $q >> 1); $k++) { $rootRe[] = cos(2 * M_PI * $k / $q); $rootIm[] = -sin(2 * M_PI * $k / $q); }
        $pc = []; $ps = [];
        for ($n = 0; $n < $q; $n++) { $pc[] = cos(M_PI * $n / $m); $ps[] = sin(M_PI * $n / $m); }
        return [$cw, $sw, $rev, $rootRe, $rootIm, $pc, $ps];
    }
}
