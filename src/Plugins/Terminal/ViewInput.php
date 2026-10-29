<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

use MyPHP\Plugins\Terminal\Vt\{Gesture, Key as VtKey, KeyEncoder, KeyEvent, MouseEncoder, Terminal as VtTerminal};
use MyPHP\Transfer\{Data, Format};
use MyPHP\UI\{InputEvent, Key};

/**
 * The terminal's input as it comes (mygo's view.go): keys, text, edit
 * commands, the pointer and the wheel.
 *
 * @internal part of View
 */
trait ViewInput
{
    /** Takes the view's input as it comes. */
    public function input(InputEvent $ev): bool
    {
        switch ($ev->kind) {
            case InputEvent::KEY_DOWN:
                return $this->keyDown($ev);
            case InputEvent::KEY_UP:
                return $this->keyUp($ev);
            case InputEvent::TEXT:
                $this->typed($ev->text);
                return true;
            case InputEvent::COMMAND:
                switch ($ev->text) {
                    case 'copy': $this->copy(false); return true;
                    case 'paste': $this->paste(); return true;
                    case 'selectAll': $this->selectAll(); return true;
                }
                return false;
            case InputEvent::POINTER_DOWN:
            case InputEvent::POINTER_UP:
            case InputEvent::POINTER_MOVE:
                $took = $this->pointerEvent($ev);
                if (($link = $this->opening) !== '') {
                    $this->opening = '';
                    $open = $this->t->opts->openLink;
                    if (($open === null || !$open($link)) && !$this->openingPath && $this->services !== null) {
                        $this->services->openURLThen($link);
                    }
                }
                if (($text = $this->copying) !== '') {
                    $this->copying = '';
                    $this->writeClipboard($text);
                }
                if (($a = $this->rightClick) !== RightClickAction::RightClickContextMenu) {
                    $this->rightClick = RightClickAction::RightClickContextMenu;
                    switch ($a) {
                        case RightClickAction::RightClickCopy: $this->copy(true); break;
                        case RightClickAction::RightClickCopyOrPaste: if (!$this->copy(true)) { $this->paste(); } break;
                        case RightClickAction::RightClickPaste: $this->clearSelection(); $this->paste(); break;
                        default: break;
                    }
                }
                return $took;
            case InputEvent::SCROLL:
                return $this->scroll($ev);
        }
        return false;
    }

    /** Taken, the key goes no further: a key that types text is left for the system's input methods, whose text comes as typed. */
    private function keyDown(InputEvent $ev): bool
    {
        $t = $this->t;
        if ($this->preedit !== '') {
            return false; // a key typed while an input method composes is the input method's, as Enter choosing a candidate
        }
        $mods = $ev->mods & Key::MODIFIERS;
        $key = $ev->key;
        if (($on = $t->opts->onKey) !== null && $on($mods, $key)) {
            $this->pending = ['key' => Keys::vt($key)[0], 'unshifted' => 0, 'mods' => 0, 'repeat' => false, 'taken' => true];
            return true;
        }
        if (($b = $t->binding($mods, $key)) !== null) {
            if ($this->perform($b->action, $b->performable) || !$b->performable) {
                // Its text and release are the binding's too.
                $this->pending = ['key' => Keys::vt($key)[0], 'unshifted' => 0, 'mods' => 0, 'repeat' => false, 'taken' => true];
                return true;
            }
        }
        if (($mods & Key::COMMAND) !== 0) {
            return false; // the app's shortcuts
        }
        [$k, $unshifted] = Keys::vt($key);
        if ($k === VtKey::UNIDENTIFIED) {
            return false;
        }
        $alt = ($mods & Key::OPTION) !== 0;
        $ctrl = ($mods & Key::CONTROL) !== 0;
        $altMeta = $alt && $t->opts->optionAsAlt;
        if (Keys::typesText($key) && (!$ctrl && !$alt || $alt && !$altMeta && !$ctrl)) {
            // The text it types comes next, from the input methods.
            $this->pending = ['key' => $k, 'unshifted' => $unshifted, 'mods' => $mods, 'repeat' => $ev->repeat, 'taken' => false];
            return false;
        }
        $this->pending = null;
        $text = '';
        if ($altMeta && Keys::typesText($key) && !$ctrl) {
            // Alt as Meta: the character, which the encoder prefixes with Esc.
            $text = ($mods & Key::SHIFT) !== 0 ? Keys::shiftedText($key, $unshifted) : mb_chr($unshifted, 'UTF-8');
        }
        $this->sendKey(new KeyEvent($ev->repeat ? KeyEvent::REPEAT : KeyEvent::PRESS, $k, Keys::mods($mods), text: $text, unshifted: $unshifted));
        return true;
    }

