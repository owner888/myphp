<?php
declare(strict_types=1);

namespace MyPHP\UI\Markdown;

use Closure;

/** How much of the source a view shows, and since when what it shows came in (mygo's revealState). Times are hrtime nanoseconds. */
final class RevealState
{
    /** A stream being revealed, or done with text left to reveal. */
    public bool $revealing = false;
    public string $src = '';
    public int $shown = 0;
    public float $carry = 0;
    public int $last = 0;
    /** @var list<array{0: int, 1: int}> that the source from an offset on showed at a time (mygo's revealMark) */
    public array $marks = [];
    public float $fade = 0;

    /** How much of $src shows this frame, at time $now, and whether more is to come in (mygo's step). @return array{0: int, 1: bool} */
    public function step(int $now, string $src, bool $streaming, ?Reveal $r): array
    {
        $r ??= new Reveal();
        // Text a stream left to reveal still comes in once it is done; other text a finished view is given shows at once, as does an edit.
        $edited = $this->shown > strlen($src) || !str_starts_with($src, substr($this->src, 0, $this->shown));
        $pending = $this->revealing && $this->shown < strlen($this->src) && !$edited;
        $this->src = $src;
        if ((!$streaming && !$pending) || $r->unit === Reveal::AT_ONCE || $edited) {
            $this->shown = strlen($src); $this->last = $now; $this->revealing = $streaming;
            return [$this->shown, false];
        }
        $this->revealing = true;
        $target = strlen($src);
        if ($streaming && $r->unit !== Reveal::BY_WORD && $r->unit !== Reveal::BY_CHARACTER) { // only complete sentences, lines or paragraphs, while more is coming
            $target = $this->shown;
            for ($i = $this->shown; ;) {
                $e = self::unitEnd($src, $i, strlen($src), $r->unit);
                if ($e < 0) { break; }
                $target = $i = $e;
            }
        }
        if ($this->shown >= $target) { // nothing to show until more text comes, which builds a frame
            $this->last = $now;
            return [$this->shown, false];
        }
        $dt = $now - $this->last;
        if ($this->last === 0 || $dt > 100_000_000) {
            $dt = 16_000_000;
        }
        $this->last = $now;
        $catchUp = $r->catchUp > 0 ? $r->catchUp : 0.25;
        $backlog = $target - $this->shown;
        // As many bytes a second as show the backlog within catchUp, and at least a few hundred, so that a trickle does not crawl.
        $rate = max($backlog / $catchUp, 240.0);
        $this->carry += $rate * $dt / 1e9;
        $n = (int)$this->carry;
        if ($n === 0) {
            return [$this->shown, true];
        }
        $this->carry -= $n;
        $next = self::snap($src, min($this->shown + $n, $target), $target, $r->unit);
        if ($next > $this->shown) {
            $this->marks[] = [$this->shown, $now];
            $this->shown = $next;
        }
        return [$this->shown, $this->shown < strlen($src)];
    }

    /** Offset $i of $src moved forward to the end of the unit it is in (mygo's snap). */
    public static function snap(string $src, int $i, int $target, int $u): int
    {
        switch ($u) {
            case Reveal::BY_WORD:
                while ($i < $target && $src[$i] !== ' ' && $src[$i] !== "\n") { $i++; }
                while ($i < $target && ($src[$i] === ' ' || $src[$i] === "\n")) { $i++; }
                break;
            case Reveal::BY_SENTENCE: case Reveal::BY_LINE: case Reveal::BY_PARAGRAPH:
                $e = self::unitEnd($src, $i, $target, $u);
                $i = $e >= 0 ? $e : $target;
                break;
        }
        while ($i < $target && (ord($src[$i]) & 0xC0) === 0x80) { $i++; }
        return $i;
    }

    /** Where the first sentence, line or paragraph of $src ending after $i and by $limit ends, or -1 (mygo's unitEnd). */
    public static function unitEnd(string $src, int $i, int $limit, int $u): int
    {
        switch ($u) {
            case Reveal::BY_LINE:
                $k = strpos(substr($src, $i, $limit - $i), "\n");
                return $k === false ? -1 : $i + $k + 1;
            case Reveal::BY_PARAGRAPH:
                $k = strpos(substr($src, $i, $limit - $i), "\n\n");
                return $k === false ? -1 : $i + $k + 2;
            case Reveal::BY_SENTENCE:
                for ($j = $i; $j < $limit;) {
                    $b = ord($src[$j]);
                    $r = substr($src, $j, $b < 0x80 ? 1 : ($b < 0xE0 ? 2 : ($b < 0xF0 ? 3 : 4)));
                    $j += strlen($r);
                    if ($j > $limit) { break; }
                    switch ($r) {
                        case "\n":
                            return $j;
                        case '.': case '!': case '?': case '…':
                            while ($j < $limit && str_contains("\"')]*_", $src[$j])) { $j++; } // closing quotes and brackets go with it, then a space
                            if ($j < $limit && ($src[$j] === ' ' || $src[$j] === "\n")) {
                                return $j + 1;
                            }
                            break;
                        case '。': case '！': case '？':
                            return $j;
                    }
                }
        }
        return -1;
    }

    /** The opacity of text at a source offset as it fades in at time $now, or null when nothing fades (mygo's fadeAt). @return ?Closure(int): float */
    public function fadeAt(int $now, bool $reduceMotion, ?Reveal $r): ?Closure
    {
        $r ??= new Reveal();
        $fade = $r->fade == 0 ? 0.3 : $r->fade;
        $k = 0; // marks done fading are gone
        while ($k < count($this->marks) && ($fade < 0 || ($now - $this->marks[$k][1]) / 1e9 >= $fade)) { $k++; }
        $this->marks = array_slice($this->marks, $k);
        if ($this->marks === [] || $fade < 0 || $reduceMotion) {
            $this->marks = [];
            return null;
        }
        $this->fade = $fade;
        $marks = $this->marks;
        return static function (int $src) use ($marks, $now, $fade): float {
            if ($src < $marks[0][0]) {
                return 1.0;
            }
            $lo = 0; $hi = count($marks); // the last mark at or before src
            while ($lo < $hi) {
                $m = intdiv($lo + $hi, 2);
                if ($marks[$m][0] <= $src) { $lo = $m + 1; } else { $hi = $m; }
            }
            $a = ($now - $marks[$lo - 1][1]) / 1e9 / $fade;
            return min(1.0, (int)(self::easeOut($a) * 16) / 16); // quantized, so that the spans of text alike stay one
        };
    }

    public static function easeOut(float $t): float
    {
        $t = min(max($t, 0.0), 1.0);
        $u = 1 - $t;
        return 1 - $u * $u * $u;
    }

    /** Whether text still fades in. */
    public function fading(): bool { return $this->marks !== []; }
}
