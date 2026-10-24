<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

use MyPHP\Text\Layout;
use MyPHP\UI\{Context, InputEvent, Key, Menu};
use MyPHP\UI\Editor\{Rope, RopeText};

/**
 * The editor's input as it comes: keys, text, edit commands, the pointer
 * and the wheel (mygo's richtext input.go), as macOS has them.
 *
 * @internal part of View
 */
trait ViewInput
{
    /** The Control keys of macOS text views that act as other keys. */
    private const EMACS_KEYS = [Key::B => Key::LEFT, Key::F => Key::RIGHT, Key::P => Key::UP, Key::N => Key::DOWN, Key::H => Key::DELETE, Key::D => Key::FORWARD_DELETE];
    private const SCROLLBAR_WIDTH = 10;

    /** Takes the editor's input as it comes. */
    public function input(InputEvent $ev): bool
    {
        $this->sync();
        try {
            switch ($ev->kind) {
                case InputEvent::KEY_DOWN:
                    if ($this->key($ev->key, $ev->mods & Key::MODIFIERS)) {
                        $this->blinkStart = $this->now();
                        return true;
                    }
                    return false;
                case InputEvent::TEXT:
                    if ($this->opts->readOnly || $ev->text === '') { return false; }
                    $this->compose = '';
                    $this->insert($ev->text);
                    $this->typed($ev->text);
                    $this->blinkStart = $this->now();
                    return true;
                case InputEvent::COMMAND:
                    return $this->editCommand($ev->text);
                case InputEvent::POINTER_DOWN:
                    return $this->press($ev);
                case InputEvent::POINTER_MOVE:
                    return $this->move($ev);
                case InputEvent::POINTER_UP:
                    return $this->release();
                case InputEvent::SCROLL:
                    $before = $this->scroll;
                    $this->setScroll(max(0.0, min($this->scroll + $ev->dy, $this->hs->top(count($this->paras)) - $this->viewH)));
                    // At an end, the wheel goes on to the containers around.
                    return $this->scroll !== $before;
            }
            return false;
        } finally {
            $this->publish();
        }
    }

    /** The runtime's clock, nanoseconds. */
    private function now(): int { return $this->services?->now ?? hrtime(true); }

    /** Puts the caret at $i, extending the selection or not. */
    private function moveTo(int $i, int $affinity, bool $extend): void
    {
        $i = max(0, min($i, $this->shown->len()));
        $this->caret = $i; $this->affinity = $affinity;
        if (!$extend) { $this->selAnchor = $i; }
        $this->coalesce = false;
        $this->reveal = true;
    }

    /** Rune $i of the text shown. */
    private function runeAt(int $i): string
    {
        $t = new RopeText($this->shown->text);
        return $t->runeAt(Rope::byteOfRune($this->shown->text, $i))[0];
    }

    private static function isWord(string $r): bool { return $r === '_' || preg_match('/^[\pL\pN]$/u', $r) === 1; }

    /** The end of the word after $i. */
    private function nextWord(int $i): int
    {
        $n = $this->shown->len(); $word = false;
        for (; $i < $n; $i++) {
            if (self::isWord($this->runeAt($i))) {
                $word = true;
            } elseif ($word) {
                break;
            }
        }
        return $i;
    }

    /** The start of the word before $i. */
    private function prevWord(int $i): int
    {
        $word = false;
        for (; $i > 0; $i--) {
            if (self::isWord($this->runeAt($i - 1))) {
                $word = true;
            } elseif ($word) {
                break;
            }
        }
        return $i;
    }

    /** The word, or the run of other runes, around $i, within its paragraph. @return array{0: int, 1: int} */
    private function wordAt(int $i): array
    {
        $n = $this->shown->len();
        if ($n === 0) { return [0, 0]; }
        $i = max(0, min($i, $n - 1));
        $word = self::isWord($this->runeAt($i));
        $a = $z = $i;
        while ($a > 0) {
            $r = $this->runeAt($a - 1);
            if ($r === "\n" || self::isWord($r) !== $word) { break; }
            $a--;
        }
        while ($z < $n) {
            $r = $this->runeAt($z);
            if ($r === "\n" || self::isWord($r) !== $word) { break; }
            $z++;
        }
        return [$a, $z];
    }

