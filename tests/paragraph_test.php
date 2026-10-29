<?php
// Paragraphs the shared layout breaks itself (mygo's internal/text
// paragraph_test.go and ui text_layout_test.go, a8f25ed): soft hyphens
// showing as hyphens where lines break at them and as nothing elsewhere;
// the first line indented; lines justified; spans shifted off the
// baseline; a layout's lines.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Text\{Engine, Font, Glyph, Layout, Params, Span, Style, System};

/** Breaks lines greedily after spaces and soft hyphens, as the system's engines do, leaving no room for the hyphen of a line broken at a soft hyphen. Every rune is 10 points wide but soft hyphens, which are $shy wide; a word wider than a line breaks between its runes (mygo's breakEngine). */
final class BreakEngine implements Engine
{
    public int $shaped = 0;
    public function __construct(public float $shy = 0, public bool $omit = false) {}
    private array $fonts = [];
    public function font(Style $style): ?Font
    {
        $s = $style->fontSize();
        return $this->fonts[(string)$s] ??= new Font($s, 0.8 * $s, 0.2 * $s, 0, 1, 0.1 * $s, 0.05 * $s, -0.3 * $s, 0.05 * $s);
    }
    public function shape(array $text, Style $style, array $spans, float $width, bool $rtl, bool $wholeWords): array
    {
        $this->shaped += count($text);
        $f = $this->font($style);
        $adv = fn(int $r) => $r === 0xAD ? $this->shy : 10.0;
        $lines = []; $n = count($text);
        for ($start = 0; $start < $n;) {
            $end = $n;
            if ($width > 0) {
                [$w, $opp, $i] = [0.0, -1, $start];
                for (; $i < $n; $i++) {
                    if ($text[$i] !== 0x20 && $w + $adv($text[$i]) > $width + 1e-3) { break; }
                    $w += $adv($text[$i]);
                    if ($text[$i] === 0x20 || $text[$i] === 0xAD) { $opp = $i + 1; }
                }
                if ($i < $n) {
                    $end = $opp;
                    if ($end <= $start) { $end = max($i, $start + 1); }
                }
            }
            $glyphs = []; $x = 0.0;
            for ($i = $start; $i < $end; $i++) {
                if ($this->omit && $text[$i] === 0xAD) { continue; }
                $glyphs[] = new Glyph($f, $text[$i], $f->size, $x, 0, $adv($text[$i]), $i, 0, $rtl);
                $x += $adv($text[$i]);
            }
            $lines[] = ['start' => $start, 'end' => $end, 'runs' => [['font' => $f, 'start' => $start, 'end' => $end, 'glyphs' => $glyphs]]];
            $start = $end;
        }
        return $lines;
    }
    public function outline(Font $font, int $glyph): array { return []; }
    public function register(string $data, string $family): void {}
    public function fontFamilies(): array { return [[], []]; }
}

$lineText = fn(Layout $l, int $i) => mb_convert_encoding(pack('N*', ...array_slice($l->runes, $l->lines[$i]->start, $l->lines[$i]->end - $l->lines[$i]->start)), 'UTF-8', 'UTF-32BE');
$glyphsOf = fn(\MyPHP\Text\Line $line, int $r) => array_values(array_filter($line->glyphs, fn(Glyph $g) => $g->cluster === $r));
$st = new Style(size: 10);

