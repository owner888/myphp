<?php
declare(strict_types=1);

namespace MyPHP\Audio;

/**
 * Reads a sound's samples, kept as 32-bit floats in a string (mygo's
 * memSource).
 * @internal
 */
final class MemSource implements Source
{
    /** The sample read next, and the frames looped, $end 0 for all. */
    private int $pos = 0;
    private bool $loop = false;
    private int $start = 0, $end = 0;
    private int $count;

    public function __construct(private readonly string $samples, private readonly int $channels)
    {
        $this->count = intdiv(strlen($samples), 4);
    }

    public function read(int $frames): array
    {
        $want = $frames * $this->channels;
        $out = [];
        while (count($out) < $want) {
            $limit = $this->count;
            if ($this->loop && $this->end > 0) { $limit = min($limit, $this->end * $this->channels); }
            if ($this->pos >= $limit) {
                if (!$this->loop || $limit === 0) { return [$out, true]; }
                $this->pos = $this->start * $this->channels;
                if ($this->pos >= $limit) { $this->pos = 0; }
            }
            $c = min($want - count($out), $limit - $this->pos);
            $c -= $c % $this->channels;
            if ($c <= 0) { break; }
            array_push($out, ...unpack('g' . $c, $this->samples, $this->pos * 4));
            $this->pos += $c;
        }
        return [$out, false];
    }

    public function seek(int $frame): void
    {
        $this->pos = min($frame * $this->channels, $this->count);
    }

    public function setLoop(bool $loop, int $start, int $end): void
    {
        $this->loop = $loop; $this->start = $start; $this->end = $end;
    }

    public function close(): void {}
}