    /** The edge of the grapheme before $i ($dir < 0) or after it: a newline is one. */
    private function grapheme(int $i, int $dir): int
    {
        $n = $this->shown->len();
        $p = $this->shown->paraAt($i);
        $ps = $this->shown->paraStart($p);
        $pe = $ps + $this->para($p)->n;
        if ($dir < 0 && $i === $ps) { return max($i - 1, 0); }
        if ($dir > 0 && $i >= $pe) { return min($i + 1, $n); }
        $pv = $this->paraLayout($p);
        $u = min($this->local($p, $i), $pv->layout->len());
        $u = $dir < 0 ? $pv->layout->previousBoundary($u) : $pv->layout->nextBoundary($u);
        return $this->global($p, $u);
    }

    /** The caret one grapheme left ($dir < 0) or right on screen, across bidi runs, and to the next paragraph at the edge of one. @return array{0: int, 1: int} */
    private function visual(int $dir): array
    {
        $p = $this->shown->paraAt($this->caret);
        $pv = $this->paraLayout($p);
        $l = min($this->local($p, $this->caret), $pv->layout->len());
        [$i, $aff] = $pv->layout->moveCaret($l, $this->affinity, $dir);
        if ($i === $l) {
            if ($dir < 0) { return [max($this->shown->paraStart($p) - 1, 0), Layout::DOWNSTREAM]; }
            return [min($this->shown->paraStart($p) + $this->para($p)->n + 1, $this->shown->len()), Layout::DOWNSTREAM];
        }
        return [$this->global($p, $i), $aff];
    }

    /** Moves the caret up or down lines, keeping its x. */
    private function vertical(int $lines, bool $extend): void
    {
        [$x, $y, $h] = $this->caretAt($this->caret, 0, $this->affinity);
        if (!$this->hasDesired) { $this->desiredX = $x; $this->hasDesired = true; }
        $target = $y + $h / 2 + $lines * $h;
        if ($target < 0) {
            $this->moveTo(0, Layout::DOWNSTREAM, $extend);
        } elseif ($target > $this->hs->top(count($this->paras))) {
            $this->moveTo($this->shown->len(), Layout::DOWNSTREAM, $extend);
        } else {
            [$i, $a] = $this->positionAt($this->desiredX, $target);
            $this->moveTo($i, $a, $extend);
        }
        $this->hasDesired = true;
    }

