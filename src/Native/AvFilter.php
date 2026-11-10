<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;
use RuntimeException;

/**
 * A libavfilter graph the Linux and Windows player runs frames through
 * (AvRenderers): $graph, FFmpeg's filtergraph syntax, closed, from a source
 * named "in" (buffer@in, abuffer@in) to a sink named "out" (buffersink@out,
 * abuffersink@out). Frames pushed in come out of pull() as the filters
 * give them (a deinterlacer a frame later, atempo by its windows); null
 * pushed is the end, after which the rest comes out.
 *
 * @internal
 */
final class AvFilter
{
    private ?CData $graph;
    private CData $in, $out;

    public function __construct(public readonly string $text)
    {
        $f = Av::ffi();
        $g = $f->avfilter_graph_alloc() ?? throw new RuntimeException('myphp: libavfilter made no graph');
        $this->graph = $g;
        try {
            Av::check($f->avfilter_graph_parse_ptr($g, $text, null, null, null), "the filters \"$text\"");
            Av::check($f->avfilter_graph_config($g, null), "the filters \"$text\"");
            [$kind] = explode('=', $text, 2);
            $this->in = $f->avfilter_graph_get_filter($g, "$kind") ?? throw new RuntimeException("myphp: no source named $kind in \"$text\"");
            $sink = str_starts_with($kind, 'a') ? 'abuffersink@out' : 'buffersink@out';
            $this->out = $f->avfilter_graph_get_filter($g, $sink) ?? throw new RuntimeException("myphp: no sink named $sink in \"$text\"");
        } catch (RuntimeException $e) {
            $this->free();
            throw $e;
        }
    }

    /** Gives the graph $frame (an AVFrame*, left blank: its data the graph's now), null for the end. */
    public function push(?CData $frame): void
    {
        Av::check(Av::ffi()->av_buffersrc_add_frame_flags($this->in, $frame, 0), "filtering through \"{$this->text}\"");
    }

    /** The next frame out, an AVFrame* of the caller's (AvDecoder::release); null while none is ready, or at the end. */
    public function pull(): ?CData
    {
        $f = Av::ffi();
        $frame = $f->av_frame_alloc();
        $st = $f->av_buffersink_get_frame($this->out, $frame);
        if ($st >= 0) {
            return $frame;
        }
        AvDecoder::release($frame);
        if ($st === Av::EAGAIN || $st === Av::EOF) {
            return null;
        }
        throw new RuntimeException("myphp: filtering through \"{$this->text}\": " . Av::error($st));
    }

    /** The seconds a tick of the frames' times out is (the sink's time base). */
    public function timeBase(): float
    {
        $tb = Av::ffi()->av_buffersink_get_time_base($this->out);
        return $tb->num / max(1, $tb->den);
    }

    private function free(): void
    {
        if ($this->graph !== null) {
            Av::ffi()->avfilter_graph_free(FFI::addr($this->graph));
            $this->graph = null;
        }
    }

    public function __destruct()
    {
        $this->free();
    }
}
