<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

use MyPHP\L10n;
use MyPHP\Plugins\Terminal\Vt\{Colors, Cursor, Gesture, KeyEncoder, MouseEncoder, Terminal as VtTerminal};
use MyPHP\Text\System;
use MyPHP\UI\{Context, DisplayList, Menu, MenuItem, Node, Role, Runtime, TextInputClient, TextInputRange, TextInputSelection, TextPainter, TextState, Theme as UITheme};

/**
 * What a frame of the terminal needs from frame to frame (mygo's view):
 * the grid, the rows drawn, the encoders of keys and the mouse, an input
 * method's composition and the pointer's gestures. The terminal keeps it.
 *
 * Terminal::view() shows the terminal, and takes its keyboard input once
 * it has the focus, which a click gives it. Size it like any node, as with
 * grow(); its size sets the terminal's, in cells of the font. A terminal
 * shows in one view at a time.
 *
 * A key goes, in order, to Options::$onKey, to the terminal's keybinds
 * (Options::$keybinds, over Terminal::defaultKeybinds(): Command+C and
 * Command+V copy and paste), and to the program. Other Command keys never
 * reach the program, and go to the app's shortcuts. The Edit menu's roles
 * copy, paste and select all too. The wheel scrolls the scrollback unless
 * the program takes the mouse, and zooms with Command held
 * (Options::$onZoom). Hold Shift to select while a program takes the
 * mouse, and Command to open a link on click: a hyperlink (OSC 8), a URL
 * printed as text, or a file path with Options::$openLink.
 *
 * @internal
 */
final class View implements TextInputClient
{
    use ViewInput, ViewPaint;

    /** The cursor blinks at this interval while the terminal has the focus. */
    private const BLINK = 0.6;

    public ?Runtime $services = null;
    public ?System $sys = null;
    public ?Theme $theme = null;
    /** The theme the emulator has. */
    private ?Theme $applied = null;
    /** The grid, in device pixels: the cells, the baseline in a cell, the origin of the grid relative to the node; $scale is device pixels per point. */
    public ?array $font = null;
    /** @var list<\MyPHP\Text\Style> the regular, bold, italic and bold italic fonts */
    public array $fonts = [];
    public float $scale = 0;
    public int $cellW = 0, $cellH = 0, $baseline = 0;
    public int $ox = 0, $oy = 0;
    public int $cols = 0, $rows = 0;
    public ?Colors $colors = null;
    public Cursor $cursor;
    /** @var array{0: int, 1: int, 2: int} total, offset and length of the viewport, in rows */
    public array $scrollbar = [0, 0, 0];
    /** @var list<?RowCache> */
    public array $lines = [];
    /** @var array<string, list<\MyPHP\Text\Glyph>> shaped text, the most recent last */
    public array $shaped = [];
    public ?KeyEncoder $keys = null;
    public ?MouseEncoder $mouse = null;
    public ?Gesture $gesture = null;
    /** A key that types text, until the text comes: Ghostty's key, the codepoint it types unshifted, the modifiers, a repeat, and whether onKey or a keybind took it. @var ?array{key: int, unshifted: int, mods: int, repeat: bool, taken: bool} */
    public ?array $pending = null;
    /** An input method's composition, its caret (a rune) and its selection (UTF-16 units). */
    public string $preedit = '';
    public int $preeditCaret = 0;
    public TextInputRange $preeditSelected;
    public bool $focused = false;
    /** Nanoseconds of hrtime. */
    public int $blinkStart = 0;
    public int $now = 0;
    /** The cursor's cell, for input methods. @var array{0: int, 1: int} */
    public array $caretCell = [0, 0];
    /** The primary button selects; a press went to the program, which gets the release too. */
    public bool $selecting = false, $reporting = false;
    /** @var array{0: float, 1: float} */
    public array $pointer = [0.0, 0.0];
    /** Command is held over a link, which a click opens; the primary button pressed one, and the press is the link's until the release. */
    public bool $hoverLink = false, $linkPress = false;
    /** The link a click asked to open, and whether it is a file path, which the system does not open. */
    public string $opening = '';
    public bool $openingPath = false;
    /** The selection a release ended with Options::$copyOnSelect, and what a secondary click does, done once the event is handled. */
    public string $copying = '';
    public RightClickAction $rightClick = RightClickAction::RightClickContextMenu;
    /** Scrolling not yet a whole row; the wheel with Command not yet a notch. */
    public float $scrolled = 0, $zoomed = 0;
    /** An autoscroll tick is due. */
    public bool $ticking = false;

