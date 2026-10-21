<?php
declare(strict_types=1);

namespace MyPHP\UI\Markdown;

use MyPHP\UI\Editor\Theme as CodeTheme;
use MyPHP\UI\Theme as UITheme;

/**
 * How Markdown looks (mygo's markdown.Theme): fonts, colors and spacing for
 * each kind of block and inline. What it leaves unset (0, '' or null)
 * takes the value of defaultTheme() for the window's theme, so that a theme
 * sets only what it changes:
 *
 *     $app->md->theme = new Theme(link: [0x7c3aed, 1.0], codeFont: 'JetBrains Mono');
 *
 * Lengths in ems are times the font size. A color is [0xRRGGBB, alpha].
 */
final class Theme
{
    /** @var list<HeadingStyle> the styles of headings of levels 1 to 6 */
    public array $headings = [];

    /**
     * @param ?array{0: int, 1: float} $text
     * @param list<HeadingStyle> $headings
     * @param array{0: float, 1: float} $tableCellPadding
     */
    public function __construct(
        /** The size of body text in points: the window theme's when 0. */
        public float $fontSize = 0,
        /** The family of text, the window theme's when empty, and of code, the system's monospaced font when empty. */
        public string $font = '',
        public string $codeFont = '',
        /** How many times its font's line height a line of text is (1.5 by default), and one of code (1.4). */
        public float $lineHeight = 0,
        public float $codeLineHeight = 0,
        /** Text, what is secondary (list markers, quotes, a code block's language, footnotes), links, and strong text, the text's color by default. */
        public ?array $text = null,
        public ?array $muted = null,
        public ?array $link = null,
        public ?array $strong = null,
        /** The weight of strong text: 600. */
        public int $strongWeight = 0,
        /** Code spans' color, with $codeBackground behind them; $codeSize their size in ems: 0.875. */
        public ?array $code = null,
        public ?array $codeBackground = null,
        public float $codeSize = 0,
        /** The space between blocks, in ems (0.75), and that between the items of a tight list (0.25). */
        public float $blockSpacing = 0,
        public float $itemSpacing = 0,
        array $headings = [],
        /** How far list items are from their list's edge, their markers in that room, in ems: 1.6. */
        public float $listIndent = 0,
        /** The bar along block quotes, $quoteBarWidth points wide (3), and their text. */
        public ?array $quoteBar = null,
        public ?array $quoteText = null,
        public float $quoteBarWidth = 0,
        /** Thematic breaks and the rules of headings. */
        public ?array $rule = null,
        /** Code blocks' colors, corners of $codeBlockRadius points (the window theme's radius plus 2) and $codeBlockPadding ems of room inside (0.85); $codeColors colors their code's tokens, the code editor's default colors when null. */
        public ?array $codeBlockBackground = null,
        public ?array $codeBlockBorder = null,
        public float $codeBlockRadius = 0,
        public float $codeBlockPadding = 0,
        public ?CodeTheme $codeColors = null,
        /** A table's lines, the background of its header row, and that of every other row (none by default); the room in cells in ems, 0.6 across and 0.4 down; how wide a column grows before its cells wrap, in ems: 24. */
        public ?array $tableBorder = null,
        public ?array $tableHeader = null,
        public ?array $tableStripe = null,
        public array $tableCellPadding = [0, 0],
        public float $tableMaxColumn = 0,
        /** The background of text marked with <mark>. */
        public ?array $mark = null,
        /** The check boxes of task lists that are checked. */
        public ?array $checkbox = null,
    ) {
        $this->headings = $headings;
    }

