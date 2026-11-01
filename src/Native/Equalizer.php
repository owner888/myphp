<?php
declare(strict_types=1);

namespace MyPHP\Native;

use RuntimeException;

/**
 * IINA's ten-band equalizer, as its filter for mpv is (FFmpeg's
 * `equalizer=f=…:t=h:width=f/1.224744871:g=…` a band, lavfi's peaking
 * biquads, af_biquads.c): each band's biquad of its gain, the bands in a
 * cascade (Biquads, Accelerate's vDSP_biquad over each channel of
 * interleaved float PCM, its state kept from one buffer to the next). A band of no
 * gain is none (its biquad is the identity), one past half the rate none
 * (FFmpeg bypasses it).
 *
 * @internal Native\Renderers
 */
final class Equalizer
{
    /** IINA's bands' frequencies, and the width a band takes of its frequency (its Q). */
    public const FREQUENCIES = [32, 64, 125, 250, 500, 1000, 2000, 4000, 8000, 16000], Q = 1.224744871;

    private Biquads $biquads;

    /** @param list<float> $gains the bands' gains, dB */
    public function __construct(array $gains, int $rate, int $channels)
    {
        $coeffs = self::coefficients($gains, $rate);
        if ($coeffs === []) {
            throw new RuntimeException('myphp: an equalizer of no band of a gain');
        }
        $this->biquads = new Biquads($coeffs, $channels);
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
        return $this->biquads->process($pcm, $frames);
    }

    /** Forgets what came before, for a seek. */
    public function reset(): void
    {
        $this->biquads->reset();
    }
}
