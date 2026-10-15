<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * The text of an input field and its selection, kept by the app across
 * frames. Offsets are bytes into UTF-8 $text; the cursor is the moving end
 * of the selection and the anchor the fixed one (equal when nothing is
 * selected). While an input method composes, $marked holds the text under
 * composition, shown at the cursor but not yet in $text.
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
        $this->text = substr($this->text, 0, $a) . $s . substr($this->text, $b);
        $this->cursor = $this->anchor = $a + strlen($s);
    }

    public function setMarked(string $s, int $caretBytes): void
    {
        if ($this->hasSelection()) {
            [$a, $b] = $this->selection();
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

    /** Applies a text system command (doCommandBySelector:). Returns whether it was one. */
    public function command(string $selector): bool
    {
        $extend = str_ends_with($selector, 'AndModifySelection:');
        $base = $extend ? substr($selector, 0, -strlen('AndModifySelection:')) . ':' : $selector;
        switch ($base) {
            case 'moveLeft:': case 'moveBackward:':
                if (!$extend && $this->hasSelection()) { $this->setCursor($this->selection()[0]); } else { $this->setCursor($this->prev($this->cursor), $extend); }
                return true;
            case 'moveRight:': case 'moveForward:':
                if (!$extend && $this->hasSelection()) { $this->setCursor($this->selection()[1]); } else { $this->setCursor($this->next($this->cursor), $extend); }
                return true;
            case 'moveWordLeft:': case 'moveWordBackward:': $this->setCursor($this->wordLeft($this->cursor), $extend); return true;
            case 'moveWordRight:': case 'moveWordForward:': $this->setCursor($this->wordRight($this->cursor), $extend); return true;
            case 'moveToBeginningOfLine:': case 'moveToLeftEndOfLine:': case 'moveToBeginningOfDocument:': case 'moveToBeginningOfParagraph:': case 'moveUp:':
                $this->setCursor(0, $extend); return true;
            case 'moveToEndOfLine:': case 'moveToRightEndOfLine:': case 'moveToEndOfDocument:': case 'moveToEndOfParagraph:': case 'moveDown:':
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