    /**
     * The Markdown theme of a window's theme: its colors, a code span tinted
     * with its text color, code colored as the code editor colors it, and
     * the spacing of the chat apps (mygo's DefaultTheme).
     */
    public static function defaultTheme(UITheme $t): self
    {
        $c = fn(int $rgb, float $a = 1.0) => [$rgb, $a];
        $th = new self(
            fontSize: $t->fontSize, font: '', codeFont: 'monospace', lineHeight: 1.5, codeLineHeight: 1.4,
            text: $c($t->text), muted: $c($t->textMuted), link: $c($t->accent), strong: $c($t->text), strongWeight: 600,
            code: $c($t->text), codeBackground: $c($t->text, 0.07), codeSize: 0.875, blockSpacing: 0.75, itemSpacing: 0.25, listIndent: 1.6,
            quoteBar: $c($t->border), quoteText: $c($t->textMuted), quoteBarWidth: 3, rule: $c($t->border),
            codeBlockBackground: $c($t->text, 0.04), codeBlockBorder: $c($t->border), codeBlockRadius: $t->radius + 2, codeBlockPadding: 0.85,
            codeColors: CodeTheme::defaults($t), tableBorder: $c($t->border), tableHeader: $c($t->text, 0.04), tableCellPadding: [0.6, 0.4],
            tableMaxColumn: 24, mark: $c(0xfacc15, 0.35), checkbox: $c($t->accent),
        );
        if ($t->fontSize == 0) {
            $th->fontSize = 14;
        }
        $sizes = [1.6, 1.35, 1.15, 1, 0.95, 0.9];
        $weights = [700, 650, 600, 600, 600, 600];
        for ($i = 0; $i < 6; $i++) {
            $th->headings[$i] = new HeadingStyle($sizes[$i], $weights[$i], $c($t->text), 1.4, 0.55);
        }
        $th->headings[5]->color = $c($t->textMuted);
        return $th;
    }

    /** $th with what it leaves unset taken from $def (mygo's resolve). */
    public static function resolve(?self $th, self $def): self
    {
        if ($th === null) {
            return $def;
        }
        $o = clone $th;
        foreach (['fontSize', 'lineHeight', 'codeLineHeight', 'codeSize', 'blockSpacing', 'itemSpacing', 'listIndent', 'quoteBarWidth', 'codeBlockRadius', 'codeBlockPadding', 'tableMaxColumn'] as $f) {
            if ($o->$f == 0) { $o->$f = $def->$f; }
        }
        foreach (['font', 'codeFont'] as $f) {
            if ($o->$f === '') { $o->$f = $def->$f; }
        }
        foreach (['text', 'muted', 'link', 'codeBackground', 'quoteBar', 'rule', 'codeBlockBackground', 'codeBlockBorder', 'tableBorder', 'tableHeader', 'mark', 'checkbox'] as $f) {
            $o->$f ??= $def->$f;
        }
        $o->strong ??= $o->text;
        if ($o->strongWeight === 0) { $o->strongWeight = $def->strongWeight; }
        $o->code ??= $o->text;
        $o->quoteText ??= $o->muted;
        $heads = [];
        for ($i = 0; $i < 6; $i++) {
            $h = isset($o->headings[$i]) ? clone $o->headings[$i] : new HeadingStyle();
            $d = $def->headings[$i];
            if ($h->size == 0) { $h->size = $d->size; }
            if ($h->weight === 0) { $h->weight = $d->weight; }
            $h->color ??= $i === 5 ? $o->muted : $o->text;
            if ($h->top == 0) { $h->top = $d->top; }
            if ($h->bottom == 0) { $h->bottom = $d->bottom; }
            $heads[] = $h;
        }
        $o->headings = $heads;
        $o->codeColors ??= $def->codeColors;
        if ($o->tableCellPadding[0] == 0) { $o->tableCellPadding[0] = $def->tableCellPadding[0]; }
        if ($o->tableCellPadding[1] == 0) { $o->tableCellPadding[1] = $def->tableCellPadding[1]; }
        return $o;
    }

    /** $v ems in points. */
    public function em(float $v): float { return $v * $this->fontSize; }

    /** What tells themes apart, to lay out anew when it changes. */
    public function key(): string { return serialize($this); }
}