    public function __construct(public Terminal $t)
    {
        $this->cursor = new Cursor;
        $this->preeditSelected = new TextInputRange(0, 0);
        $this->blinkStart = hrtime(true);
    }

    /** The view of $t as a frame builds it (mygo's View). */
    public static function build(Context $c, Terminal $t): Node
    {
        $v = $t->v ??= new self($t);
        $rt = $c->runtime;
        if ($v->services !== $rt) {
            $v->services = $rt;
            $t->draw = $rt === null ? null : function () use ($rt): void { $rt->changed(); };
        }
        $v->sys = $c->metrics()->textSystem();
        // A terminal's grid is left to right in a right-to-left interface too.
        $e = $c->column()->focusable()->clip();
        $c->end();
        $e->ownRing = true; // no focus ring
        $e->role(Role::GROUP)->label('Terminal');
        $v->update($c, $c->focused($e));
        $e->cursor($v->hoverLink ? 'pointer' : 'ibeam');
        $e->handleInput($v->input(...));
        $e->handleTextInput($v);
        $e->draw(function (DisplayList $list, Node $n, UITheme $th, TextPainter $text) use ($v): void { $v->paint($list, $text, [$n->x, $n->y, $n->w, $n->h]); });
        $e->contextMenu($v->menu(...));
        return $e;
    }

    /** Updates the view as a frame builds. */
    private function update(Context $c, bool $focused): void
    {
        $t = $this->t;
        $this->now = $this->services?->now ?? hrtime(true);
        $dark = $c->theme->dark;
        $theme = $t->opts->theme;
        if ($dark && $t->opts->darkTheme !== null) {
            $theme = $t->opts->darkTheme;
        } elseif ($theme === null) {
            $theme = Theme::default($dark);
        }
        $this->theme = $theme;
        if ($t->term !== null) {
            if ($this->applied !== $theme) {
                $theme->apply($t->term);
                $this->applied = $theme;
            }
            $t->curTheme = $theme;
            if ($focused !== $this->focused && $t->term->mode(VtTerminal::MODE_FOCUS_EVENT)) {
                $t->push(KeyEncoder::encodeFocus($focused));
            }
            // The hand over a link follows Command as it is pressed and let go of, with the pointer still.
            $this->hoverLink = $this->linkUnder($c->modifiers(), $this->pointer[0], $this->pointer[1])[0] !== '';
            // Input methods compose where the cursor is now, which the last frame may not show yet.
            $this->caretCell = [$this->cursor->x, $this->cursor->y];
            if ($t->term->atBottom()) {
                $this->caretCell = $t->term->cursor();
            }
        }
        $copied = $t->clipOut;
        $t->clipOut = [];
        foreach ($copied as $s) {
            $this->writeClipboard($s);
        }
        if ($focused !== $this->focused) {
            $this->focused = $focused;
            $this->blinkStart = $this->now;
            if (!$focused) {
                // Input methods drop their composition with the focus.
                $this->preedit = '';
                $this->pending = null;
            }
        }
        // The cursor blinks while the terminal has the focus.
        if ($this->focused && $this->cursor->blinking && $this->cursor->visible) {
            $since = ($this->now - $this->blinkStart) / 1e9;
            $this->services?->after(self::BLINK - fmod($since, self::BLINK));
        }
        if ($this->ticking) {
            $this->services?->after(0.04);
        }
    }

    /** Frees what the view made of the library, as the terminal closes. */
    public function release(): void
    {
        $this->keys?->free();
        $this->keys = null;
        $this->mouse?->free();
        $this->mouse = null;
        $this->gesture?->free($this->t->term);
        $this->gesture = null;
    }

