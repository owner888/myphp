<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

use MyPHP\UI\Theme as UITheme;

/**
 * How the editor's text looks (mygo's richtext.Theme): its colors, the
 * font of code, the styles of headings, and the room of lists, quotations
 * and blocks of code. What it leaves unset (0, '' or null) takes the value
 * of defaultTheme() for the window's theme, so that a theme sets only what
 * it changes:
 *
 *     RichText::editor($c, $doc, new EditorOptions(theme: new Theme(link: [0x7c3aed, 1.0])));
 *
 * Lengths in ems are times the editor's font size (EditorOptions::$fontSize).
 * A color is [0xRRGGBB, alpha].
 */
final class Theme
{
    /** @var list<HeadingStyle> the styles of headings of levels 1 to 6 */
    public array $headings;

    /** The default sizes of headings, in ems. */
    private const HEADING_SIZES = [1.8, 1.45, 1.2, 1.1, 1.0, 0.9];

    /**
     * @param ?array{0: int, 1: float} $text
     * @param list<HeadingStyle> $headings
     * @param array{0: float, 1: float} $codeBlockPadding
     * @param array{0: float, 1: float} $padding
     */
    public function __construct(
        /** Colors text; the placeholder, the text of tasks done and the boxes of those to do; links. */
        public ?array $text = null,
        public ?array $muted = null,
        public ?array $link = null,
        /** Colors the caret, and is behind the text selected. */
        public ?array $caret = null,
        public ?array $selection = null,
        /** The family of code, the system's monospaced font when empty, and its size in ems: 0.92. */
        public string $codeFont = '',
        public float $codeSize = 0,
        /** Colors code, within text and in blocks, the text color by default. */
        public ?array $code = null,
        /** Behind code within text, which it hugs with rounded corners. */
        public ?array $codeBackground = null,
        /** Fills blocks of code, with corners of $codeBlockRadius ems (0.3) and $codeBlockPadding ems of room inside: 0.7 across, and 0.5 at their top and bottom. */
        public ?array $codeBlockBackground = null,
        public float $codeBlockRadius = 0,
        public array $codeBlockPadding = [0, 0],
        array $headings = [],
        /** How far a level of a list or of indentation goes, in ems, a list's markers in that room: 1.7. */
        public float $indent = 0,
        /** Colors the bullets and numbers of lists, the text color by default; fills the boxes of tasks done, and draws their checks: white. */
        public ?array $marker = null,
        public ?array $checkbox = null,
        public ?array $checkmark = null,
        /** Colors the bar along quotations, $quoteBarWidth points wide (3), and their text, the muted color by default. */
        public ?array $quoteBar = null,
        public ?array $quoteText = null,
        public float $quoteBarWidth = 0,
        /** The room around the text of an editor, in points across and down: the window theme's space(3) and space(2.5). An editorBase() has none, its room the app's. */
        public array $padding = [0, 0],
    ) {
        $this->headings = $headings;
    }

    /**
     * The editor's theme in a window's theme (mygo's DefaultTheme): its
     * text, muted, accent, selection and border colors, code tinted with its
     * text color, and headings from 1.8 times the text down to 0.9.
     */
    public static function defaultTheme(UITheme $t): Theme
    {
        $th = new Theme(
            text: [$t->text, 1.0], muted: [$t->textMuted, 1.0], link: [$t->accent, 1.0], caret: [$t->accent, 1.0],
            selection: [$t->selection, $t->selectionAlpha], codeFont: 'monospace', codeSize: 0.92, code: [$t->text, 1.0],
            codeBackground: [$t->text, 0.07], codeBlockBackground: [$t->text, 0.07], codeBlockRadius: 0.3, codeBlockPadding: [0.7, 0.5],
            indent: 1.7, marker: [$t->text, 1.0], checkbox: [$t->accent, 1.0], checkmark: [0xffffff, 1.0],
            quoteBar: [$t->border, 1.0], quoteText: [$t->textMuted, 1.0], quoteBarWidth: 3, padding: [$t->space(3), $t->space(2.5)],
        );
        foreach (self::HEADING_SIZES as $size) {
            $th->headings[] = new HeadingStyle($size, 700, [$t->text, 1.0], 0.6, 0.25);
        }
        return $th;
    }

    /**
     * $th with what it leaves unset taken from $def; the colors that default
     * to others of $th take those (mygo's resolve).
     *
     * @internal
     */
    public static function resolve(?Theme $th, Theme $def): Theme
    {
        if ($th === null) { return $def; }
        $o = clone $th;
        $o->text ??= $def->text;
        $o->muted ??= $def->muted;
        $o->link ??= $def->link;
        $o->caret ??= $def->caret;
        $o->selection ??= $def->selection;
        if ($o->codeFont === '') { $o->codeFont = $def->codeFont; }
        if ($o->codeSize == 0) { $o->codeSize = $def->codeSize; }
        $o->code ??= $o->text;
        $o->codeBackground ??= $def->codeBackground;
        $o->codeBlockBackground ??= $def->codeBlockBackground;
        if ($o->codeBlockRadius == 0) { $o->codeBlockRadius = $def->codeBlockRadius; }
        for ($k = 0; $k < 2; $k++) {
            if (($o->codeBlockPadding[$k] ?? 0) == 0) { $o->codeBlockPadding[$k] = $def->codeBlockPadding[$k]; }
            if (($o->padding[$k] ?? 0) == 0) { $o->padding[$k] = $def->padding[$k]; }
        }
        $headings = [];
        foreach ($def->headings as $i => $d) {
            $h = isset($o->headings[$i]) ? clone $o->headings[$i] : new HeadingStyle;
            if ($h->size == 0) { $h->size = $d->size; }
            if ($h->weight === 0) { $h->weight = $d->weight; }
            $h->color ??= $o->text;
            if ($h->top == 0) { $h->top = $d->top; }
            if ($h->bottom == 0) { $h->bottom = $d->bottom; }
            $headings[] = $h;
        }
        $o->headings = $headings;
        if ($o->indent == 0) { $o->indent = $def->indent; }
        $o->marker ??= $o->text;
        $o->checkbox ??= $def->checkbox;
        $o->checkmark ??= $def->checkmark;
        $o->quoteBar ??= $def->quoteBar;
        $o->quoteText ??= $o->muted;
        if ($o->quoteBarWidth == 0) { $o->quoteBarWidth = $def->quoteBarWidth; }
        return $o;
    }

    /** What tells two looks apart, for the layouts made in one. @internal */
    public function key(): string { return serialize($this); }
}