    /** Does what a key bound to an action does, and reports whether it did something (Keybind::$performable). A performable copy lets go of the selection, so that the key's next press reaches the program. */
    private function perform(Action $a, bool $performable): bool
    {
        switch ($a) {
            case Action::ActionIgnore: return true;
            case Action::ActionCopy: return $this->copy($performable);
            case Action::ActionPaste: return $this->paste();
            case Action::ActionSelectAll: $this->selectAll(); return true;
            case Action::ActionClearScreen: return $this->clearScreen();
            case Action::ActionIncreaseFontSize: $this->zoom(new Zoom(1)); return true;
            case Action::ActionDecreaseFontSize: $this->zoom(new Zoom(-1)); return true;
            case Action::ActionResetFontSize: $this->zoom(new Zoom(reset: true)); return true;
            case Action::ActionScrollPageUp:
            case Action::ActionScrollPageDown:
            case Action::ActionScrollToTop:
            case Action::ActionScrollToBottom:
                return $this->scrollBack($a);
            default:
                return false;
        }
    }

    /** Zooms, as onZoom takes it or the terminal does. */
    private function zoom(Zoom $z): void
    {
        if (($on = $this->t->opts->onZoom) !== null && $on($z)) {
            return;
        }
        $this->t->zoom($z);
    }

    /** Scrolls the scrollback as an action does, but for full-screen programs, which have none, and reports whether it did. */
    private function scrollBack(Action $a): bool
    {
        $term = $this->t->term;
        if ($term === null || $term->altScreen()) {
            return false;
        }
        $page = max($term->size()[1] - 1, 1);
        match ($a) {
            Action::ActionScrollPageUp => $term->scrollBy(-$page),
            Action::ActionScrollPageDown => $term->scrollBy($page),
            Action::ActionScrollToTop => $term->scrollToTop(),
            default => $term->scrollToBottom(),
        };
        $this->invalidate();
        return true;
    }

    /**
     * Clears the scrollback and the screen above the cursor's line, which
     * moves to the top, as Kitty's clear_terminal to_cursor: the prompt the
     * shell shows stays. A full-screen program's screen, which this would
     * break, is left alone, as in Ghostty; it reports whether it cleared.
     */
    private function clearScreen(): bool
    {
        $term = $this->t->term;
        if ($term === null || $term->altScreen()) {
            return false;
        }
        $term->setSelection(null);
        $term->scrollToBottom();
        [$x, $y] = $term->cursor();
        $seq = "\x1b[3J";
        if ($y > 0) {
            // Delete the rows above the cursor's from the top, then put the cursor back on its line, now the first.
            $seq .= "\x1b[H\x1b[{$y}M\x1b[1;" . ($x + 1) . 'H';
        }
        $this->t->feed($seq);
        return true;
    }