    /** Asks for a frame. */
    private function invalidate(): void
    {
        $this->services?->changed();
    }

    private function writeClipboard(string $s): void
    {
        if ($this->services !== null) { ($this->services->writeClipboard)($s); }
    }

    /** The terminal's context menu. */
    public function menu(Menu $m): void
    {
        $t = $this->t;
        $text = $t->term?->selectionText();
        $tx = L10n::in(\MyPHP\App::locale());
        if ($this->menuItem($m, $tx->get('copy'), Action::ActionCopy)->disabled($text === null || $text === '')->chosen()) {
            $this->writeClipboard((string)$text);
        }
        if ($this->menuItem($m, $tx->get('paste'), Action::ActionPaste)->chosen()) {
            $this->paste();
        }
        if ($this->menuItem($m, $tx->get('selectAll'), Action::ActionSelectAll)->chosen()) {
            $this->selectAll();
        }
        $m->separator();
        if ($m->item($tx->get('clearScrollback'))->chosen()) {
            $t->feed("\x1b[3J");
        }
    }

    /** An item of the menu, showing the key bound to its action. */
    private function menuItem(Menu $m, string $label, Action $a): MenuItem
    {
        $it = $m->item($label);
        if (($b = $this->t->shortcut($a)) !== null) {
            $it->shortcut($b->mods, $b->key);
        }
        return $it;
    }

    /** Where input methods compose: the cursor's cell, in points of the node. @return array{0: float, 1: float, 2: float, 3: float} */
    private function caret(): array
    {
        if ($this->scale == 0) {
            return [0.0, 0.0, 0.0, 0.0];
        }
        $cw = $this->cellW / $this->scale; $ch = $this->cellH / $this->scale;
        return [$this->ox / $this->scale + $this->caretCell[0] * $cw, $this->oy / $this->scale + $this->caretCell[1] * $ch, $cw, $ch];
    }

    // The terminal as the client of the system's input methods: the text is the composition alone, at the cursor; what they commit goes to the program as typed (mygo's TextCaret, InputText and InputCompose).

    private static function units(string $s): int { return TextState::utf16Index($s, strlen($s)); }

    public function textForRange(TextInputRange $r): array
    {
        $n = self::units($this->preedit);
        $r = $r->normalized();
        $a = min($r->start, $n); $z = min($r->end, $n);
        $from = TextState::byteIndex($this->preedit, $a);
        return [substr($this->preedit, $from, TextState::byteIndex($this->preedit, $z) - $from), new TextInputRange($a, $z)];
    }

    public function selection(): TextInputSelection
    {
        if ($this->preedit === '') {
            return new TextInputSelection(new TextInputRange(0, 0));
        }
        return new TextInputSelection(new TextInputRange($this->preeditSelected->start, $this->preeditSelected->end));
    }

    public function markedRange(): ?TextInputRange
    {
        return $this->preedit === '' ? null : new TextInputRange(0, self::units($this->preedit));
    }

    public function replaceText(?TextInputRange $r, string $s): void
    {
        if ($s !== '') { $this->typed($s); }
        $this->preedit = '';
        $this->invalidate();
    }

    public function setMarkedText(?TextInputRange $r, string $s, TextInputRange $selected): void
    {
        $n = self::units($s);
        $sel = $selected->normalized();
        $this->preedit = $s;
        $this->preeditSelected = new TextInputRange(min($sel->start, $n), min($sel->end, $n));
        $this->preeditCaret = mb_strlen(substr($s, 0, TextState::byteIndex($s, $this->preeditSelected->end)), 'UTF-8');
        $this->pending = null;
        $this->invalidate();
    }

    public function unmarkText(): void
    {
        $this->preedit = '';
        $this->invalidate();
    }

    public function boundsForRange(TextInputRange $r): ?array
    {
        if ($this->scale == 0) {
            return null;
        }
        return [$this->caret(), new TextInputRange(0, 0)];
    }

    public function indexForPoint(float $x, float $y): ?int { return null; }
}
