<?php
declare(strict_types=1);

namespace MyPHP\Media;

/**
 * An ASS or SSA script's header and its dialogue lines in their styles
 * (libass's reading of them): [Script Info]'s PlayResX and PlayResY (384
 * by 288 when neither is given), WrapStyle and ScaledBorderAndShadow, and
 * the styles of [V4+ Styles] (or SSA's [V4 Styles]) by their Format line.
 * A line's override tags change its runs' styles: \b \i \u \s, \fn \fs
 * \fsp, \c \1c \2c \3c \4c and \alpha \1a \2a \3a \4a, \bord \shad,
 * \fscx \fscy \frz, \r, \p (a drawing, left out); and the line's: \an \a,
 * \pos, \move, \fad \fade, \clip (a rectangle), \org; \t animates what
 * follows it, \k \K \kf \ko sing its syllables. \be, \blur, \fax, \fay,
 * \frx, \fry and the borders and shadows apart in x and y are left out.
 */
final class Ass
{
    /** libass's style when a script names one it has not. */
    private const DEFAULT = ['name' => 'Default', 'fontname' => 'Arial', 'fontsize' => '18', 'primarycolour' => '&H00FFFFFF', 'outlinecolour' => '&H00000000', 'backcolour' => '&H00000000',
        'bold' => '0', 'italic' => '0', 'underline' => '0', 'strikeout' => '0', 'spacing' => '0', 'borderstyle' => '1', 'outline' => '2', 'shadow' => '2', 'alignment' => '2',
        'marginl' => '20', 'marginr' => '20', 'marginv' => '20'];

    public readonly int $playResX, $playResY;
    public readonly bool $scaledBorder, $wraps;
    /** @var array<string, array<string, string>> the styles by name, their fields by their Format names, lowercase */
    private array $styles = [];

