<?php
declare(strict_types=1);

namespace MyPHP\Audio\Vorbis;

/**
 * Decodes Ogg Vorbis files (Vorbis I), in PHP: mygo plays them with
 * jfreymuth/oggvorbis (package audio/vorbis). Floors of type 1, which
 * every encoder since libvorbis 1.0 writes; type 0 throws. It seeks by the
 * pages' granule positions, decoding from a page before the frame asked
 * for; the encoder's priming at the start and padding at the end are cut
 * as the granule positions say.
 */
final class Decoder implements \MyPHP\Audio\Decoder
{
    private Ogg $ogg;
    private Codec $codec;
    private int $channels = 0, $rate = 0;
    /** @var list<float> decoded frames, interleaved, not yet read */
    private array $pending = [];
    private int $pendingAt = 0;
    /** The frame after the last decoded, once known; the frame to drop decoded frames before; whether decoding is done. */
    private int $pos = 0, $dropTo = 0;
    private bool $done = false;
    /** @var list<float> frames decoded since a start or a seek, held until a page's granule position says where they are */
    private array $held = [];
    private int $heldFrames = 0;
    /** Whether the frames decoded are placed; whether decoding started at the stream's start. */
    private bool $placed = false, $atStart = true;
    /** Where the first audio page starts, and the stream's length in frames, or -1. */
    private int $audioAt = 0, $length = -1;
    /** @var ?list<array{0: int, 1: int}> the pages' offsets and granule positions, read as the first seek needs them */
    private ?array $pages = null;

    /** @param resource $r a seekable stream at the start of the file */
    public function __construct(private $r)
    {
        $this->ogg = new Ogg($r, (int)ftell($r));
        $this->headers();
        $this->audioAt = $this->ogg->tell();
        $this->length = $this->ogg->lastGranule();
        $this->ogg->seekPage($this->audioAt);
        $this->restart(true, 0);
    }

    public function sampleRate(): int { return $this->rate; }
    public function channels(): int { return $this->channels; }
    public function len(): int { return $this->length; }

    private function headers(): void
    {
        $id = $this->ogg->packet() ?? throw new \RuntimeException('vorbis: no identification header');
        $comment = $this->ogg->packet() ?? throw new \RuntimeException('vorbis: no comment header');
        $setup = $this->ogg->packet() ?? throw new \RuntimeException('vorbis: no setup header');
        $this->codec = new Codec($id[0], $comment[0], $setup[0]);
        $this->channels = $this->codec->channels(); $this->rate = $this->codec->rate();
    }

    /** Starts decoding afresh, at the stream's start or after a seek, dropping the frames before $dropTo. */
    private function restart(bool $atStart, int $dropTo): void
    {
        $this->codec->reset();
        $this->pending = []; $this->pendingAt = 0; $this->held = []; $this->heldFrames = 0;
        $this->placed = false; $this->atStart = $atStart;
        $this->pos = 0; $this->dropTo = $dropTo; $this->done = false;
    }

    public function read(int $frames): array
    {
        $want = $frames * $this->channels;
        while (count($this->pending) - $this->pendingAt < $want && !$this->done) {
            $this->decodePacket();
        }
        $out = array_slice($this->pending, $this->pendingAt, $want);
        $this->pendingAt += count($out);
        if ($this->pendingAt > 65536) { $this->pending = array_slice($this->pending, $this->pendingAt); $this->pendingAt = 0; }
        return $out;
    }

    public function setPosition(int $frame): void
    {
        if ($frame < 0 || ($this->length >= 0 && $frame > $this->length)) { throw new \RuntimeException('vorbis: seek out of range'); }
        // After the last page whose granule position is a long block before the frame, unless the page after it ends the stream, whose padding would leave where its frames are unsure: its packets are decoded, the first only to overlap.
        $this->pages ??= $this->ogg->pages($this->audioAt);
        $next = null;
        for ($k = 0, $n = count($this->pages); $k + 2 < $n; $k++) {
            [, $g] = $this->pages[$k];
            if ($g > $frame - $this->codec->longBlock()) { break; }
            if ($g >= 0) { $next = $this->pages[$k + 1][0]; }
        }
        if ($next === null) { // near the start: from the start
            $this->ogg->seekPage($this->audioAt);
            $this->restart(true, $frame);
            return;
        }
        $this->ogg->seekPage($next);
        $this->restart(false, $frame);
    }

    /** Decodes the next audio packet into $pending, once where its frames are is known. */
    private function decodePacket(): void
    {
        $p = $this->ogg->packet();
        if ($p === null) {
            if (!$this->placed) { $this->place(0, null); } // no granule position came: from the start
            $this->done = true;
            return;
        }
        [$data, $granule, $eos] = $p;
        $out = $this->codec->decode($data);
        $n = intdiv(count($out), $this->channels);
        if (!$this->placed) {
            array_push($this->held, ...$out);
            $this->heldFrames += $n;
            if ($granule >= 0) {
                // At the start, a first page that ends the stream too has padding at its end; another has the encoder's priming at its start, which its position leaves out.
                $start = $this->atStart ? ($eos ? 0 : min(0, $granule - $this->heldFrames)) : $granule - $this->heldFrames;
                $this->place($start, $eos ? $granule : null);
            }
        } else {
            if ($eos && $granule >= 0 && $granule < $this->pos + $n) { // the end's padding
                $n = max(0, $granule - $this->pos);
                $out = array_slice($out, 0, $n * $this->channels);
            }
            $this->emit($out, $this->pos, $n);
        }
        if ($eos) { $this->done = true; }
    }

    /** Places the frames held, the first at $start, up to the end $end of the stream (null for none). */
    private function place(int $start, ?int $end): void
    {
        $n = $this->heldFrames; $out = $this->held;
        if ($end !== null && $end - $start < $n) {
            $n = max(0, $end - $start);
            $out = array_slice($out, 0, $n * $this->channels);
        }
        $this->held = []; $this->heldFrames = 0; $this->placed = true;
        $this->emit($out, $start, $n);
    }

    /** Adds $n frames to what read() gives, the first at $start, dropping those before the frame seeked to. */
    private function emit(array $out, int $start, int $n): void
    {
        if ($start < $this->dropTo) {
            $drop = min($n, $this->dropTo - $start);
            $out = array_slice($out, $drop * $this->channels);
            $n -= $drop; $start += $drop;
        }
        if ($out !== []) { array_push($this->pending, ...$out); }
        $this->pos = $start + $n;
    }

    public function close(): void {}
}