    /** Handles a key pressed, and reports whether it took it. */
    private function key(int $key, int $mods): bool
    {
        if (($cmd = self::shortcut($key, $mods)) !== null) { return $this->run($cmd); }
        $shift = ($mods & Key::SHIFT) !== 0;
        $m = $mods & ~Key::SHIFT;
        if ($m === Key::CONTROL) {
            if (isset(self::EMACS_KEYS[$key])) {
                $key = self::EMACS_KEYS[$key]; $m = 0;
            } else {
                $p = $this->shown->paraAt($this->caret);
                $ps = $this->shown->paraStart($p);
                $pe = $ps + $this->para($p)->n;
                switch ($key) {
                    case Key::A:
                        $this->moveTo($ps, Layout::DOWNSTREAM, $shift);
                        return true;
                    case Key::E:
                        $this->moveTo($pe, Layout::DOWNSTREAM, $shift);
                        return true;
                    case Key::K:
                        if ($this->opts->readOnly) { return true; }
                        if ($pe === $this->caret && $pe < $this->shown->len()) { $pe++; }
                        $this->deleteRange($this->caret, $pe, false);
                        return true;
                }
            }
        }
        $word = $m === Key::OPTION;
        if ($key === Key::V && $shift && ($m === Key::COMMAND || $m === (Key::COMMAND | Key::OPTION))) {
            // Paste and Match Style: ⇧⌥⌘V, and ⇧⌘V as Notes has it.
            return $this->editCommand('pasteAndMatchStyle');
        }
        [$a, $z] = $this->sel();
        $n = $this->shown->len();
        switch ($key) {
            case Key::LEFT: case Key::RIGHT:
                $left = $key === Key::LEFT;
                if ($m === Key::COMMAND) {
                    [$s, $e] = $this->lineEdges($this->caret);
                    $left ? $this->moveTo($s, Layout::DOWNSTREAM, $shift) : $this->moveTo($e, Layout::UPSTREAM, $shift);
                } elseif ($word) {
                    $this->moveTo($left ? $this->prevWord($this->caret) : $this->nextWord($this->caret), Layout::DOWNSTREAM, $shift);
                } elseif ($a !== $z && !$shift) {
                    $this->moveTo($left ? $a : $z, Layout::DOWNSTREAM, false);
                } else {
                    [$i, $aff] = $this->visual($left ? -1 : 1);
                    $this->moveTo($i, $aff, $shift);
                }
                $this->hasDesired = false;
                return true;
            case Key::UP: case Key::DOWN:
                if ($m === Key::COMMAND) {
                    $this->moveTo($key === Key::UP ? 0 : $n, Layout::DOWNSTREAM, $shift);
                    return true;
                }
                if ($m === Key::OPTION) {
                    // By paragraphs: up to the start of this one or the one before; down to the end of this one or the next, as NSTextView.
                    [$pa, $pz] = $this->shown->paragraphRange($this->caret);
                    if ($key === Key::UP && $this->caret === $pa && $pa > 0) {
                        [$pa] = $this->shown->paragraphRange($pa - 1);
                        $this->moveTo($pa, Layout::DOWNSTREAM, $shift);
                    } elseif ($key === Key::UP) {
                        $this->moveTo($pa, Layout::DOWNSTREAM, $shift);
                    } elseif ($this->caret === $pz && $pz < $n) {
                        [, $pz] = $this->shown->paragraphRange($pz + 1);
                        $this->moveTo($pz, Layout::UPSTREAM, $shift);
                    } else {
                        $this->moveTo($pz, Layout::UPSTREAM, $shift);
                    }
                    $this->hasDesired = false;
                    return true;
                }
                if ($m !== 0) { return false; }
                $this->vertical($key === Key::UP ? -1 : 1, $shift);
                return true;
            case Key::PAGE_UP: case Key::PAGE_DOWN:
                $lines = max((int)($this->viewH / ($this->look->size * 1.3)) - 1, 1);
                $this->vertical($key === Key::PAGE_UP ? -$lines : $lines, $shift);
                return true;
            case Key::HOME: case Key::END:
                if ($m === Key::CONTROL) {
                    $this->moveTo($key === Key::HOME ? 0 : $n, Layout::DOWNSTREAM, $shift);
                    return true;
                }
                [$s, $e] = $this->lineEdges($this->caret);
                $key === Key::HOME ? $this->moveTo($s, Layout::DOWNSTREAM, $shift) : $this->moveTo($e, Layout::UPSTREAM, $shift);
                return true;
            case Key::A:
                if ($m === Key::COMMAND) {
                    $this->selAnchor = 0; $this->caret = $n;
                    return true;
                }
                break;
            case Key::C: case Key::X: case Key::V: case Key::Z: case Key::Y:
                if ($m !== Key::COMMAND) { break; }
                return match ($key) {
                    Key::C => $this->editCommand('copy'),
                    Key::X => $this->editCommand('cut'),
                    Key::V => $this->editCommand('paste'),
                    Key::Z => $this->editCommand($shift ? 'redo' : 'undo'),
                    Key::Y => $this->editCommand('redo'),
                };
        }
        if ($this->opts->readOnly) { return false; }
        $p = $this->shown->paraAt($this->caret);
        $st = $this->para($p)->style;
        $ps = $this->shown->paraStart($p);
        switch ($key) {
            case Key::DELETE: // Backspace
                if ($a !== $z) {
                    $this->deleteRange($a, $z, false);
                } elseif ($m === 0 && $this->caret === $ps && self::special($st)) {
                    // At the start of a list's item, a heading, a quotation or a block of code, Backspace takes the style away before joining.
                    $this->command(fn(Document $d) => $d->formatParagraphs(self::unstyle(...)));
                } elseif ($m === Key::COMMAND) {
                    [$s] = $this->lineEdges($this->caret);
                    $this->deleteRange($s, $this->caret, false);
                } elseif ($word) {
                    $this->deleteRange($this->prevWord($this->caret), $this->caret, false);
                } elseif ($this->caret > 0) {
                    $this->deleteRange($this->grapheme($this->caret, -1), $this->caret, true);
                }
                return true;
            case Key::FORWARD_DELETE:
                if ($a !== $z) {
                    $this->deleteRange($a, $z, false);
                } elseif ($word) {
                    $this->deleteRange($this->caret, $this->nextWord($this->caret), false);
                } elseif ($this->caret < $n) {
                    $this->deleteRange($this->caret, $this->grapheme($this->caret, 1), false);
                }
                return true;
            case Key::RETURN: case Key::ENTER:
                if ($m !== 0) { return false; }
                $this->enter();
                return true;
            case Key::TAB:
                if ($m !== 0) { return false; }
                if ($st->list !== ListKind::NoList) { return $this->run($shift ? 'outdent' : 'indent'); }
                if ($st->codeBlock && !$shift) {
                    $this->insert("\t");
                    return true;
                }
                return false;
        }
        return false;
    }