    /** Reads the header of script $s: what comes before [Events], and the rest if any. */
    public function __construct(string $s)
    {
        $info = []; $section = ''; $format = null; $ssa = false;
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $s)) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === ';') {
                continue;
            }
            if ($line[0] === '[') {
                $section = strtolower($line);
                $ssa = $ssa || $section === '[v4 styles]';
                $format = null;
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
            if ($section === '[script info]') {
                $info[strtolower($key)] = $value;
            } elseif ($section === '[v4+ styles]' || $section === '[v4 styles]') {
                if (strcasecmp($key, 'Format') === 0) {
                    $format = array_map(fn(string $f) => strtolower(trim($f)), explode(',', $value));
                } elseif (strcasecmp($key, 'Style') === 0 && $format !== null) {
                    $f = array_combine($format, array_pad(explode(',', $value, count($format)), count($format), ''));
                    $f = array_map('trim', $f);
                    if ($ssa) { // SSA's: the outline is its TertiaryColour, its alignment the old one
                        $f['outlinecolour'] ??= $f['tertiarycolour'] ?? '';
                        $f['alignment'] = (string)self::numpad((int)($f['alignment'] ?? 2));
                    }
                    $this->styles[$f['name'] ?? 'Default'] = $f + self::DEFAULT;
                }
            }
        }
        [$x, $y] = [(int)($info['playresx'] ?? 0), (int)($info['playresy'] ?? 0)];
        if ($x <= 0 && $y <= 0) { [$x, $y] = [384, 288]; }
        elseif ($x <= 0) { $x = $y === 1024 ? 1280 : intdiv($y * 4, 3); }
        elseif ($y <= 0) { $y = $x === 1280 ? 1024 : intdiv($x * 3, 4); }
        [$this->playResX, $this->playResY] = [$x, $y];
        $this->scaledBorder = strtolower($info['scaledborderandshadow'] ?? 'yes') !== 'no';
        $this->wraps = (int)($info['wrapstyle'] ?? 0) !== 2;
    }

    /**
     * Dialogue line $text of style $style, layer $layer and margins (0 for
     * the style's), showing from $start for $duration seconds, as it shows
     * at its start (AssLine::at for later); null for one that shows nothing
     * (empty, or a drawing alone).
     */
    public function line(string $text, string $style, int $layer = 0, int $marginL = 0, int $marginR = 0, int $marginV = 0, float $start = 0.0, float $duration = INF): ?AssLine
    {
        $base = $this->styles[ltrim($style, '*')] ?? $this->styles['Default'] ?? self::DEFAULT;
        $st = self::state($base);
        $line = ['align' => (int)$base['alignment'], 'pos' => null, 'move' => null, 'fade' => null, 'clip' => null, 'org' => null, 'drawing' => false];
        $parts = []; // each run's text, its state, the \t's over it and its syllable
        $transforms = []; $karaoke = 0.0; $syllable = null;
        foreach (preg_split('/(\{[^}]*\})/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if ($part[0] === '{') {
                foreach (self::tags(substr($part, 1, -1)) as [$name, $arg]) {
                    $this->tag($name, $arg, $st, $base, $line, $transforms, $karaoke, $syllable);
                }
                continue;
            }
            if ($line['drawing']) {
                continue;
            }
            $part = str_replace(['\h', '\n'], ["\u{00A0}", $this->wraps ? ' ' : "\n"], $part);
            foreach (preg_split('/(\\\\N)/', $part, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
                $parts[] = [$piece === '\N' ? "\n" : $piece, $st, $transforms, $syllable];
            }
            $syllable = null; // a syllable's text is what follows its \k, up to the next tags
        }
        $shape = [$line['align'], $marginL ?: (int)$base['marginl'], $marginR ?: (int)$base['marginr'], $marginV ?: (int)$base['marginv'], $layer, $this->playResX, $this->playResY, $this->scaledBorder, $this->wraps, $start, $duration];
        $animated = $line['move'] !== null || $line['fade'] !== null || array_filter($parts, fn(array $p) => $p[2] !== [] || $p[3] !== null) !== [];
        $make = function (float $t) use ($parts, $line, $shape): AssLine {
            $runs = [];
            foreach ($parts as [$piece, $state, $ts, $syl]) {
                foreach ($ts as [$t1, $t2, $accel, $tags]) { $state = $this->animate($state, $tags, self::progress($t, $t1, $t2, $accel)); }
                if ($syl !== null) { // karaoke: the secondary colour until its syllable is sung (\ko: no outline until then)
                    [$from, , $kind] = $syl;
                    if ($t < $from) {
                        if ($kind === 'ko') { $state['outlineAlpha'] = 0.0; } else { [$state['color'], $state['alpha']] = [$state['secondary'], $state['secondaryAlpha']]; }
                    }
                }
                $runs[] = self::run($piece, $state);
            }
            [$align, $ml, $mr, $mv, $layer, $rx, $ry, $scaled, $wraps, $start, $duration] = $shape;
            $pos = $line['pos']; $moved = [0.0, 0.0];
            if ($line['move'] !== null) { // \move: from its start to its end between its times (its whole time when it gives none)
                [$x1, $y1, $x2, $y2, $t1, $t2] = $line['move'];
                [$t1, $t2] = $t2 > $t1 ? [$t1, $t2] : [0.0, is_finite($duration) ? $duration : 0.0];
                $k = $t2 > $t1 ? max(0.0, min(1.0, ($t - $t1) / ($t2 - $t1))) : 0.0;
                [$pos, $moved] = [[$x1, $y1], [($x2 - $x1) * $k, ($y2 - $y1) * $k]]; // laid out at its start, moved from it
            }
            $alpha = 1.0;
            if ($line['fade'] !== null) { // \fade: its alphas (0 opaque, 255 clear) between its times, \fad's from and to clear
                [$a1, $a2, $a3, $t1, $t2, $t3, $t4] = $line['fade'][0] === 'fad'
                    ? [255.0, 0.0, 255.0, 0.0, $line['fade'][1], (is_finite($duration) ? $duration : INF) - $line['fade'][2], is_finite($duration) ? $duration : INF]
                    : $line['fade'];
                $a = match (true) { $t < $t1 => $a1, $t < $t2 => $a1 + ($a2 - $a1) * ($t - $t1) / max(1e-9, $t2 - $t1), $t < $t3 => $a2, $t < $t4 => $a2 + ($a3 - $a2) * ($t - $t3) / max(1e-9, $t4 - $t3), default => $a3 };
                $alpha = 1 - $a / 255;
            }
            return new AssLine($runs, $align, $pos, $ml, $mr, $mv, $layer, $rx, $ry, $scaled, $wraps, $start, $duration, $alpha, $line['clip'], $line['org'], $moved);
        };
        $first = $make(0.0);
        if ($first->text() === '') {
            return null;
        }
        return $animated ? $first->animatedBy($make) : $first;
    }

    /** How far a \t from $t1 to $t2 (seconds into the line; 0 and 0 for all of it) has gone at $t, by its acceleration. */
    private static function progress(float $t, float $t1, float $t2, float $accel): float
    {
        if ($t2 <= $t1) { return $t >= $t1 ? 1.0 : 0.0; }
        $k = max(0.0, min(1.0, ($t - $t1) / ($t2 - $t1)));
        return $accel === 1.0 ? $k : $k ** $accel;
    }

    /** State $st part way ($k, 0 to 1) to what tags $tags make of it, as \t animates: its numbers and colors between, the rest left. @param array<string, mixed> $st @param list<array{0: string, 1: string}> $tags @return array<string, mixed> */
    private function animate(array $st, array $tags, float $k): array
    {
        if ($k <= 0) { return $st; }
        $to = $st; $line = ['align' => 0, 'pos' => null, 'move' => null, 'fade' => null, 'clip' => null, 'org' => null, 'drawing' => false]; $none = []; $kar = 0.0; $syl = null;
        foreach ($tags as [$name, $arg]) { $this->tag($name, $arg, $to, [], $line, $none, $kar, $syl); }
        foreach (['size', 'spacing', 'border', 'shadow', 'alpha', 'outlineAlpha', 'shadowAlpha', 'secondaryAlpha', 'scaleX', 'scaleY', 'rotation'] as $f) {
            $st[$f] = $st[$f] + ($to[$f] - $st[$f]) * $k;
        }
        foreach (['color', 'outlineColor', 'shadowColor', 'secondary'] as $f) {
            $c = 0;
            foreach ([16, 8, 0] as $sh) { $c |= (int)round((($st[$f] >> $sh) & 0xff) + (((($to[$f] >> $sh) & 0xff) - (($st[$f] >> $sh) & 0xff)) * $k)) << $sh; }
            $st[$f] = $c;
        }
        return $st;
    }

    /** The state of a run in style $s. @param array<string, string> $s @return array<string, mixed> */
    private static function state(array $s): array
    {
        [$c, $a] = self::color($s['primarycolour']); [$sc, $sa] = self::color($s['secondarycolour'] ?? '&H000000FF'); [$oc, $oa] = self::color($s['outlinecolour']); [$bc, $ba] = self::color($s['backcolour']);
        $bold = (int)$s['bold'];
        return ['font' => $s['fontname'], 'size' => (float)$s['fontsize'], 'weight' => $bold === -1 || $bold === 1 ? 700 : ($bold > 1 ? $bold : 400),
            'italic' => (int)$s['italic'] !== 0, 'underline' => (int)$s['underline'] !== 0, 'strikeout' => (int)$s['strikeout'] !== 0,
            'color' => $c, 'alpha' => $a, 'secondary' => $sc, 'secondaryAlpha' => $sa, 'outlineColor' => $oc, 'outlineAlpha' => $oa, 'shadowColor' => $bc, 'shadowAlpha' => $ba,
            'border' => (float)$s['outline'], 'shadow' => (float)$s['shadow'], 'spacing' => (float)$s['spacing'], 'borderStyle' => (int)$s['borderstyle'] === 3 ? 3 : 1,
            'scaleX' => (float)($s['scalex'] ?? 100), 'scaleY' => (float)($s['scaley'] ?? 100), 'rotation' => (float)($s['angle'] ?? 0)];
    }

    /** @param array<string, mixed> $st */
    private static function run(string $text, array $st): AssRun
    {
        return new AssRun($text, $st['font'], $st['size'], $st['weight'], $st['italic'], $st['underline'], $st['strikeout'], $st['color'], $st['alpha'],
            $st['outlineColor'], $st['outlineAlpha'], $st['shadowColor'], $st['shadowAlpha'], $st['border'], $st['shadow'], $st['spacing'], $st['borderStyle'],
            $st['scaleX'] / 100, $st['scaleY'] / 100, $st['rotation']);
    }

    /**
     * The tags of an override block, [name with its argument, the argument
     * in parentheses or ''], as libass scans them: a tag runs to the next
     * backslash, or to its closing parenthesis (\t's hold tags of their own).
     * @return list<array{0: string, 1: string}>
     */
    private static function tags(string $block): array
    {
        $out = [];
        $n = strlen($block);
        for ($p = strpos($block, '\\'); $p !== false && $p < $n;) {
            $p++;
            $start = $p;
            while ($p < $n && $block[$p] !== '\\' && $block[$p] !== '(') { $p++; }
            $name = trim(substr($block, $start, $p - $start));
            $arg = '';
            if ($p < $n && $block[$p] === '(') {
                $depth = 0; $from = $p + 1;
                for (; $p < $n; $p++) {
                    if ($block[$p] === '(') { $depth++; } elseif ($block[$p] === ')' && --$depth === 0) { break; }
                }
                $arg = substr($block, $from, $p - $from);
                $p = strpos($block, '\\', $p);
                if ($p === false) { $p = $n; }
            }
            if ($name !== '') { $out[] = [$name, $arg]; }
        }
        return $out;
    }

    /**
     * Applies tag $name (its value after it) of argument $arg to the run's
     * state, or to the line's (its alignment, \pos, \move, fades, \clip,
     * \org, whether a drawing follows); a \t to the \t's of what follows,
     * a \k to the syllable that follows.
     * @param array<string, mixed> $st
     * @param array<string, string> $base
     * @param array<string, mixed> $line
     * @param list<array{0: float, 1: float, 2: float, 3: list<array{0: string, 1: string}>}> $transforms
     * @param ?array{0: float, 1: float, 2: string} $syllable
     */
    private function tag(string $name, string $arg, array &$st, array $base, array &$line, array &$transforms, float &$karaoke, ?array &$syllable): void
    {
        if (preg_match('/^(alpha|[1-4]a|[1-4]c|c(?!lip)|an|a(?!lpha)|pos|move|fn|fsp|fscx|fscy|fsc|fs|bord|shad|be|blur|b|iclip|i|u|s|r|pbo|p|t|fr[xyz]?|fa[xy]|fade|fad|org|clip|kf|ko|k|K|q|xbord|ybord|xshad|yshad)(.*)$/s', $name, $m) !== 1) {
            return; // a tag libass knows not either
        }
        [, $tag, $v] = $m;
        $v = trim($v);
        $nums = fn(): array => array_map('floatval', array_map('trim', explode(',', $arg)));
        switch ($tag) {
            case 'c': case '1c': [$st['color']] = self::color($v); break;
            case '2c': [$st['secondary']] = self::color($v); break;
            case '3c': [$st['outlineColor']] = self::color($v); break;
            case '4c': [$st['shadowColor']] = self::color($v); break;
            case 'alpha': $a = self::alpha($v); $st['alpha'] = $st['secondaryAlpha'] = $st['outlineAlpha'] = $st['shadowAlpha'] = $a; break;
            case '1a': $st['alpha'] = self::alpha($v); break;
            case '2a': $st['secondaryAlpha'] = self::alpha($v); break;
            case '3a': $st['outlineAlpha'] = self::alpha($v); break;
            case '4a': $st['shadowAlpha'] = self::alpha($v); break;
            case 'an': if ((int)$v >= 1 && (int)$v <= 9) { $line['align'] = (int)$v; } break;
            case 'a': $line['align'] = self::numpad((int)$v); break;
            case 'pos': $xy = $nums(); if (count($xy) >= 2) { $line['pos'] ??= [$xy[0], $xy[1]]; } break;
            case 'move': // from (x1, y1) to (x2, y2), between t1 and t2 in milliseconds (its whole time without)
                $n = $nums();
                if (count($n) >= 4 && $line['move'] === null) { $line['move'] = [$n[0], $n[1], $n[2], $n[3], ($n[4] ?? 0) / 1000, ($n[5] ?? 0) / 1000]; }
                break;
            case 'fad': // in and out, in milliseconds: from clear, and to clear at its end
                $n = $nums();
                if (count($n) === 2 && $line['fade'] === null) { $line['fade'] = ['fad', $n[0] / 1000, $n[1] / 1000]; } // its times from the line's end, known as it shows
                break;
            case 'fade': // alphas a1, a2, a3 and times t1 to t4
                $n = $nums();
                if (count($n) === 7 && $line['fade'] === null) { $line['fade'] = [$n[0], $n[1], $n[2], $n[3] / 1000, $n[4] / 1000, $n[5] / 1000, $n[6] / 1000]; }
                break;
            case 'clip': // a rectangle; a drawing's clip is left out
                $n = $nums();
                if (count($n) === 4) { $line['clip'] = [min($n[0], $n[2]), min($n[1], $n[3]), max($n[0], $n[2]), max($n[1], $n[3])]; }
                break;
            case 'org': $xy = $nums(); if (count($xy) >= 2) { $line['org'] ??= [$xy[0], $xy[1]]; } break;
            case 'fn': $st['font'] = $v !== '' ? $v : ($base['fontname'] ?? $st['font']); break;
            case 'fs': $st['size'] = $v !== '' && (float)$v > 0 ? ($v[0] === '+' || $v[0] === '-' ? $st['size'] * (1 + (float)$v / 10) : (float)$v) : (float)($base['fontsize'] ?? $st['size']); break;
            case 'fsp': $st['spacing'] = (float)$v; break;
            case 'fscx': $st['scaleX'] = $v !== '' ? (float)$v : (float)($base['scalex'] ?? 100); break;
            case 'fscy': $st['scaleY'] = $v !== '' ? (float)$v : (float)($base['scaley'] ?? 100); break;
            case 'fsc': $st['scaleX'] = (float)($base['scalex'] ?? 100); $st['scaleY'] = (float)($base['scaley'] ?? 100); break;
            case 'fr': case 'frz': $st['rotation'] = (float)$v; break;
            case 'bord': $st['border'] = max(0.0, (float)$v); break;
            case 'shad': $st['shadow'] = max(0.0, (float)$v); break;
            case 'b': $b = (int)$v; $st['weight'] = $b === 1 ? 700 : ($b > 1 ? $b : 400); break;
            case 'i': $st['italic'] = $v === '1'; break;
            case 'u': $st['underline'] = $v === '1'; break;
            case 's': $st['strikeout'] = $v === '1'; break;
            case 'r': $st = self::state($v !== '' && isset($this->styles[$v]) ? $this->styles[$v] : $base); $transforms = []; break;
            case 'p': $line['drawing'] = (int)$v > 0; break;
            case 't': // [t1, t2,] [accel,] tags: what follows goes to them between t1 and t2 (milliseconds; its whole time without)
                if (preg_match('/^\s*(?:(-?[\d.]+)\s*,\s*(-?[\d.]+)\s*,)?\s*(?:([\d.]+)\s*,)?\s*(\\\\.*)$/s', $arg, $tm) === 1) {
                    $t1 = $tm[1] !== '' ? (float)$tm[1] / 1000 : 0.0; $t2 = $tm[2] !== '' ? (float)$tm[2] / 1000 : 0.0;
                    $transforms[] = [$t1, $t2, $tm[3] !== '' ? (float)$tm[3] : 1.0, self::tags($tm[4])];
                }
                break;
            case 'k': case 'K': case 'kf': case 'ko': // a syllable of centiseconds, sung from the time the ones before took
                $d = (float)$v / 100;
                $syllable = [$karaoke, $d, $tag === 'ko' ? 'ko' : 'k'];
                $karaoke += $d;
                break;
        }
    }

    /** An ASS color, &HAABBGGRR& (or SSA's decimal), as 0xRRGGBB and an opacity. @return array{0: int, 1: float} */
    private static function color(string $v): array
    {
        $v = trim($v, " &\t");
        $n = stripos($v, 'h') === 0 ? (int)hexdec(substr($v, 1)) : (int)$v;
        $r = $n & 0xff; $g = ($n >> 8) & 0xff; $b = ($n >> 16) & 0xff; $a = ($n >> 24) & 0xff;
        return [($r << 16) | ($g << 8) | $b, 1 - $a / 255];
    }

    /** An ASS alpha, &HAA& (00 opaque, FF clear), as an opacity. */
    private static function alpha(string $v): float
    {
        $v = trim($v, " &\t");
        return 1 - ((stripos($v, 'h') === 0 ? (int)hexdec(substr($v, 1)) : (int)$v) & 0xff) / 255;
    }

    /** SSA's alignment (\a): 1 to 3 at the bottom, 5 to 7 at the top, 9 to 11 in the middle, on the numpad. */
    private static function numpad(int $a): int
    {
        $col = (($a - 1) & 3) + 1;
        return match (true) { $a >= 9 => 3 + $col, $a >= 5 => 6 + $col, default => $col };
    }
}
