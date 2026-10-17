<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * A sequence of Keyframes playing for a node and a key (mygo's
 * sequence): from $start, in nanoseconds, the frames at their times in
 * nanoseconds.
 */
final class KeyframeSequence
{
    /** @var list<array{0: int, 1: float, 2: ?\Closure}> the frames: at, value, ease */
    public array $frames = [];
    public float $value;
    public bool $done = false;
    public int $rested = 0;

    /**
     * @param list<Keyframe> $frames
     * $from is the first value the first time through, which interrupting
     * a sequence starts from.
     */
    public function __construct(array $frames, public KeyframeOptions $options, public int $start, public float $from)
    {
        foreach ($frames as $f) {
            $this->frames[] = [Motion::ns($f->at), $f->value, $f->ease];
        }
        $this->value = $from;
    }

    /** How many times the sequence plays, 0 for ever. */
    public function plays(): int
    {
        return $this->options->repeat === KeyframeOptions::REPEAT_FOREVER ? 0 : $this->options->repeat + 1;
    }

    public function final(): float
    {
        if ($this->options->alternate && $this->plays() % 2 === 0) {
            return $this->frames[0][1];
        }
        return $this->frames[count($this->frames) - 1][1];
    }

    /** The value at $now and whether the sequence is done. @return array{0: float, 1: bool} */
    public function sample(int $now): array
    {
        if ($this->done) {
            return [$this->value, true];
        }
        $elapsed = $now - $this->start;
        if ($elapsed < 0) {
            return [$this->from, false]; // delayed
        }
        $d = $this->frames[count($this->frames) - 1][0];
        [$at, $iteration, $done] = Motion::playhead($elapsed, $d, $this->options->repeat, $this->options->alternate, Motion::ns($this->options->repeatDelay));
        if ($done) {
            return [$this->final(), true];
        }
        for ($i = 1, $n = count($this->frames); $i < $n; $i++) {
            [$bAt, $bValue, $ease] = $this->frames[$i];
            if ($at > $bAt) {
                continue;
            }
            [$aAt, $aValue] = $this->frames[$i - 1];
            if ($i === 1 && $iteration === 0) {
                $aValue = $this->from;
            }
            $t = ($at - $aAt) / ($bAt - $aAt);
            $e = $ease === null ? $t : $ease($t);
            return [$aValue + ($bValue - $aValue) * $e, false];
        }
        return [$this->final(), false];
    }
}