    private function keyUp(InputEvent $ev): bool
    {
        [$k, $unshifted] = Keys::vt($ev->key);
        $mods = $ev->mods & Key::MODIFIERS;
        $p = $this->pending;
        if ($p !== null && $p['taken'] && $p['key'] === $k) {
            // The release of a key onKey or a keybind took, Command held or not.
            $this->pending = null;
            return true;
        }
        if ($k === VtKey::UNIDENTIFIED || ($mods & Key::COMMAND) !== 0) {
            return false;
        }
        if ($p !== null && $p['key'] === $k) {
            $this->pending = null;
            // No text came: the key alone.
            $text = '';
            if (($p['mods'] & Key::CONTROL) === 0) {
                $text = ($p['mods'] & Key::SHIFT) !== 0 ? Keys::shiftedText($ev->key, $p['unshifted']) : mb_chr($p['unshifted'], 'UTF-8'); // what the key types on a US keyboard
            }
            $this->sendKey(new KeyEvent($p['repeat'] ? KeyEvent::REPEAT : KeyEvent::PRESS, $p['key'], Keys::mods($p['mods']), text: $text, unshifted: $p['unshifted']));
        }
        return $this->encodeKey(new KeyEvent(KeyEvent::RELEASE, $k, Keys::mods($mods), unshifted: $unshifted), false);
    }

    /** Sends text typed or committed by an input method. */
    private function typed(string $text): void
    {
        $this->preedit = '';
        $p = $this->pending;
        $this->pending = null;
        if ($p !== null && $p['taken']) {
            return; // the text of a key onKey took
        }
        $n = mb_strlen($text, 'UTF-8');
        if ($p === null) {
            // Text without a key: of an input method.
            if ($n !== 1) {
                $this->sendText($text);
                return;
            }
            [$k, $unshifted, $shift] = Keys::ofRune(mb_ord($text, 'UTF-8'));
            $p = ['key' => $k, 'unshifted' => $unshifted, 'mods' => $shift ? Key::SHIFT : 0, 'repeat' => false, 'taken' => false];
        }
        if ($n > 1) {
            $this->sendText($text);
            return;
        }
        $consumed = 0;
        if (mb_ord($text, 'UTF-8') !== $p['unshifted']) {
            // Shift or Option made the character.
            $consumed = Keys::mods($p['mods'] & (Key::SHIFT | Key::OPTION));
        }
        $this->sendKey(new KeyEvent($p['repeat'] ? KeyEvent::REPEAT : KeyEvent::PRESS, $p['key'], Keys::mods($p['mods']), $consumed, $text, $p['unshifted']));
    }

    /** Sends a key, scrolling to the bottom and clearing the selection as Ghostty does when it sends something. */
    private function sendKey(KeyEvent $k): void
    {
        if ($this->encodeKey($k, true)) {
            $this->blinkStart = hrtime(true);
        }
    }

    private function encodeKey(KeyEvent $k, bool $press): bool
    {
        $t = $this->t;
        if ($t->term === null) {
            return false;
        }
        $this->keys ??= new KeyEncoder;
        $this->keys->sync($t->term, $t->opts->optionAsAlt);
        $b = $this->keys->encode($k);
        if ($b === '') {
            return false;
        }
        $t->push($b);
        if ($press) {
            $t->term->scrollToBottom();
            $t->term->setSelection(null);
        }
        return true;
    }

    /** Sends text that is no key, as of an input method. */
    private function sendText(string $text): void
    {
        $t = $this->t;
        if ($t->term !== null) {
            $t->term->scrollToBottom();
            $t->term->setSelection(null);
        }
        $t->send($text);
        $this->blinkStart = hrtime(true);
    }

    /** Copies the selection, letting go of it with $clear, and reports whether there was one. */
    private function copy(bool $clear): bool
    {
        $term = $this->t->term;
        $text = $term?->selectionText();
        if ($text === null || $text === '') {
            return false;
        }
        if ($clear) { $term->setSelection(null); }
        $this->writeClipboard($text);
        if ($clear) { $this->invalidate(); }
        return true;
    }

