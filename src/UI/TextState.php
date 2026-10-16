<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * The text of an input field and its selection, kept by the app across
 * frames. Offsets are bytes into UTF-8 $text; the cursor is the moving end
 * of the selection and the anchor the fixed one (equal when nothing is
 * selected). While an input method composes, $marked holds the text under
 * composition, shown at the cursor but not yet in $text. Edits are
 * undoable: typing within a second coalesces into one step.
 */
final class TextState
{
    public int $cursor = 0;
    public int $anchor = 0;
    public ?string $marked = null;
    /** Byte offset of the caret inside $marked while composing. */
    public int $markedCaret = 0;
    /** Set for a frame when Enter was pressed in the field. */
    public bool $submitted = false;
    /** A text area: Enter inserts a line, Up/Down and line ends go by the wrapped lines (Runtime handles them). */
    public bool $multiline = false;
    /** How far a text area is scrolled; the wheel moves it, the painter clamps it and brings a moved caret into view. */
    public float $scrollY = 0;
    /** How far through its text a text area is scrolled, 0..1 (the painter sets it), for anything that follows it. */
    public float $scrollFraction = 0;
    /** The caret the painter last brought into view: text length and cursor. */
    public array $caretSeen = [-1, -1];
    private const UNDO_DEPTH = 200;
    /** @var list<array{0: string, 1: int, 2: int}> */
    private array $undo = [];
    private array $redo = [];
    private string $lastEdit = '';
    private float $lastEditAt = 0;
    private ?TextLayout $layout = null;

    public function __construct(public string $text = '')
    {
        $this->cursor = $this->anchor = strlen($text);
    }

    public function hasSelection(): bool
    {
        return $this->cursor !== $this->anchor;
    }

    /** @return array{0: int, 1: int} selection start and end, ordered */
    public function selection(): array
    {
        return [min($this->cursor, $this->anchor), max($this->cursor, $this->anchor)];
    }

    public function selectedText(): string
    {
        [$a, $b] = $this->selection();
        return substr($this->text, $a, $b - $a);
    }

    /** What the field shows: the text with the composition at the cursor. */
    public function display(): string
    {
        return $this->marked === null ? $this->text : substr($this->text, 0, $this->cursor) . $this->marked . substr($this->text, $this->cursor);
    }

    /** Where the caret is in display(), in bytes. */
    public function displayCaret(): int
    {
        return $this->marked === null ? $this->cursor : $this->cursor + $this->markedCaret;
    }

    public function insert(string $s): void
    {
        $this->marked = null;
        [$a, $b] = $this->selection();
        $this->snapshot($s === '' ? 'delete' : ($a === $b && !str_contains($s, "\n") ? 'type' : 'edit'));
        $this->text = substr($this->text, 0, $a) . $s . substr($this->text, $b);
        $this->cursor = $this->anchor = $a + strlen($s);
    }

    public function setMarked(string $s, int $caretBytes): void
    {
        if ($this->hasSelection()) {
            [$a, $b] = $this->selection();
            $this->snapshot('edit');
            $this->text = substr($this->text, 0, $a) . substr($this->text, $b);
            $this->cursor = $this->anchor = $a;
        }
        $this->marked = $s === '' ? null : $s;
        $this->markedCaret = $caretBytes;
    }

    public function selectAll(): void
    {
        $this->anchor = 0;
        $this->cursor = strlen($this->text);
    }

    public function setCursor(int $byte, bool $extend = false): void
    {
        $this->marked = null;
        $this->cursor = max(0, min(strlen($this->text), $byte));
        if (!$extend) {
            $this->anchor = $this->cursor;
        }
    }

    /** Remembers the text before an edit of kind $kind; consecutive typing or deleting within a second is one step. */
    private function snapshot(string $kind): void
    {
        $now = microtime(true);
        if ($kind !== 'edit' && $kind === $this->lastEdit && $now - $this->lastEditAt < 1.0) {
            $this->lastEditAt = $now;
            return;
        }
        $this->undo[] = [$this->text, $this->cursor, $this->anchor];
        if (count($this->undo) > self::UNDO_DEPTH) {
            array_shift($this->undo);
        }
        $this->redo = [];
        $this->lastEdit = $kind;
        $this->lastEditAt = $now;
    }

    public function undo(): void
    {
        if ($this->undo === []) {
            return;
        }
        $this->redo[] = [$this->text, $this->cursor, $this->anchor];
        [$this->text, $this->cursor, $this->anchor] = array_pop($this->undo);
        $this->marked = null;
        $this->lastEdit = '';
    }

    public function redo(): void
    {
        if ($this->redo === []) {
            return;
        }
        $this->undo[] = [$this->text, $this->cursor, $this->anchor];
        [$this->text, $this->cursor, $this->anchor] = array_pop($this->redo);
        $this->marked = null;
        $this->lastEdit = '';
    }

    /** The wrapped lines of display() at $width, kept while the text, size and width stay. */
    public function layout(TextMetrics $m, float $size, float $width): TextLayout
    {
        $display = $this->display();
        $l = $this->layout;
        if ($l === null || $l->text !== $display || $l->size !== $size || $l->width !== $width) {
            $l = $this->layout = TextLayout::wrap($m, $display, $size, $width);
        }
        return $l;
    }