// Soft hyphens: a hyphen where a line breaks at one, nothing elsewhere; a line broken again where its hyphen would not fit.
foreach ([0.0, 5.0] as $shy) {
    $s = new System(new BreakEngine($shy));
    $whole = $s->layout(new Params("ab\u{AD}cd", $st));
    same([40.0, 1], [$whole->width, count($whole->lines)], "shy $shy: unbroken, the soft hyphen takes no room");
    $g = $glyphsOf($whole->lines[0], 2);
    check(count($g) === 1 && $g[0]->font === null && $g[0]->advance == 0 && $g[0]->x == 20, "shy $shy: an unbroken soft hyphen draws nothing");
    same(20.0, $glyphsOf($whole->lines[0], 3)[0]->x, "shy $shy: the rune after it closes up");

    $l = $s->layout(new Params("aaaa\u{AD}bbbb cc", $st, 50));
    same([3, "aaaa\u{AD}", 'bbbb '], [count($l->lines), $lineText($l, 0), $lineText($l, 1)], "shy $shy: broken at the soft hyphen");
    $hy = $glyphsOf($l->lines[0], 4);
    check(count($hy) === 1 && $hy[0]->id === 0x2D && $hy[0]->advance == 10 && $hy[0]->x == 40 && $l->lines[0]->width == 50, "shy $shy: a hyphen ends the line: " . json_encode([$hy[0]->id ?? null, $hy[0]->x ?? null, $l->lines[0]->width]));

    $l = $s->layout(new Params("aa\u{AD}aa\u{AD}bbbb", $st, 45));
    $got = []; $fits = true;
    foreach ($l->lines as $i => $line) { $got[] = $lineText($l, $i); $fits = $fits && $line->width <= 45; }
    same(["aa\u{AD}", "aa\u{AD}", 'bbbb'], $got, "shy $shy: \"aa\u{AD}aa-\" does not fit 45: broken at the first");
    check($fits, "shy $shy: every line fits");
    foreach ([0, 1] as $i) {
        $hy = $glyphsOf($l->lines[$i], $l->lines[$i]->end - 1);
        check(count($hy) === 1 && $hy[0]->id === 0x2D, "shy $shy: line $i ends with a hyphen");
    }
    $l = $s->layout(new Params("aaaa\u{AD}bb", $st, 45));
    same([2, "aaaa\u{AD}", 50.0], [count($l->lines), $lineText($l, 0), $l->lines[0]->width], "shy $shy: a word's part alone fits beside the hyphen: the hyphen overflows");
    $e = $s->engine; $e->shaped = 0;
    $l = $s->layout(new Params(str_repeat("a\u{AD}", 20) . 'a', $st, 15));
    check(count($l->lines) === 21 && $e->shaped <= 3 * 41, "shy $shy: lines whose hyphens overflow shape their own runes again: " . count($l->lines) . " lines, {$e->shaped} runes shaped");

    $om = new System(new BreakEngine($shy, true));
    $l = $om->layout(new Params("aaaa\u{AD}bbbb cc", $st, 50));
    $hy = $glyphsOf($l->lines[0], 4);
    check(count($hy) === 1 && $hy[0]->id === 0x2D && $hy[0]->x == 40 && $l->lines[0]->width == 50, "shy $shy: an engine giving soft hyphens no glyph gets the hyphen too");
    $a = $glyphsOf($l->lines[0], 3);
    same([1, 1], [count($a), $a[0]->runes], "shy $shy: the rune before it, one rune");
    $l = $s->layout(new Params("ab\u{AD}cd", $st));
    same([20.0, 20.0], [$l->caret(2)[0], $l->caret(3)[0]], "shy $shy: carets step over the hidden soft hyphen");
}

// The first line indented, and wrapped that much narrower, on the side its paragraph starts.
$s = new System(new BreakEngine());
$l = $s->layout(new Params('aaa bbb ccc ddd', $st, 80, indent: 25));
same(['aaa ', 'bbb ccc ', 'ddd'], [$lineText($l, 0), $lineText($l, 1), $lineText($l, 2)], 'indented, the first line narrower');
same([25.0, 0.0, 0.0, 25.0], [$l->lines[0]->x, $l->lines[1]->x, $l->lines[2]->x, $l->caret(0)[0]], 'only the first line moves in, the caret with it');
$l = $s->layout(new Params('aaa', $st, indent: 25));
same([55.0, 25.0], [$l->width, $l->lines[0]->x], 'unwrapped, the indent takes room');
same(45.0, $s->layout(new Params('aaa', $st, 100, align: Params::CENTER, indent: 20))->lines[0]->x, 'centered, in the middle of the room beside it');
$l = $s->layout(new Params('aaaa bbbb cccc', $st, 60, maxLines: 1, indent: 20));
check($l->truncated && $l->lines[0]->width <= 40 && $l->lines[0]->x == 20, 'cut short, the ellipsis beside the indent: ' . json_encode([$l->lines[0]->width, $l->lines[0]->x]));
$l = $s->layout(new Params("a\nb", $st, indent: 20));
same([20.0, 0.0], [$l->lines[0]->x, $l->lines[1]->x], 'only the first paragraph\'s first line');
$l = $s->layout(new Params(str_repeat('אב ', 30), $st, 200, indent: 40));
check($l->lines[0]->rtl && abs($l->lines[0]->x + $l->lines[0]->width - 160) < 0.01 && abs($l->lines[1]->x + $l->lines[1]->width - 200) < 0.01, 'right to left, the indent at the right: ' . json_encode([$l->lines[0]->x + $l->lines[0]->width, $l->lines[1]->x + $l->lines[1]->width]));

