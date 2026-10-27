<?php
declare(strict_types=1);

namespace MyPHP\Media;

use Closure;

/**
 * A dialogue line of an ASS script as it shows: its runs in their styles,
 * where it is (an alignment on the numpad, 1 to 9, within the margins, or
 * at \pos), its layer, and the script's PlayRes, the coordinates of all of
 * it, which lie over the film's picture. A line that moves, fades, is
 * animated (\t) or sung (\k) is as it shows at its start; at() gives it
 * at a time.
 */
final class AssLine
{
    /** @var ?Closure(float): self what it is a time into it, for one that changes as it shows */
    private ?Closure $changes = null;

    /**
     * @param list<AssRun> $runs
     * @param ?array{0: float, 1: float} $position
     * @param ?array{0: float, 1: float, 2: float, 3: float} $clip
     * @param ?array{0: float, 1: float} $origin
     */
    public function __construct(
        public readonly array $runs,
        /** As a numpad: 1, 2, 3 at the bottom, 4, 5, 6 in the middle, 7, 8, 9 at the top. */
        public readonly int $alignment,
        /** \pos (or where \move has it): where the point of the alignment is, [x, y]; null for the margins'. */
        public readonly ?array $position,
        public readonly int $marginLeft,
        public readonly int $marginRight,
        public readonly int $marginVertical,
        public readonly int $layer,
        public readonly int $playResX,
        public readonly int $playResY,
        /** Whether the outlines and shadows scale with the picture (ScaledBorderAndShadow), else they are of the picture's pixels. */
        public readonly bool $scaledBorder,
        /** WrapStyle 2 breaks no line but at \N; the others wrap at the margins. */
        public readonly bool $wraps,
        /** When it starts, in seconds of the film, and how long it shows. */
        public readonly float $start = 0.0,
        public readonly float $duration = INF,
        /** Its opacity as \fad and \fade have it, 0 to 1. */
        public readonly float $alpha = 1.0,
        /** \clip: the rectangle of it that shows, [x1, y1, x2, y2]; null for all. */
        public readonly ?array $clip = null,
        /** \org: the point it turns around; null for that of its alignment. */
        public readonly ?array $origin = null,
    ) {}

    /** Its text as plain lines. */
    public function text(): string
    {
        return trim(implode('', array_map(fn(AssRun $r) => $r->text, $this->runs)));
    }

    /** Whether it changes as it shows: at() is not itself at every time. */
    public function changes(): bool
    {
        return $this->changes !== null;
    }

    /** The line as it shows $t seconds after its start; it still changes(). */
    public function at(float $t): self
    {
        return $this->changes === null ? $this : ($this->changes)($t)->animatedBy($this->changes);
    }

    /** It, changing as $make makes it at a time. @internal Ass @param Closure(float): self $make */
    public function animatedBy(Closure $make): self
    {
        $l = clone $this;
        $l->changes = $make;
        return $l;
    }

    /** Whether it is laid out on its own, not stacked with the lines of its alignment: placed (\pos, \move), turned, scaled or clipped. */
    public function alone(): bool
    {
        $r = $this->runs[0] ?? null;
        return $this->position !== null || $this->clip !== null || ($r !== null && ($r->rotation != 0 || $r->scaleX != 1 || $r->scaleY != 1));
    }
}
