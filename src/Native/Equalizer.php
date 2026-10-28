<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;
use RuntimeException;

/**
 * IINA's ten-band equalizer, as its filter for mpv is (FFmpeg's
 * `equalizer=f=…:t=h:width=f/1.224744871:g=…` a band, lavfi's peaking
 * biquads, af_biquads.c): each band's biquad of its gain, the bands in a
 * cascade by Accelerate's vDSP_biquad over each channel of interleaved
 * float PCM, its state kept from one buffer to the next. A band of no
 * gain is none (its biquad is the identity), one past half the rate none
 * (FFmpeg bypasses it).
 *
 * @internal Native\Renderers
 */
final class Equalizer
{
    /** IINA's bands' frequencies, and the width a band takes of its frequency (its Q). */
    public const FREQUENCIES = [32, 64, 125, 250, 500, 1000, 2000, 4000, 8000, 16000], Q = 1.224744871;

    private static ?FFI $v = null;
    private CData $setup, $in, $out;
    /** @var list<CData> each channel's delay line, 2 a section and 2 */
    private array $delays = [];
    private int $sections, $capacity = 0;

    /** @param list<float> $gains the bands' gains, dB */
    public function __construct(array $gains, int $rate, private readonly int $channels)
    {
        $coeffs = self::coefficients($gains, $rate);
        $this->sections = count($coeffs);
        if ($this->sections === 0) {
            throw new RuntimeException('myphp: an equalizer of no band of a gain');
        }
        $v = self::v();
        $c = $v->new('double[' . (5 * $this->sections) . ']');
        foreach (array_merge(...$coeffs) as $i => $x) { $c[$i] = $x; }
        $this->setup = $v->vDSP_biquad_CreateSetup($c, $this->sections);
        for ($ch = 0; $ch < $channels; $ch++) {
            $this->delays[] = $v->new('float[' . (2 * $this->sections + 2) . ']'); // zeroed
        }
    }

    /**
     * The biquads of $gains at $rate, normalized (a0 1): [b0, b1, b2, a1,
     * a2] each, vDSP's order, of the bands that are any (FFmpeg's
     * equalizer: A = 10^(g/40), alpha = sin(w0) / (2 f / width)).
     * @param list<float> $gains
     * @return list<array{0: float, 1: float, 2: float, 3: float, 4: float}>
     */
    public static function coefficients(array $gains, int $rate): array
    {
        if (count($gains) !== count(self::FREQUENCIES)) {
            throw new RuntimeException('myphp: an equalizer has ' . count(self::FREQUENCIES) . ' bands, not ' . count($gains));
        }
        $out = [];
        foreach (self::FREQUENCIES as $i => $f) {
            $g = (float)$gains[$i];
            $w0 = 2 * M_PI * $f / $rate;
            if ($g == 0.0 || $w0 > M_PI) {
                continue;
            }
            $a = 10 ** ($g / 40);
            $alpha = sin($w0) / (2 * $f / ($f / self::Q));
            $a0 = 1 + $alpha / $a;
            $out[] = [(1 + $alpha * $a) / $a0, -2 * cos($w0) / $a0, (1 - $alpha * $a) / $a0, -2 * cos($w0) / $a0, (1 - $alpha / $a) / $a0];
        }
        return $out;
    }

    /** Whether $gains filter at all: a band of a gain. @param list<float> $gains */
    public static function flat(array $gains): bool
    {
        foreach ($gains as $g) { if ((float)$g != 0.0) { return false; } }
        return true;
    }

    /** $frames frames of interleaved float PCM $pcm, filtered. */
    public function process(string $pcm, int $frames): string
    {
        $v = self::v();
        $n = $frames * $this->channels;
        if ($n > $this->capacity) {
            $this->capacity = $n;
            $this->in = $v->new("float[$n]");
            $this->out = $v->new("float[$n]");
        }
        FFI::memcpy($this->in, $pcm, 4 * $n);
        for ($ch = 0; $ch < $this->channels; $ch++) { // each channel strided through the frames, its own delay line
            $v->vDSP_biquad($this->setup, $this->delays[$ch], FFI::addr($this->in[$ch]), $this->channels, FFI::addr($this->out[$ch]), $this->channels, $frames);
        }
        return FFI::string($this->out, 4 * $n);
    }

    /** Forgets what came before, for a seek. */
    public function reset(): void
    {
        foreach ($this->delays as $d) { FFI::memset($d, 0, FFI::sizeof($d)); }
    }

    private static function v(): FFI
    {
        return self::$v ??= FFI::cdef('
            typedef struct vDSP_biquad_SetupStruct *vDSP_biquad_Setup;
            vDSP_biquad_Setup vDSP_biquad_CreateSetup(const double *coefficients, unsigned long sections);
            void vDSP_biquad_DestroySetup(vDSP_biquad_Setup setup);
            void vDSP_biquad(vDSP_biquad_Setup setup, float *delay, const float *x, long ix, float *y, long iy, unsigned long n);
        ', '/System/Library/Frameworks/Accelerate.framework/Accelerate');
    }

    public function __destruct()
    {
        self::v()->vDSP_biquad_DestroySetup($this->setup);
    }
}