    /**
     * The command a key with $mods runs: ⌘B, ⌘I, ⌘U, ⇧⌘X, ⌘E, ⌘K, ⇧⌘7, ⇧⌘8,
     * ⇧⌘L, ⌘], ⌘[, ⇧⌘{, ⇧⌘|, ⇧⌘} and ⌥⌘0–6, as Pages, Notes and Google
     * Docs have them, or null.
     */
    private static function shortcut(int $key, int $mods): ?string
    {
        if ($mods === Key::COMMAND) {
            return match ($key) {
                Key::B => 'bold', Key::I => 'italic', Key::U => 'underline', Key::K => 'link',
                Key::BRACKET_RIGHT => 'indent', Key::BRACKET_LEFT => 'outdent', Key::E => 'code',
                default => null,
            };
        }
        if ($mods === (Key::COMMAND | Key::SHIFT)) {
            return match ($key) {
                Key::X => 'strikethrough', Key::N7 => 'numbers', Key::N8 => 'bullets', Key::L => 'tasks',
                Key::BRACKET_LEFT => 'alignLeft', Key::BRACKET_RIGHT => 'alignRight', Key::BACKSLASH => 'alignCenter',
                default => null,
            };
        }
        if ($mods === (Key::COMMAND | Key::OPTION)) {
            $digits = [Key::N0 => 0, Key::N1 => 1, Key::N2 => 2, Key::N3 => 3, Key::N4 => 4, Key::N5 => 5, Key::N6 => 6];
            if (isset($digits[$key])) { return 'heading' . $digits[$key]; }
        }
        return null;
    }

    /** Performs an edit command of a menu or a shortcut. */
    private function editCommand(string $name): bool
    {
        [$a, $z] = $this->sel();
        switch ($name) {
            case 'copy':
                if ($a !== $z) { $this->copySelection(); }
                return true;
            case 'cut':
                if ($a !== $z && !$this->opts->readOnly) {
                    $this->copySelection();
                    $this->deleteRange($a, $z, false);
                }
                return true;
            case 'paste': case 'pasteAndMatchStyle':
                if (!$this->opts->readOnly) { $this->paste($name === 'pasteAndMatchStyle'); }
                return true;
            case 'selectAll':
                $this->selAnchor = 0; $this->caret = $this->shown->len();
                return true;
            case 'delete':
                if ($a !== $z && !$this->opts->readOnly) { $this->deleteRange($a, $z, false); }
                return true;
            case 'undo': case 'redo':
                if (!$this->opts->readOnly) {
                    $this->commitCompose();
                    $this->takeBack($name === 'redo');
                }
                return true;
        }
        return $this->run($name);
    }

