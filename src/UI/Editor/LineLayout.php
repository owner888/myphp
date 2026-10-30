<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/**
 * A line laid out for display (mygo's lineLayout): its text, as
 * displayed (tabs expanded, inlays inserted) with the segments mapping
 * the displayed text to the line's, its rows, and its tokens.
 */
final class LineLayout
{
    public const SEG_TEXT = 0, SEG_TAB = 1, SEG_INLAY = 2;

    /** The displayed text. */
    public string $disp = '';
    /** @var ?list<array{disp: int, from: int, to: int, kind: int, inlay: int, ghost: bool}> runs of the displayed text: the displayed byte each starts at, the bytes of the line it shows, its kind, the inlay it shows, and whether the inlay is a suggestion (a caret at it shows before) */
    public ?array $segs = null;
    /** @var list<array{from: int, to: int, indent: float, width: float}> the rows: displayed bytes, the indent of rows after the first, the width */
    public array $rows = [];
    /** @var list<array{0: int, 1: int}> */
    public array $toks = [];
    /** The tokens are from a confirmed state. */
    public bool $exact = false;
    /** @var list<array{0: int, 1: int}> the faces of the tokens (Font::BOLD, Font::ITALIC bits) by displayed bytes: [end, face] runs, bytes past the last regular; none when all are */
    public array $faces = [];
    public float $width = 0.0;
    public int $used = 0;

    /** @param int $n the line @param int $start the byte of the text it starts at @param string $text the line, without its newline */
    public function __construct(public int $n, public int $start, public string $text) {}

    /** Maps a byte of the line to its displayed byte; $after places an offset at an inlay after it. */
    public function toDisp(int $col, bool $after): int
    {
        if ($this->segs === null) { return $col; }
        $out = strlen($this->disp);
        for ($i = count($this->segs) - 1; $i >= 0; $i--) {
            $s = $this->segs[$i];
            switch ($s['kind']) {
                case self::SEG_TEXT:
                    if ($col >= $s['from'] && $col <= $s['to']) { return $s['disp'] + $col - $s['from']; }
                    break;
                case self::SEG_TAB:
                    if ($col === $s['from']) { return $s['disp']; }
                    if ($col === $s['to']) { return $s['disp'] + $this->segLen($i); }
                    break;
                case self::SEG_INLAY:
                    if ($col === $s['from']) {
                        if ($after && !$s['ghost']) { return $s['disp'] + $this->segLen($i); }
                        $out = $s['disp'];
                        continue 2;
                    }
            }
            if ($s['from'] < $col) { break; }
        }
        return $out;
    }

    public function segLen(int $i): int
    {
        if ($i + 1 < count($this->segs)) { return $this->segs[$i + 1]['disp'] - $this->segs[$i]['disp']; }
        return strlen($this->disp) - $this->segs[$i]['disp'];
    }

    /** Maps a displayed byte to the byte of the line, the nearest edge for a byte inside a tab or an inlay. */
    public function fromDisp(int $d): int
    {
        if ($this->segs === null) { return min($d, strlen($this->text)); }
        $lo = 0; $hi = count($this->segs);
        while ($lo < $hi) { $m = ($lo + $hi) >> 1; if ($this->segs[$m]['disp'] > $d) { $hi = $m; } else { $lo = $m + 1; } }
        $i = $lo - 1;
        if ($i < 0) { return 0; }
        $s = $this->segs[$i];
        switch ($s['kind']) {
            case self::SEG_TEXT: return min($s['from'] + $d - $s['disp'], $s['to']);
            case self::SEG_TAB: return $d - $s['disp'] > intdiv($this->segLen($i), 2) ? $s['to'] : $s['from'];
        }
        return $s['from'];
    }

    /** The row holding displayed byte $d, the row it ends rather than the next for a byte at a wrap when $upstream. */
    public function rowOf(int $d, bool $upstream): int
    {
        $n = count($this->rows);
        foreach ($this->rows as $i => $r) {
            if ($d < $r['to'] || $i === $n - 1 || $d === $r['to'] && $upstream) { return $i; }
        }
        return 0;
    }
}
