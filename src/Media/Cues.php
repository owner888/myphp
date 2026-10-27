<?php
declare(strict_types=1);

namespace MyPHP\Media;

/**
 * The cues of a subtitle track, as they are read: each a start and an end
 * in seconds and what shows, a text, an ASS line in its styles, or the
 * pictures of an image track (none for one that clears what showed), the
 * same cue read again (after a seek) kept once; a cue of no end lasts
 * until the next one starts.
 */
final class Cues
{
    /** @var array<string, array{0: float, 1: float, 2: string|AssLine|list<SubtitlePicture>}> by start and text (one of pictures a start) */
    private array $cues = [];
    /** @var ?list<array{0: float, 1: float, 2: string|AssLine|list<SubtitlePicture>}> the cues by start, made as at() asks after an add */
    private ?array $list = [];

    /** Adds a cue of a text, an ASS line or pictures (none: it clears); INF for its end makes it last until the next. @param string|AssLine|list<SubtitlePicture> $shown */
    public function add(float $start, float $end, string|AssLine|array $shown): void
    {
        $key = sprintf('%.3f', $start) . "\0" . match (true) { is_string($shown) => $shown, $shown instanceof AssLine => "\2" . $shown->layer . "\0" . $shown->text(), default => "\1" }; // read again, the same cue
        if (!is_string($shown) && isset($this->cues[$key])) {
            return;
        }
        $this->cues[$key] = [$start, $end, $shown];
        $this->list = null;
    }

    public function count(): int
    {
        return count($this->cues);
    }

    /** What the cues showing at $t seconds show, texts, ASS lines or pictures, in the order they started; a cue that clears shows nothing. @return list<string|AssLine|list<SubtitlePicture>> */
    public function at(float $t): array
    {
        if ($this->list === null) {
            $this->list = array_values($this->cues);
            usort($this->list, fn(array $a, array $b): int => $a[0] <=> $b[0]);
        }
        $list = $this->list;
        $lo = 0; $hi = count($list) - 1; $at = -1; // the last cue starting at or before $t
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($list[$mid][0] <= $t) { $at = $mid; $lo = $mid + 1; } else { $hi = $mid - 1; }
        }
        $out = [];
        for ($i = $at; $i >= 0 && $i > $at - 16; $i--) { // the cues showing together: a few at most
            [, $end, $shown] = $list[$i];
            if ($shown !== [] && $t < (is_infinite($end) ? ($list[$i + 1][0] ?? INF) : $end)) {
                array_unshift($out, $shown);
            }
        }
        return $out;
    }

    /** When after $t the text showing changes next, a cue starting or one showing ending; null for no more. @internal the player wakes for it */
    public function next(float $t): ?float
    {
        $this->at($t); // sorts the list
        $list = $this->list;
        $lo = 0; $hi = count($list) - 1; $first = count($list); // the first cue starting after $t
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($list[$mid][0] > $t) { $first = $mid; $hi = $mid - 1; } else { $lo = $mid + 1; }
        }
        $next = $list[$first][0] ?? INF;
        for ($i = $first - 1; $i >= 0 && $i > $first - 17; $i--) { // the ends of those showing: a few at most, as at() finds them
            if (is_finite($list[$i][1]) && $list[$i][1] > $t) { $next = min($next, $list[$i][1]); }
        }
        return is_finite($next) ? $next : null;
    }
}
