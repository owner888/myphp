<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/** The list of completions an editor shows (mygo's completions). */
final class Completions
{
    public bool $shown = false;
    /** Where the word being completed starts. */
    public int $at = 0;
    /** @var list<Completion> */
    public array $items = [];
    /** @var list<int> the items shown, best first */
    public array $filtered = [];
    /** @var list<int> */
    public array $scores = [];
    public int $selected = 0;
    public string $prefix = '';
    public int $version = 0;
    /** A request is out. */
    public bool $request = false;
    public bool $scrollTo = false;

    public function open(): bool { return $this->shown && $this->filtered !== []; }

    public function close(): void
    {
        $this->shown = false;
        $this->items = []; $this->filtered = [];
        $this->request = false;
    }

    public function step(int $d): void
    {
        if (($n = count($this->filtered)) > 0) {
            $this->selected = ($this->selected + $d + $n) % $n;
            $this->scrollTo = true;
        }
    }

    /** @param list<Change> $cs */
    public function moved(array $cs): void
    {
        $this->at = Change::mapOffset($this->at, $cs, Change::LEFT);
    }

    /**
     * Keeps the items matching $prefix, best first, as Zed's fuzzy
     * matching: the prefix's characters in order, each match worth more at
     * the start of the label, of a word in it or right after the one
     * before, and less the later it comes.
     */
    public function filter(string $prefix): void
    {
        $this->prefix = $prefix;
        $filtered = []; $scores = [];
        foreach ($this->items as $i => $it) {
            $s = self::fuzzyScore($it->filterText !== '' ? $it->filterText : $it->label, $prefix);
            if ($s !== null) { $filtered[] = $i; $scores[] = $s; }
        }
        $idx = array_keys($filtered);
        usort($idx, fn(int $a, int $b) => $scores[$b] <=> $scores[$a]); // stable
        $this->filtered = array_map(fn(int $j) => $filtered[$j], $idx);
        $this->scores = array_map(fn(int $j) => $scores[$j], $idx);
        $this->selected = 0;
        $this->scrollTo = true;
    }

    /** How well $query matches $text, by subsequence, or null when it does not. */
    public static function fuzzyScore(string $text, string $query): ?int
    {
        if ($query === '') { return 0; }
        $score = 0; $ti = 0; $prevMatch = -2;
        $tr = mb_str_split($text, 1, 'UTF-8');
        foreach (mb_str_split($query, 1, 'UTF-8') as $q) {
            $ql = mb_strtolower($q, 'UTF-8');
            $found = false;
            while ($ti < count($tr)) {
                $c = $tr[$ti];
                if (mb_strtolower($c, 'UTF-8') === $ql) {
                    $pts = 1;
                    if ($ti === 0) {
                        $pts += 8;
                    } elseif ($ti === $prevMatch + 1) {
                        $pts += 5;
                    } elseif (!View::isWordRune($tr[$ti - 1]) || Chars::isUpper($c) && Chars::isLower($tr[$ti - 1])) {
                        $pts += 4;
                    }
                    if ($c === $q) { $pts++; }
                    $score += $pts * 16 - $ti;
                    $prevMatch = $ti;
                    $ti++;
                    $found = true;
                    break;
                }
                $ti++;
            }
            if (!$found) { return null; }
        }
        // Shorter labels win ties.
        return $score - (count($tr) - mb_strlen($query, 'UTF-8'));
    }
}