// Justified: every line but the last of a paragraph stretched to the width by its spaces.
$p = fn(array $o = []) => new Params(...['text' => "aaa bbb ccc ddd\naa bb", 'style' => $st, 'width' => 100, 'justify' => true, ...$o]);
$l = $s->layout($p());
same([3, 'aaa bbb '], [count($l->lines), $lineText($l, 0)], 'justified: the lines');
same([0.0, 100.0], [$l->lines[0]->x, $l->lines[0]->width], 'the first line as wide as the width');
same([70.0, 40.0, 70.0], [$glyphsOf($l->lines[0], 4)[0]->x, $glyphsOf($l->lines[0], 3)[0]->advance, $l->caret(4)[0]], 'its space widened, the second word at the end, the caret with it');
check($l->lines[1]->x == 0 && $l->lines[1]->width == 70 && $l->lines[2]->width == 50, 'the last lines of the paragraphs as they are');
$l = $s->layout($p(['align' => Params::CENTER]));
same([0.0, 15.0], [$l->lines[0]->x, $l->lines[1]->x], 'centered, the last lines are');
$l = $s->layout($p(['keepSpaces' => true]));
same([110.0, 70.0, 110.0], [$l->lines[0]->width, $glyphsOf($l->lines[0], 4)[0]->x, $l->width], 'spaces kept hang past the width');
same(100.0, $s->layout(new Params('aaa bbb cccc', $st, 100, justify: true))->width, 'the text as wide as its lines stretched');
$l = $s->layout($p(['indent' => 10]));
same([10.0, 90.0], [$l->lines[0]->x, $l->lines[0]->width], 'indented, the first line fills the room beside it');
same(70.0, $s->layout(new Params('aaaaaaaaaaaa', $st, 75, justify: true))->lines[0]->width, 'a line without spaces stays as it is');

// Han and kana widen the room after them but the last.
$runes = System::runes('日本語です');
$line = new \MyPHP\Text\Line(start: 0, end: count($runes), width: 50);
foreach ($runes as $i => $r) { $line->glyphs[] = new Glyph(null, 0, 10, $i * 10, 0, 10, $i, 1); }
$justify = (new \ReflectionMethod(System::class, 'justifyLine'))->getClosure();
same(0.0, $justify($line, $runes, 90), 'justified from 0');
same([90.0, [0.0, 20.0, 40.0, 60.0, 80.0], 10.0], [$line->width, array_map(fn($g) => $g->x, $line->glyphs), $line->glyphs[4]->advance], 'each glyph 20 apart, the last as wide as it was');

// Spans shifted off the baseline: the line makes room, decorations follow.
$s = new System(new BreakEngine());
$big = new Style(size: 16);
$spans = [new Span(1), new Span(2, size: 10, baselineShift: 7), new Span(4), new Span(5, size: 10, baselineShift: -4)];
$plain = $s->layout(new Params('x2 H2O', $big));
$l = $s->layout(new Params('x2 H2O', $big, spans: $spans));
$line = $l->lines[0];
[$x, $sup, $sub] = [$glyphsOf($line, 0)[0], $glyphsOf($line, 1)[0], $glyphsOf($line, 4)[0]];
same([$line->baseline, $line->baseline - 7, $line->baseline + 4], [$x->y, $sup->y, $sub->y], 'raised and lowered');
check($line->ascent >= $sup->font->ascent + 7 && $line->descent >= $sub->font->descent + 4 && $l->height > $plain->height, 'the line makes room for them');
$flat = $s->layout(new Params('x2 H2O', $big, spans: [new Span(1), new Span(2, size: 10), new Span(4), new Span(5, size: 10)]));
$under = fn(Layout $l, int $i) => $s->decorate($l, 0, $i, $i + 1, 0, 0, 1, System::UNDERLINE, 0)[0][2];
$d = ($under($flat, 1) - $flat->lines[0]->baseline) - ($under($l, 1) - $line->baseline);
check($d >= 6 && $d <= 8, "the superscript's underline goes up with it: $d");
check($s->layout(new Params("a\u{FFFC}", $big, spans: [new Span(1), new Span(2, objectWidth: 5, objectHeight: 30, baselineShift: 10)]))->lines[0]->ascent >= 40, 'a raised object too');

