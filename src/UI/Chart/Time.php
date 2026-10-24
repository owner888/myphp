<?php
declare(strict_types=1);

namespace MyPHP\UI\Chart;

use Closure;
use DateTimeImmutable;

/**
 * Maps times from $from to $to onto the plot (mygo's chart.Time), as d3's
 * scaleTime and Swift Charts' scales of dates. Its ticks fall on round
 * times of the times' time zone, a second, a quarter of an hour, a day,
 * the first of a month, chosen for the span: midnights for a week, months
 * for a year.
 *
 *     $x = new Time($start, $end);
 *     $ticks = $x->ticks(6);
 */
final class Time
{
    private const MILLI = 0, SECOND = 1, MINUTE = 2, HOUR = 3, DAY = 4, WEEK = 5, MONTH = 6, YEAR = 7;
    private const D = 86400;
    /** d3-time's tick intervals, by their length: [unit, every, seconds]. */
    private const INTERVALS = [
        [self::SECOND, 1, 1], [self::SECOND, 5, 5], [self::SECOND, 15, 15], [self::SECOND, 30, 30],
        [self::MINUTE, 1, 60], [self::MINUTE, 5, 300], [self::MINUTE, 15, 900], [self::MINUTE, 30, 1800],
        [self::HOUR, 1, 3600], [self::HOUR, 3, 10800], [self::HOUR, 6, 21600], [self::HOUR, 12, 43200],
        [self::DAY, 1, self::D], [self::DAY, 2, 2 * self::D], [self::WEEK, 1, 7 * self::D],
        [self::MONTH, 1, 30 * self::D], [self::MONTH, 3, 90 * self::D], [self::YEAR, 1, 365 * self::D],
    ];

    /** @param ?Closure(DateTimeImmutable): string $format labels the ticks; null labels each with what changed since a round time of the coarser unit, as d3's multi-scale format: "2026", "Mar", "Mar 5", "14:00", "14:05:30" */
    public function __construct(public DateTimeImmutable $from, public DateTimeImmutable $to, public ?Closure $format = null) {}

    /** Seconds since the epoch, to the microsecond. */
    private static function seconds(DateTimeImmutable $t): float
    {
        return (float)$t->format('U.u');
    }

    /** Where $t is along the plot. */
    public function at(DateTimeImmutable $t): float
    {
        $span = self::seconds($this->to) - self::seconds($this->from);
        return $span == 0 ? 0.5 : (self::seconds($t) - self::seconds($this->from)) / $span;
    }

    public function span(DateTimeImmutable $a, DateTimeImmutable $b): Span
    {
        return new Span($this->at($a), $this->at($b));
    }

