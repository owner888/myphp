<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;

/**
 * libswresample's conversion of decoded audio frames to what the Linux
 * player's output plays (Platform\Linux\Renderers): interleaved 32-bit
 * floats of $channels channels at $rate (mixed down or up as
 * libswresample's matrix does), its context made again only as the
 * frames' format, rate or channels change.
 *
 * @internal
 */
final class AvResampler
{
    private ?CData $ctx = null;
    private CData $out;
    /** The output's buffer, as large as the most a frame gave, and its plane as libswresample takes it. */
    private ?CData $buffer = null;
    private CData $plane;
    private int $capacity = 0;
    /** The frames' format, rate and channels it was made for. */
    private array $made = [];

    public function __construct(public readonly int $rate, public readonly int $channels)
    {
        $f = Av::ffi();
        $this->out = $f->new('AVChannelLayout');
        $f->av_channel_layout_default(FFI::addr($this->out), $channels);
        $this->plane = $f->new('uint8_t*[1]');
    }

    /** $frame (an audio AVFrame*) converted: its PCM bytes, and how many frames (of $channels samples) they are. @return array{0: string, 1: int} */
    public function convert(CData $frame): array
    {
        $f = Av::ffi();
        $made = [$frame->format, $frame->sample_rate, $frame->ch_layout->nb_channels, $frame->ch_layout->order, $frame->ch_layout->mask];
        if ($made !== $this->made) {
            $this->free();
            $ctx = $f->new('void*');
            Av::check($f->swr_alloc_set_opts2(FFI::addr($ctx), FFI::addr($this->out), Av::FLT, $this->rate, FFI::addr($frame->ch_layout), $frame->format, $frame->sample_rate, 0, null), 'a resampler');
            $this->ctx = $ctx;
            Av::check($f->swr_init($this->ctx), 'starting the resampler');
            $this->made = $made;
        }
        $n = $f->swr_get_out_samples($this->ctx, $frame->nb_samples);
        if ($n > $this->capacity) {
            $this->capacity = $n;
            $this->buffer = $f->new('float[' . ($n * $this->channels) . ']');
            $this->plane[0] = $f->cast('uint8_t*', FFI::addr($this->buffer[0]));
        }
        if ($n <= 0) {
            return ['', 0];
        }
        $got = Av::check($f->swr_convert($this->ctx, FFI::addr($this->plane[0]), $n, $frame->extended_data, $frame->nb_samples), 'resampling');
        return [FFI::string($this->plane[0], $got * $this->channels * 4), $got];
    }

    /** Forgets what it holds of the frames before, for a seek. */
    public function reset(): void
    {
        $this->free();
        $this->made = [];
    }

    private function free(): void
    {
        if ($this->ctx !== null) {
            Av::ffi()->swr_free(FFI::addr($this->ctx));
            $this->ctx = null;
        }
    }

    public function __destruct()
    {
        $this->free();
        Av::ffi()->av_channel_layout_uninit(FFI::addr($this->out));
    }
}