    /**
     * Pastes the clipboard and reports whether it held anything. It reads,
     * as the paste starts, every format for Options::$onPaste, those the
     * program can read for one that reads the clipboard itself (mode 5522),
     * and else only the text, so that pasting into a shell converts nothing
     * for nothing. The program's read comes later: it reads what was read
     * here.
     */
    private function paste(): bool
    {
        $t = $this->t;
        $rt = $this->services;
        if ($rt === null) {
            return false;
        }
        $held = ($rt->clipboardFormats)();
        if ($held === []) {
            return false;
        }
        if ($t->opts->onPaste !== null) {
            $d = ($rt->readClipboardData)(...$held);
            if (($t->opts->onPaste)($d)) {
                return true;
            }
        } elseif ($t->readsClipboard()) {
            $d = ($rt->readClipboardData)(...array_values(array_filter($held, fn(string $f) => PasteOffer::mime($f) !== '')));
        } else {
            $d = ($rt->readClipboardData)(Format::TEXT);
        }
        $t->pasteHeld($d, $held);
        return true;
    }

    /** Lets go of the selection. */
    private function clearSelection(): void
    {
        $this->t->term?->setSelection(null);
        $this->invalidate();
    }

    private function selectAll(): void
    {
        $term = $this->t->term;
        if ($term !== null && ($s = $term->selectAll()) !== null) {
            $term->setSelection($s);
        }
        $this->invalidate();
    }

    /** The cell under a point of the node, in points, within the grid. @return array{0: int, 1: int} */
    private function cellAt(float $x, float $y): array
    {
        if ($this->cellW === 0) {
            return [0, 0];
        }
        $col = (int)floor(($x * $this->scale - $this->ox) / $this->cellW);
        $row = (int)floor(($y * $this->scale - $this->oy) / $this->cellH);
        return [max(0, min($col, $this->cols - 1)), max(0, min($row, $this->rows - 1))];
    }

    /** Reports the pointer to the program that takes the mouse, or selects. */
    private function pointerEvent(InputEvent $ev): bool
    {
        $t = $this->t;
        $term = $t->term;
        $this->pointer = [$ev->x, $ev->y];
        if ($term === null || $this->cellW === 0) {
            return false;
        }
        if ($this->linkPress) {
            // The press opened a link: its moves and its release are no one else's.
            $this->linkPress = $ev->kind !== InputEvent::POINTER_UP;
            return true;
        }
        // Command over a link: a hand, and a click opens it, before a program that takes the mouse sees it.
        [$link, $path] = $this->linkUnder($ev->mods, $ev->x, $ev->y);
        if (($hover = $link !== '') !== $this->hoverLink) {
            $this->hoverLink = $hover;
            $this->invalidate();
        }
        if ($link !== '' && $ev->kind === InputEvent::POINTER_DOWN && $ev->button === 0) {
            [$this->opening, $this->openingPath, $this->linkPress] = [$link, $path, true];
            return true;
        }
        $tracking = $term->mouseTracking() && ($ev->mods & Key::SHIFT) === 0;
        if ($tracking && !$this->selecting || $this->reporting) {
            return $this->report($ev);
        }
        $this->gesture ??= new Gesture(500_000_000, 5 * $this->scale);
        [$col, $row] = $this->cellAt($ev->x, $ev->y);
        $px = $ev->x * $this->scale; $py = $ev->y * $this->scale;
        switch ($ev->kind) {
            case InputEvent::POINTER_DOWN:
                if ($ev->button === 1 && $t->opts->rightClick !== RightClickAction::RightClickContextMenu) {
                    $this->rightClick = $t->opts->rightClick; // input() does it
                    return true;
                }
                if ($ev->button !== 0) {
                    return false; // the context menu
                }
                $ref = $term->cellAt($col, $row);
                if ($ref === null) {
                    return false;
                }
                $this->selecting = true;
                $term->setSelection($this->gesture->press($term, $ref, $px, $py, hrtime(true)));
                return true;
            case InputEvent::POINTER_MOVE:
                if (!$this->selecting) {
                    return false;
                }
                $ref = $term->cellAt($col, $row);
                if ($ref === null) {
                    return true;
                }
                if (($s = $this->gesture->drag($term, $ref, $px, $py, $this->geometry(), ($ev->mods & Key::OPTION) !== 0)) !== null) {
                    $term->setSelection($s);
                }
                $this->ticking = $this->gesture->autoscroll($term) !== 0;
                return true;
            case InputEvent::POINTER_UP:
                if (!$this->selecting || $ev->button !== 0) {
                    return false;
                }
                $this->selecting = $this->ticking = false;
                $this->gesture->release($term, $term->cellAt($col, $row));
                if ($t->opts->copyOnSelect === CopyOnSelect::CopyOnSelectClipboard && ($text = $term->selectionText()) !== null) {
                    $this->copying = $text;
                }
                return true;
        }
        return false;
    }

