<?php
declare(strict_types=1);

namespace MyPHP\Native;

use MyPHP\Media\AudioFilters;

/**
 * The audio filters' stages (Media\AudioFilters) made for a sound of
 * $rate a second and $channels channels, run over interleaved float PCM
 * in order: a gain by vDSP_vsmul, the biquads one after another by
 * Biquads (a stage past half the rate none, as FFmpeg's), a pan each
 * output channel's sum of the input's; their state kept from one buffer
 * to the next.
 *
 * @internal Native\Renderers
 */
final class AudioChain
{
    private static ?\FFI $vdsp = null;
    /** @var list<array{0: string, 1: mixed}> */
    private array $run = [];
    /** The channels it gives. */
    public readonly int $channels;

    public function __construct(AudioFilters $filters, int $rate, int $channels)
    {
        $ch = $channels;
        foreach ($filters->stages as $s) {
            if ($s[0] === 'gain') {
                $this->run[] = ['gain', $s[1]];
            } elseif ($s[0] === 'biquad') {
                $c = AudioFilters::coefficients($s, $rate);
                if ($c !== null) { $this->run[] = ['biquad', new Biquads([$c], $ch)]; }
            } else {
                $this->run[] = ['pan', [$ch, $s[1], $s[2]]];
                $ch = $s[1];
            }
        }
        $this->channels = $ch;
    }

    /** $frames frames of $pcm, through the stages. */
    public function process(string $pcm, int $frames): string
    {
        foreach ($this->run as [$kind, $x]) {
            $pcm = match ($kind) {
                'gain' => self::amplify($pcm, $x),
                'biquad' => $x->process($pcm, $frames),
                'pan' => self::pan($pcm, $frames, ...$x),
            };
        }
        return $pcm;
    }

    /** Forgets what came before, for a seek. */
    public function reset(): void
    {
        foreach ($this->run as [$kind, $x]) {
            if ($kind === 'biquad') { $x->reset(); }
        }
    }

    /** Float PCM $pcm times $k (vDSP_vsmul). @internal Renderers */
    public static function amplify(string $pcm, float $k): string
    {
        $v = self::$vdsp ??= \FFI::cdef('void vDSP_vsmul(const float*, long, const float*, float*, long, unsigned long);', '/System/Library/Frameworks/Accelerate.framework/Accelerate');
        $n = intdiv(strlen($pcm), 4);
        $buf = $v->new("float[$n]");
        \FFI::memcpy($buf, $pcm, $n * 4);
        $scale = $v->new('float');
        $scale->cdata = $k;
        $v->vDSP_vsmul($buf, 1, \FFI::addr($scale), $buf, 1, $n);
        return \FFI::string($buf, $n * 4);
    }

    /** $frames frames of $in channels made $out, each output channel's sum of the input's by $rows. @param list<list<float>> $rows */
    private static function pan(string $pcm, int $frames, int $in, int $out, array $rows): string
    {
        $x = unpack('g*', $pcm) ?: [];
        $y = [];
        for ($f = 0; $f < $frames; $f++) {
            $at = $f * $in + 1;
            for ($o = 0; $o < $out; $o++) {
                $sum = 0.0;
                foreach ($rows[$o] as $c => $k) { if ($k != 0.0 && $c < $in) { $sum += $k * $x[$at + $c]; } } // a channel the sound has not: none
                $y[] = $sum;
            }
        }
        return pack('g*', ...$y);
    }
}