    /** Handles a press of the pointer. */
    private function press(InputEvent $ev): bool
    {
        if ($ev->button !== 0) { return false; }
        $this->pressLink = '';
        if ($this->overScrollbar($ev->x)) {
            $this->thumb = true;
            [$top, $size] = $this->thumbBox();
            if ($ev->y >= $top && $ev->y < $top + $size) {
                $this->thumbGrab = $ev->y - $top;
            } else {
                $this->thumbGrab = $size / 2;
                $this->dragThumb($ev->y);
            }
            return true;
        }
        $this->commitCompose();
        [$x, $y] = $this->contentPoint($ev->x, $ev->y);
        [$p, $box] = $this->taskBox($x, $y);
        if ($box && $ev->clicks === 1 && !$this->opts->readOnly) {
            $start = $this->shown->paraStart($p);
            $this->command(fn(Document $d) => $d->toggleChecked($start));
            return true;
        }
        [$i, $aff] = $this->positionAt($x, $y);
        $shift = ($ev->mods & Key::SHIFT) !== 0;
        if ($ev->clicks === 1 && !$shift && $i < $this->shown->len()) {
            $this->pressLink = $this->shown->attrsAt($i)->link;
        }
        $this->dragging = true;
        $this->hasDesired = false;
        $this->dragUnit = min(max($ev->clicks, 1), 3);
        if ($this->dragUnit === 1) {
            $this->moveTo($i, $aff, $shift);
        } elseif ($this->dragUnit === 2) {
            [$this->selAnchor, $this->caret] = $this->wordAt($i);
        } else {
            [$pa, $pz] = $this->shown->paragraphRange($i);
            $this->selAnchor = $pa; $this->caret = min($pz + 1, $this->shown->len());
        }
        $this->dragStart = [$this->selAnchor, $this->caret];
        $this->coalesce = false;
        return true;
    }

    /** Handles the pointer moving: a drag extends the selection. */
    private function move(InputEvent $ev): bool
    {
        if ($this->thumb) {
            $this->dragThumb($ev->y);
            return true;
        }
        [$x, $y] = $this->contentPoint($ev->x, $ev->y);
        if (!$this->dragging) {
            $cursor = \MyPHP\UI\Cursor::Text;
            if ($this->taskBox($x, $y)[1] || $this->overScrollbar($ev->x)) {
                $cursor = \MyPHP\UI\Cursor::Default;
            } else {
                [$i] = $this->positionAt($x, $y);
                if ($i < $this->shown->len() && $this->shown->attrsAt($i)->link !== '') { $cursor = \MyPHP\UI\Cursor::Pointer; }
            }
            if ($cursor !== $this->cursor) {
                $this->cursor = $cursor;
                return true;
            }
            return false;
        }
        // Near the edges, a drag scrolls.
        if ($ev->y < $this->padY) {
            $this->setScroll(max(0.0, $this->scroll - ($this->padY - $ev->y)));
        } elseif ($ev->y > $this->padY + $this->viewH) {
            $this->setScroll($this->scroll + ($ev->y - $this->padY - $this->viewH));
        }
        [$i, $aff] = $this->positionAt($x, $y);
        if ($this->dragUnit === 2) {
            [$s, $e] = $this->wordAt($i);
            if ($i < $this->dragStart[0]) {
                $this->selAnchor = $this->dragStart[1]; $this->caret = $s;
            } else {
                $this->selAnchor = $this->dragStart[0]; $this->caret = max($e, $this->dragStart[1]);
            }
        } elseif ($this->dragUnit === 3) {
            [$pa, $pz] = $this->shown->paragraphRange($i);
            if ($i < $this->dragStart[0]) {
                $this->selAnchor = $this->dragStart[1]; $this->caret = $pa;
            } else {
                $this->selAnchor = $this->dragStart[0]; $this->caret = min($pz + 1, $this->shown->len());
            }
        } else {
            $this->caret = $i; $this->affinity = $aff;
        }
        if ($i !== $this->dragStart[0] || $this->dragUnit !== 1) { $this->pressLink = ''; }
        $this->reveal = true;
        return true;
    }