    /** The time at $f along the plot. */
    public function invert(float $f): DateTimeImmutable
    {
        $s = self::seconds($this->from) + $f * (self::seconds($this->to) - self::seconds($this->from));
        return DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $s))->setTimezone($this->from->getTimezone());
    }

    /** The interval of about $count ticks from $lo to $hi, as d3-time's tickInterval: [unit, every]. @return array{0: int, 1: int} */
    private static function interval(DateTimeImmutable $lo, DateTimeImmutable $hi, int $count): array
    {
        $span = self::seconds($hi) - self::seconds($lo);
        $target = $span / max(1, $count);
        $i = 0;
        while ($i < count(self::INTERVALS) && self::INTERVALS[$i][2] <= $target) {
            $i++;
        }
        if ($i === count(self::INTERVALS)) {
            $n = Ticks::step((float)$lo->format('Y'), (float)$hi->format('Y') + ((int)$hi->format('z') + 1) / 366, $count);
            return [self::YEAR, max(1, (int)round($n))];
        }
        if ($i === 0) {
            $n = Ticks::step(0, floor($span * 1000), $count);
            return [self::MILLI, max(1, (int)round($n))];
        }
        [$u, $n] = $target / self::INTERVALS[$i - 1][2] < self::INTERVALS[$i][2] / $target ? self::INTERVALS[$i - 1] : self::INTERVALS[$i];
        return [$u, $n];
    }

    /** The latest round time of the interval at or before $t, in $t's time zone: the field of the unit a multiple of $n, as d3-time's every. */
    private static function floor(DateTimeImmutable $t, int $u, int $n): DateTimeImmutable
    {
        [$y, $mo, $d, $h, $mi, $s, $us, $w] = array_map('intval', explode(' ', $t->format('Y n j G i s u w')));
        return match ($u) {
            self::MILLI => $t->setTime($h, $mi, $s, (intdiv($us, 1000) - intdiv($us, 1000) % $n) * 1000),
            self::SECOND => $t->setTime($h, $mi, $s - $s % $n),
            self::MINUTE => $t->setTime($h, $mi - $mi % $n, 0),
            self::HOUR => $t->setTime($h - $h % $n, 0, 0),
            self::DAY => $t->setDate($y, $mo, $d - ($d - 1) % $n)->setTime(0, 0),
            self::WEEK => $t->setDate($y, $mo, $d - $w)->setTime(0, 0), // weeks start on Sunday, as d3's timeWeek
            self::MONTH => $t->setDate($y, $mo - ($mo - 1) % $n, 1)->setTime(0, 0),
            default => $t->setDate($y - $y % $n, 1, 1)->setTime(0, 0),
        };
    }

    /** The round time of the interval after $t, a round time. */
    private static function next(DateTimeImmutable $t, int $u, int $n): DateTimeImmutable
    {
        [$y, $mo, $d, $h] = array_map('intval', explode(' ', $t->format('Y n j G')));
        switch ($u) {
            case self::MILLI: return $t->modify('+' . ($n * 1000) . ' microseconds');
            case self::SECOND: return $t->modify("+$n seconds");
            case self::MINUTE: return $t->modify('+' . ($n * 60) . ' seconds');
            case self::HOUR: return $t->setTime($h + $n, 0, 0);
            case self::DAY:
                $next = $t->setDate($y, $mo, $d + $n)->setTime(0, 0);
                return (int)$next->format('n') !== $mo ? $next->setDate((int)$next->format('Y'), (int)$next->format('n'), 1) : $next; // days of a month count from its first
            case self::WEEK: return $t->setDate($y, $mo, $d + 7 * $n)->setTime(0, 0);
            case self::MONTH: return $t->setDate($y, $mo + $n, 1)->setTime(0, 0);
            default: return $t->setDate($y + $n, 1, 1)->setTime(0, 0);
        }
    }

    /** About $count round times within the domain; their values are milliseconds since the epoch. @return list<Tick> */
    public function ticks(int $count): array
    {
        [$lo, $hi] = $this->to < $this->from ? [$this->to, $this->from] : [$this->from, $this->to];
        if ($count <= 0 || !($lo < $hi)) {
            return [];
        }
        [$u, $n] = self::interval($lo, $hi, $count);
        $format = $this->format ?? self::label(...);
        $out = [];
        for ($t = self::floor($lo, $u, $n); $t <= $hi && count($out) < 1000; $t = self::next($t, $u, $n)) {
            if ($t >= $lo) {
                $out[] = new Tick($this->at($t), round(self::seconds($t) * 1000), $format($t));
            }
        }
        return $this->to < $this->from ? array_reverse($out) : $out;
    }

    /** A tick's label: the finest unit it is not round in, as d3's multi-scale time format. */
    public static function label(DateTimeImmutable $t): string
    {
        [$mo, $d, $h, $mi, $s, $us] = array_map('intval', explode(' ', $t->format('n j G i s u')));
        return match (true) {
            $us !== 0 => $t->format('.v'),
            $s !== 0 => $t->format(':s'),
            $mi !== 0 => $t->format('H:i'),
            $h !== 0 => $t->format('H:00'),
            $d !== 1 => $t->format('M j'),
            $mo !== 1 => $t->format('M'),
            default => $t->format('Y'),
        };
    }
}