    /** Applies a text system command (doCommandBySelector:). Returns whether it was one. */
    public function command(string $selector): bool
    {
        $extend = str_ends_with($selector, 'AndModifySelection:');
        $base = $extend ? substr($selector, 0, -strlen('AndModifySelection:')) . ':' : $selector;
        if ($this->multiline && in_array($base, self::BY_LINE, true)) {
            return false; // Runtime answers these from the wrapped lines
        }
        switch ($base) {
            case 'moveToBeginningOfParagraph:':
                $nl = strrpos(substr($this->text, 0, $this->cursor), "\n");
                $this->setCursor($nl === false ? 0 : $nl + 1, $extend); return true;
            case 'moveToEndOfParagraph:':
                $nl = strpos($this->text, "\n", $this->cursor);
                $this->setCursor($nl === false ? strlen($this->text) : $nl, $extend); return true;
            case 'moveLeft:': case 'moveBackward:':
                if (!$extend && $this->hasSelection()) { $this->setCursor($this->selection()[0]); } else { $this->setCursor($this->prev($this->cursor), $extend); }
                return true;
            case 'moveRight:': case 'moveForward:':
                if (!$extend && $this->hasSelection()) { $this->setCursor($this->selection()[1]); } else { $this->setCursor($this->next($this->cursor), $extend); }
                return true;
            case 'moveWordLeft:': case 'moveWordBackward:': $this->setCursor($this->wordLeft($this->cursor), $extend); return true;
            case 'moveWordRight:': case 'moveWordForward:': $this->setCursor($this->wordRight($this->cursor), $extend); return true;
            case 'moveToBeginningOfLine:': case 'moveToLeftEndOfLine:': case 'moveToBeginningOfDocument:': case 'moveUp:':
                $this->setCursor(0, $extend); return true;
            case 'moveToEndOfLine:': case 'moveToRightEndOfLine:': case 'moveToEndOfDocument:': case 'moveDown:':
                $this->setCursor(strlen($this->text), $extend); return true;
            case 'deleteBackward:':
                if (!$this->hasSelection()) { $this->anchor = $this->prev($this->cursor); }
                $this->insert(''); return true;
            case 'deleteForward:':
                if (!$this->hasSelection()) { $this->anchor = $this->next($this->cursor); }
                $this->insert(''); return true;
            case 'deleteWordBackward:':
                if (!$this->hasSelection()) { $this->anchor = $this->wordLeft($this->cursor); }
                $this->insert(''); return true;
            case 'deleteWordForward:':
                if (!$this->hasSelection()) { $this->anchor = $this->wordRight($this->cursor); }
                $this->insert(''); return true;
            case 'deleteToBeginningOfLine:':
                if (!$this->hasSelection()) { $this->anchor = 0; }
                $this->insert(''); return true;
            case 'selectAll:': $this->selectAll(); return true;
            case 'insertNewline:': $this->submitted = true; return true;
        }
        return false;
    }

    /** Commands a text area leaves to Runtime, which knows its wrapped lines. */
    public const BY_LINE = ['moveUp:', 'moveDown:', 'moveToBeginningOfLine:', 'moveToLeftEndOfLine:', 'moveToEndOfLine:', 'moveToRightEndOfLine:', 'deleteToBeginningOfLine:', 'insertNewline:', 'pageUp:', 'pageDown:'];

    /** Byte offset of the previous UTF-8 character boundary. */
    public function prev(int $i): int
    {
        if ($i <= 0) { return 0; }
        $i--;
        while ($i > 0 && (ord($this->text[$i]) & 0xC0) === 0x80) { $i--; }
        return $i;
    }

    public function next(int $i): int
    {
        $n = strlen($this->text);
        if ($i >= $n) { return $n; }
        $i++;
        while ($i < $n && (ord($this->text[$i]) & 0xC0) === 0x80) { $i++; }
        return $i;
    }

    /** Selects the word at the caret (a run without spaces), as a double click does. */
    public function selectWord(): void
    {
        $n = strlen($this->text); $a = $b = $this->cursor;
        while ($a > 0 && !ctype_space($this->text[$a - 1])) { $a--; }
        while ($b < $n && !ctype_space($this->text[$b])) { $b++; }
        $this->anchor = $a; $this->cursor = $b;
    }

    /** Selects the line at the caret (between newlines), as a triple click does. */
    public function selectLine(): void
    {
        $a = strrpos(substr($this->text, 0, $this->cursor), "\n");
        $b = strpos($this->text, "\n", $this->cursor);
        $this->anchor = $a === false ? 0 : $a + 1; $this->cursor = $b === false ? strlen($this->text) : $b;
    }

    private function wordLeft(int $i): int
    {
        while ($i > 0 && ctype_space($this->text[$i - 1])) { $i--; }
        while ($i > 0 && !ctype_space($this->text[$i - 1])) { $i--; }
        return $i;
    }

    private function wordRight(int $i): int
    {
        $n = strlen($this->text);
        while ($i < $n && !ctype_space($this->text[$i])) { $i++; }
        while ($i < $n && ctype_space($this->text[$i])) { $i++; }
        return $i;
    }

    /** UTF-16 unit index of a byte offset in $s. */
    public static function utf16Index(string $s, int $byte): int
    {
        return intdiv(strlen(mb_convert_encoding(substr($s, 0, $byte), 'UTF-16LE', 'UTF-8')), 2);
    }

    /** Byte offset of a UTF-16 unit index in $s. */
    public static function byteIndex(string $s, int $u16): int
    {
        $units = 0; $i = 0; $n = strlen($s);
        while ($i < $n && $units < $u16) {
            $c = ord($s[$i]);
            $len = $c < 0x80 ? 1 : ($c < 0xE0 ? 2 : ($c < 0xF0 ? 3 : 4));
            $units += $len === 4 ? 2 : 1;
            $i += $len;
        }
        return min($i, $n);
    }
}