    /** The link a click with $mods at ($x, $y) opens, and whether it is a file path: none without Command, or while the primary button selects or the program has a press. @return array{0: string, 1: bool} */
    private function linkUnder(int $mods, float $x, float $y): array
    {
        if (($mods & Key::COMMAND) === 0 || $this->selecting || $this->reporting || $this->cellW === 0 || $this->t->term === null) {
            return ['', false];
        }
        return $this->linkAt(...$this->cellAt($x, $y));
    }

    /** The link at a cell: a hyperlink (OSC 8), a URL printed as text, or with Options::$openLink a file path, or "". @return array{0: string, 1: bool} */
    private function linkAt(int $col, int $row): array
    {
        $term = $this->t->term;
        if (($ref = $term->cellAt($col, $row)) !== null && ($url = $term->hyperlink($ref)) !== '') {
            return [$url, false];
        }
        [$text, $cols] = $term->rowText($row);
        if (($url = Links::urlAt($text, $cols, $col)) !== '') {
            return [$url, false];
        }
        if ($this->t->opts->openLink !== null && ($p = Links::pathAt($text, $cols, $col)) !== '') {
            return [$p, true];
        }
        return ['', false];
    }

    /** The grid in pixels, for selection gestures. @return array{0: int, 1: int, 2: int, 3: int} */
    private function geometry(): array
    {
        return [$this->cols, $this->cellW, $this->ox, $this->oy + $this->rows * $this->cellH];
    }

    /** Scrolls a selection dragged past the top or the bottom. */
    private function autoscroll(): void
    {
        $term = $this->t->term;
        $dir = $this->gesture->autoscroll($term);
        if ($dir === 0 || !$this->selecting) {
            $this->ticking = false;
            return;
        }
        $term->scrollBy($dir);
        [$col, $row] = $this->cellAt($this->pointer[0], $this->pointer[1]);
        if (($s = $this->gesture->tick($term, $col, $row, $this->pointer[0] * $this->scale, $this->pointer[1] * $this->scale, $this->geometry(), false)) !== null) {
            $term->setSelection($s);
        }
    }

    /** Reports the pointer to the program. */
    private function report(InputEvent $ev): bool
    {
        $term = $this->t->term;
        $this->mouse ??= new MouseEncoder;
        $buttons = [MouseEncoder::BUTTON_LEFT, MouseEncoder::BUTTON_RIGHT, MouseEncoder::BUTTON_MIDDLE];
        switch ($ev->kind) {
            case InputEvent::POINTER_DOWN: $action = MouseEncoder::PRESS; $this->reporting = true; break;
            case InputEvent::POINTER_UP: $action = MouseEncoder::RELEASE; $this->reporting = false; break;
            default: $action = MouseEncoder::MOTION;
        }
        $b = $buttons[$ev->button] ?? MouseEncoder::BUTTON_NONE;
        $this->mouse->sync($term, $this->ox * 2 + $this->cols * $this->cellW, $this->oy * 2 + $this->rows * $this->cellH, $this->cellW, $this->cellH, $this->ox, $this->oy, $this->reporting);
        $out = $this->mouse->encode($action, $b, Keys::mods($ev->mods), $ev->x * $this->scale, $ev->y * $this->scale);
        if ($out !== '') {
            $this->t->push($out);
        }
        return $ev->kind !== InputEvent::POINTER_MOVE || $out !== '';
    }

