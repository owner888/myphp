<?php
declare(strict_types=1);

namespace MyPHP\Audio;

use MyPHP\Logger;

/**
 * Decodes ahead of a voice, about a second, into a ring the voice reads
 * without waiting (mygo's stream). mygo decodes on a goroutine of its own;
 * here the output decodes a little of every stream each time it mixes
 * (fill), on the main thread, and a stream fills itself as it is created,
 * moved and started.
 * @internal
 */
final class Stream implements Source
{
    /** @var array<int, Stream> the streams open, by object id: what the output fills */
    private static array $live = [];
    /** Frames decoded at a time. */
    private const CHUNK = 2048;

    private int $channels, $keep;
    /** The frames decoded and not yet read, as 32-bit floats of $keep channels, from $r; the ring's room in frames. */
    private string $ring = '';
    private int $r = 0;
    private int $capacity;
    private bool $loop = false;
    /** The frames looped, $end 0 for all. */
    private int $start = 0, $end = 0;
    /** The frame the decoder decodes next; whether it decoded to the loop's end, and goes on from its start; whether it reached its end; where to move it next, or -1. */
    private int $pos = 0;
    private bool $wrap = false, $atEOF = false;
    private int $seekTo = -1;
    private bool $closed = false;

    public function __construct(private readonly Decoder $d)
    {
        $this->channels = max($d->channels(), 1);
        $this->keep = min($this->channels, 2);
        $this->capacity = max($d->sampleRate(), 4096); // about a second ahead
        self::$live[spl_object_id($this)] = $this;
        $this->fill(self::CHUNK * 4);
    }

    /** Decodes a little of every stream open: what the output does each time it mixes. */
    public static function fillAll(int $frames = 4096): void
    {
        foreach (self::$live as $s) { $s->fill($frames); }
    }

    /** The frames decoded ahead. */
    public function buffered(): int
    {
        return intdiv(strlen($this->ring) - $this->r, 4 * $this->keep);
    }

    /** Decodes up to $budget frames ahead, as far as the ring has room; what mygo's decoding goroutine does between its waits. */
    public function fill(int $budget): void
    {
        while (!$this->closed && $budget > 0) {
            if ($this->seekTo < 0 && !$this->wrap && (($this->atEOF && !$this->loop) || $this->capacity - $this->buffered() < self::CHUNK)) {
                return; // nothing to do until read, moved or looped
            }
            if ($this->seekTo >= 0 || $this->wrap || $this->atEOF) { // a seek, or a loop back to its start
                $to = $this->seekTo >= 0 ? $this->seekTo : $this->start;
                $this->seekTo = -1; $this->wrap = false;
                try {
                    $this->d->setPosition($to);
                    $this->atEOF = false;
                } catch (\Throwable) {
                    $this->atEOF = true; $this->loop = false; // where it cannot go, the stream ends
                }
                $this->pos = $to;
                continue;
            }
            try {
                $samples = $this->d->read(self::CHUNK);
            } catch (\Throwable $e) {
                Logger::error($e->getMessage(), 'audio');
                $this->atEOF = true; $this->loop = false; // a decoder that fails loops no more
                return;
            }
            $n = count($samples); $n -= $n % $this->channels;
            if ($n === 0) {
                $this->atEOF = true;
                continue;
            }
            if ($this->keep !== $this->channels || $n !== count($samples)) { // channels beyond two are not played
                $kept = [];
                for ($i = 0; $i < $n; $i += $this->channels) {
                    for ($c = 0; $c < $this->keep; $c++) { $kept[] = $samples[$i + $c]; }
                }
                $samples = $kept;
            }
            $frames = intdiv($n, $this->channels);
            if ($this->loop && $this->end > 0 && $this->pos + $frames >= $this->end) { // past the loop's end: what is before it, then its start
                $samples = array_slice($samples, 0, max($this->end - $this->pos, 0) * $this->keep);
                $this->wrap = true;
            }
            $this->pos += $frames;
            if ($this->r > 0 && $this->r >= strlen($this->ring) / 2) { $this->ring = substr($this->ring, $this->r); $this->r = 0; }
            $this->ring .= pack('g*', ...$samples);
            $budget -= $frames;
        }
    }

    public function read(int $frames): array
    {
        $avail = $this->buffered();
        $got = min($frames, $avail);
        $out = $got > 0 ? array_values(unpack('g' . ($got * $this->keep), $this->ring, $this->r)) : [];
        $this->r += $got * $this->keep * 4;
        $ended = $got < $frames && $this->buffered() === 0 && $this->atEOF && !$this->loop && $this->seekTo < 0;
        return [$out, $ended];
    }

    public function seek(int $frame): void
    {
        $this->seekTo = $frame;
        $this->atEOF = $this->wrap = false;
        $this->ring = ''; $this->r = 0;
        $this->fill(self::CHUNK * 2); // what plays next, at once
    }

    public function setLoop(bool $loop, int $start, int $end): void
    {
        if ($this->wrap && !$loop) { // looping no more at its end: from there, where it decoded past
            $this->wrap = false; $this->seekTo = $this->end;
        }
        $this->loop = $loop; $this->start = $start; $this->end = $end;
    }

    public function close(): void
    {
        if ($this->closed) { return; }
        $this->closed = true;
        unset(self::$live[spl_object_id($this)]);
        $this->ring = '';
        if (method_exists($this->d, 'close')) { $this->d->close(); }
    }
}