// A layout's lines (mygo's TextLayout.Lines), justified and indented through the UI's shaping.
$sys = new System(new BreakEngine());
$tl = \MyPHP\UI\TextLayout::shape($sys, [new \MyPHP\UI\Span("aaa bbb ccc ddd\nee")], new \MyPHP\UI\TextStyle(), 10, 100, justify: true, indent: 10);
$lines = $tl->lines();
same(3, count($lines), 'its lines');
same([[10.0, 0.0, 100.0], 0, 8], [[$lines[0]->rect[0], $lines[0]->rect[1], $lines[0]->rect[2]], $lines[0]->start, $lines[0]->end], 'the first, indented and justified, the space ending it kept past the width, and its offsets');
same([8, 15, 16, 18], [$lines[1]->start, $lines[1]->end, $lines[2]->start, $lines[2]->end], 'a newline ending a line is not in it');
check($lines[1]->baseline > $lines[0]->baseline && $lines[0]->ascent > 0 && $lines[0]->descent > 0 && !$lines[0]->rtl, 'baselines from the top');
$ol = \MyPHP\UI\TextLayout::shape($sys, [new \MyPHP\UI\Span('a'), new \MyPHP\UI\Span("\u{FFFC}", objectWidth: 5, objectHeight: 10, objectDescent: 2, baselineShift: 4)], new \MyPHP\UI\TextStyle(), 10);
$o = $ol->objects()[0][1];
same([$ol->lines()[0]->baseline - 14, 12.0], [$o[1], $o[3]], 'a shifted object\'s box goes up with it');

// The system's engine (mygo's TestSoftHyphensOfEngine, and its indent and justify): soft hyphens as hyphens where lines break at them, nothing elsewhere, lines fitting their width.
if (PHP_OS_FAMILY === 'Darwin') {
    \MyPHP\Native\ObjC::boot(['Foundation', 'AppKit']);
    $mac = new System(new \MyPHP\Native\TextEngine(new \MyPHP\Native\CoreText(2.0)));
    $text = "extra\u{AD}ordinary\u{AD}things hap\u{AD}pen";
    $s16 = new Style(size: 16);
    $plain = $mac->layout(new Params(str_replace("\u{AD}", '', $text), $s16));
    $whole = $mac->layout(new Params($text, $s16));
    check(abs($whole->width - $plain->width) <= 1.5, "Core Text: unbroken, {$whole->width} wide, {$plain->width} without the soft hyphens");
    $hyphens = 0; $bad = [];
    for ($w = 60.0; $w <= 260; $w += 5) {
        $l = $mac->layout(new Params($text, $s16, $w));
        foreach ($l->lines as $i => $line) {
            $inner = array_slice($l->runes, $line->start, max(0, $line->end - 1 - $line->start));
            $word = $i < count($l->lines) - 1 && $line->end > $line->start && $l->runes[$line->end - 1] === 0xAD && !in_array(0x20, $inner, true) && !in_array(0xAD, $inner, true);
            if ($line->width > $w + 0.5 && !$word) { $bad[] = "at $w line $i is {$line->width} wide"; }
            for ($r = $line->start; $r < $line->end; $r++) {
                if ($l->runes[$r] !== 0xAD) { continue; }
                $broken = $r === $line->end - 1 && $i < count($l->lines) - 1;
                foreach ($glyphsOf($line, $r) as $g) {
                    if (($g->font !== null && $g->advance > 0) !== $broken) { $bad[] = "at $w the soft hyphen in line $i shows: " . json_encode(!$broken); }
                }
                if ($broken) { $hyphens++; }
            }
        }
    }
    same([], $bad, 'Core Text: lines fit, soft hyphens show where they break alone');
    check($hyphens > 0, 'Core Text: a line broke at a soft hyphen');
    $long = str_repeat('The quick brown fox jumps over the lazy dog. ', 4);
    $in = $mac->layout(new Params($long, $s16, 200, indent: 40));
    check($in->lines[0]->x == 40 && $in->lines[0]->width <= 160.5 && $in->lines[1]->x == 0, 'Core Text: indented');
    $lj = $mac->layout(new Params($long, $s16, 230, justify: true));
    $edges = true;
    foreach (array_slice($lj->lines, 0, -1) as $line) { $edges = $edges && $line->x == 0 && abs($line->x + $line->width - 230) < 0.01; }
    check($edges, 'Core Text: justified to the width');
}