    /** Scrolls the scrollback, or reports the wheel to the program. */
    private function scroll(InputEvent $ev): bool
    {
        $t = $this->t;
        if ($this->cellH === 0) {
            return false;
        }
        $zooming = ($ev->mods & Key::COMMAND) !== 0;
        if ($ev->precise) { // a touchpad gesture keeps what it began as, though Command comes down while its momentum still runs (mygo's 9c69cf8)
            if (!$ev->momentum) { $this->scrollZoom = $zooming; }
            $zooming = $this->scrollZoom;
        }
        if ($zooming) {
            // Rolling up is a step up. dy is the scroll offset's change, so up comes negative; a wheel's event is a notch, a trackpad's many small moves, which add up to a step by 40.
            if (!$ev->precise) {
                if ($ev->dy < 0) { $this->zoom(new Zoom(1)); } elseif ($ev->dy > 0) { $this->zoom(new Zoom(-1)); }
                return true;
            }
            $this->zoomed -= $ev->dy;
            if (($steps = (int)($this->zoomed / 40)) !== 0) {
                $this->zoomed -= $steps * 40;
                $this->zoom(new Zoom($steps));
            }
            return true;
        }
        $ch = $this->cellH / $this->scale;
        $dy = $ev->dy;
        if (!$ev->precise) {
            $dy = $dy / self::WHEEL_LINE * 3 * $ch; // a notch scrolls three rows
        }
        $this->scrolled += $dy;
        $rows = (int)($this->scrolled / $ch);
        $this->scrolled -= $rows * $ch;
        if ($rows === 0) {
            return true;
        }
        if (($on = $t->opts->onScroll) !== null && $this->wheelScrollsBack($ev->mods) && $on($rows)) {
            return true;
        }
        $term = $t->term;
        if ($term === null) {
            return false;
        }
        if ($this->wheelTracked($ev->mods)) {
            $this->mouse ??= new MouseEncoder;
            $this->mouse->sync($term, $this->ox * 2 + $this->cols * $this->cellW, $this->oy * 2 + $this->rows * $this->cellH, $this->cellW, $this->cellH, $this->ox, $this->oy, false);
            $button = $rows < 0 ? MouseEncoder::WHEEL_UP : MouseEncoder::WHEEL_DOWN;
            for ($i = abs($rows); $i > 0; $i--) {
                if (($out = $this->mouse->encode(MouseEncoder::PRESS, $button, Keys::mods($ev->mods), $ev->x * $this->scale, $ev->y * $this->scale)) !== '') {
                    $t->push($out);
                }
            }
        } elseif ($this->wheelArrows()) {
            // Full-screen programs scroll with the arrows (mode 1007).
            $this->keys ??= new KeyEncoder;
            $this->keys->sync($term, false);
            $key = $rows < 0 ? VtKey::ARROW_UP : VtKey::ARROW_DOWN;
            for ($i = abs($rows); $i > 0; $i--) {
                $t->push($this->keys->encode(new KeyEvent(KeyEvent::PRESS, $key)));
            }
        } else {
            $term->scrollBy($rows);
        }
        return true;
    }

    /** Whether the program takes the wheel as the mouse: Shift keeps it for the terminal. */
    private function wheelTracked(int $mods): bool
    {
        return $this->t->term->mouseTracking() && ($mods & Key::SHIFT) === 0;
    }

    /** Whether a full-screen program takes the wheel as arrow keys (alternate scroll, mode 1007). */
    private function wheelArrows(): bool
    {
        return $this->t->term->altScreen() && $this->t->term->mode(VtTerminal::MODE_ALT_SCROLL);
    }

    /** Whether the wheel scrolls the scrollback, no program taking it, as Options::$onScroll may then. */
    private function wheelScrollsBack(int $mods): bool
    {
        return $this->t->term !== null && !$this->wheelTracked($mods) && !$this->wheelArrows();
    }
}
