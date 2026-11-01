<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;

/**
 * A cascade of biquads ([b0, b1, b2, a1, a2] each, a0 1) over each
 * channel of interleaved float PCM, by Accelerate's vDSP_biquad, each
 * channel's state kept from one buffer to the next.
 *
 * @internal Native\Equalizer, Native\AudioChain
 */
final class Biquads
{
    private static ?FFI $v = null;
    private CData $setup, $in, $out;
    /** @var list<CData> each channel's delay line, 2 a section and 2 */
    private array $delays = [];
    private int $capacity = 0;

    /** @param list<array{0: float, 1: float, 2: float, 3: float, 4: float}> $sections */
    public function __construct(array $sections, private readonly int $channels)
    {
        $v = self::v();
        $n = count($sections);
        $c = $v->new('double[' . (5 * $n) . ']');
        foreach (array_merge(...$sections) as $i => $x) { $c[$i] = $x; }
        $this->setup = $v->vDSP_biquad_CreateSetup($c, $n);
        for ($ch = 0; $ch < $channels; $ch++) {
            $this->delays[] = $v->new('float[' . (2 * $n + 2) . ']'); // zeroed
        }
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