    /** Ends a drag, and opens the link a click began on. */
    private function release(): bool
    {
        if ($this->thumb) {
            $this->thumb = false;
            return true;
        }
        if (!$this->dragging) { return false; }
        $this->dragging = false;
        $link = $this->pressLink;
        $this->pressLink = '';
        if ($link !== '' && $this->caret === $this->selAnchor) {
            if ($this->opts->onLink !== null) {
                ($this->opts->onLink)($link);
            } elseif (Context::safeToOpen($link) && $this->services !== null) {
                $this->services->openURLThen($link);
            }
        }
        return true;
    }

    /** Whether ($x, $y), in the content, is on the check box of a task, and which paragraph's. @return array{0: int, 1: bool} */
    private function taskBox(float $x, float $y): array
    {
        if ($this->paras === []) { return [0, false]; }
        $p = $this->hs->at($y);
        $st = $this->para($p)->style;
        if ($st->list !== ListKind::Tasks) { return [0, false]; }
        [$left] = $this->insets($st);
        if ($x < $left - $this->look->indent || $x >= $left - 2) { return [$p, false]; }
        // Beside the first line, where the box is.
        [, $top, $h] = $this->caretAt($this->shown->paraStart($p), 0, Layout::DOWNSTREAM);
        return [$p, $y >= $top && $y < $top + $h];
    }

    /** Whether $x of the element is on the scroll bar. */
    private function overScrollbar(float $x): bool
    {
        return $this->hs->top(count($this->paras)) > $this->viewH && $x >= $this->rect[2] - self::SCROLLBAR_WIDTH;
    }

    /** The top and the height of the scroll bar's thumb, in the element. @return array{0: float, 1: float} */
    private function thumbBox(): array
    {
        $total = $this->hs->top(count($this->paras));
        $track = $this->rect[3] - 4;
        $size = max(24.0, $track * $this->viewH / max($total, 1.0));
        $room = $track - $size;
        $at = ($max = $total - $this->viewH) > 0 ? $this->scroll / $max : 0.0;
        return [2 + $room * $at, $size];
    }

    /** Scrolls as the thumb is dragged to $y. */
    private function dragThumb(float $y): void
    {
        [, $size] = $this->thumbBox();
        $track = $this->rect[3] - 4 - $size;
        $total = $this->hs->top(count($this->paras)) - $this->viewH;
        if ($track <= 0 || $total <= 0) { return; }
        $f = ($y - $this->thumbGrab - 2) / $track;
        $this->setScroll(max(0.0, min($f, 1.0)) * $total);
    }

    /** Builds the editor's context menu: the editing items of a text input (mygo's EditItems). */
    public function menu(Menu $m): void
    {
        $t = \MyPHP\L10n::in(\MyPHP\App::locale());
        [$a, $z] = $this->sel();
        $ro = $this->opts->readOnly;
        $items = [
            [$m->item($t->get('menuCut'))->disabled($a === $z || $ro), 'cut'],
            [$m->item($t->get('menuCopy'))->disabled($a === $z), 'copy'],
            [$m->item($t->get('menuPaste'))->disabled($ro), 'paste'],
        ];
        $m->separator();
        $items[] = [$m->item($t->get('menuSelectAll'))->disabled($this->shown->len() === 0), 'selectAll'];
        foreach ($items as [$it, $cmd]) {
            if ($it->chosen()) {
                $this->editCommand($cmd);
                $this->publish();
            }
        }
    }
}
