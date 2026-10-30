<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

use MyPHP\L10n;
use MyPHP\Plugins\Terminal\Vt\{Colors, Cursor, Gesture, KeyEncoder, MouseEncoder, Terminal as VtTerminal};
use MyPHP\Text\System;
use MyPHP\Transfer\Format;
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
 * printed as text, or a file path with Options::$openLink. The pointer
 * is Ghostty's: the I-beam over a shell, the arrow while a program takes
 * the mouse (the I-beam again while Shift is held, as Shift selects then),
 * and the shape a program asks for with OSC 22 (pointer, crosshair,
 * col-resize…) until it takes or leaves the mouse again.
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
    /** Options::$minimumContrast, as of the last paint. */
    public float $contrast = 0.0;
    public Cursor $cursor;
    /** @var array{0: int, 1: int, 2: int} total, offset and length of the viewport, in rows */
    public array $scrollbar = [0, 0, 0];
    /** @var array{0: float, 1: float} the node's size in points, as the last paint had it */
    public array $size = [0.0, 0.0];
    /**
     * The scroll bar (mygo's): the primary button drags it, held $barGrab
     * points below the thumb's top; the pointer is over the bar, as of its
     * last move ($overBar) and of the last build ($barHover); the last
     * paint showed it hovered or held ($barLit); how many rows the viewport
     * was above the bottom ($barBack), and when either last changed
     * ($barSeen, hrtime), from which the bar fades out.
     */
    public bool $barHeld = false, $overBar = false, $barHover = false, $barLit = false;
    public float $barGrab = 0.0;
    public int $barBack = 0, $barSeen = 0;
    /** How wide the strip at the right edge is over which the scroll bar takes the pointer, in points. */
    private const SCROLLBAR_STRIP = 12;
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
    /** @var ?array{0: int, 1: int, 2: int} the cells of the link Command is held over, [row, x0, x1) of the viewport, which a click opens and which shows underlined; null over none */
    public ?array $hover = null;
    /** What the hovered link opens, and whether it is a file path (Terminal::hoveredLink); the primary button pressed a link, and the press is the link's until the release. */
    public string $hoverLink = '';
    public bool $hoverPath = false, $linkPress = false;
    /** The pointer over the grid (pointerShape); whether the program took the mouse as of the last frame, and the shape taking or leaving it showed while the program's own (OSC 22) is still the one it had then. */
    public \MyPHP\UI\Cursor $pointerAt = \MyPHP\UI\Cursor::Text;
    private bool $tracking = false;
    private int $modeShape = self::SHAPE_TEXT, $modeOsc = 0;
    /** Ghostty's pointer shapes (GhosttyMouseShape) the view picks itself: the arrow and the I-beam. */
    private const SHAPE_DEFAULT = 0, SHAPE_TEXT = 8;
    /** The link a click asked to open, and whether it is a file path, which the system does not open. */
    public string $opening = '';
    public bool $openingPath = false;
    /** The selection a release ended with Options::$copyOnSelect, and what a secondary click does, done once the event is handled. */
    public string $copying = '';
    public RightClickAction $rightClick = RightClickAction::RightClickContextMenu;
    /** Scrolling not yet a whole row; the wheel with Command not yet a notch. */
    public float $scrolled = 0, $zoomed = 0;
    /** That the touchpad's scroll gesture going on zooms, from how it began. */
    private bool $scrollZoom = false;
    /** An autoscroll tick is due. */
    public bool $ticking = false;
    /** The programs' reads of the clipboard waiting to be answered, and the one the terminal's own prompt asks about while $clipOpen, one at a time. @var list<ClipboardRequest> */
    private array $reads = [];
    private ?ClipboardRequest $clipPrompt = null;
    private bool $clipOpen = false, $prompted = false;

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
        $v->update($c, $c->focused($e), $c->hovered($e));
        $e->cursor($v->hover !== null ? \MyPHP\UI\Cursor::Pointer : $v->pointerAt);
        if (($files = $c->droppedFiles($e)) !== null && $files !== []) {
            $t->dropFiles($files);
        }
        $e->handleInput($v->input(...));
        $e->handleTextInput($v);
        $e->draw(function (DisplayList $list, Node $n, UITheme $th, TextPainter $text) use ($v): void { $v->paint($list, $text, [$n->x, $n->y, $n->w, $n->h]); });
        $e->contextMenu($v->menu(...));
        return $e;
    }

    /**
     * The pointer over the grid, as Ghostty shows it (mygo's pointerShape):
     * the shape the program asked for (OSC 22), the I-beam until it asks;
     * the arrow once it takes the mouse, and the I-beam again once it
     * leaves it, until it asks for another; and the I-beam while Shift is
     * held over a program that takes the mouse, as Shift selects then.
     */
    private function pointerShape(VtTerminal $term, int $mods): \MyPHP\UI\Cursor
    {
        $asked = $term->mouseShape();
        if (($tracking = $term->mouseTracking()) !== $this->tracking) {
            [$this->tracking, $this->modeOsc] = [$tracking, $asked];
            $this->modeShape = $tracking ? self::SHAPE_DEFAULT : self::SHAPE_TEXT;
        }
        if ($this->tracking && ($mods & \MyPHP\UI\Key::SHIFT) !== 0) {
            return \MyPHP\UI\Cursor::Text;
        }
        return self::cursorOf($asked === $this->modeOsc ? $this->modeShape : $asked);
    }

    /** The pointer for a Ghostty pointer shape, as OSC 22 names them: the nearest one the system has, or the arrow (mygo's cursorOf). */
    private static function cursorOf(int $shape): \MyPHP\UI\Cursor
    {
        static $shapes = null;
        $shapes ??= array_map(fn(string $c) => \MyPHP\UI\Cursor::from($c), [
            'default', 'context-menu', 'default' /* help */, 'pointer', 'default' /* progress */, 'default' /* wait */, 'crosshair' /* cell */, 'crosshair',
            'text', 'vertical-text', 'alias', 'copy', 'move', 'not-allowed' /* no-drop */, 'not-allowed', 'grab', 'grabbing', 'move' /* all-scroll */,
            'col-resize', 'row-resize', 'n-resize', 'e-resize', 's-resize', 'w-resize', 'nesw-resize' /* ne */, 'nwse-resize' /* nw */, 'nwse-resize' /* se */,
            'nesw-resize' /* sw */, 'ew-resize', 'ns-resize', 'nesw-resize', 'nwse-resize', 'default' /* zoom-in */, 'default' /* zoom-out */,
        ]);
        return $shapes[$shape] ?? \MyPHP\UI\Cursor::Default;
    }

    /** Updates the view as a frame builds. */
    private function update(Context $c, bool $focused, bool $hovered): void
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
            $t->schemeChanged($t->curTheme, $theme);
            $t->curTheme = $theme;
            if ($focused !== $this->focused && $t->term->mode(VtTerminal::MODE_FOCUS_EVENT)) {
                $t->push(KeyEncoder::encodeFocus($focused));
            }
            // The hand over a link, and its underline, follow Command as it is pressed and let go of, with the pointer still.
            [$this->hoverLink, $this->hoverPath, $this->hover] = $hovered ? $this->linkUnder($c->modifiers(), $this->pointer[0], $this->pointer[1]) : ['', false, null];
            $this->pointerAt = $this->pointerShape($t->term, $c->modifiers());
            $this->barHover = $hovered && $this->overScrollbar($this->pointer[0], $this->pointer[1]);
            if ($this->barHover || $this->barHeld) {
                $this->pointerAt = \MyPHP\UI\Cursor::Default; // the bar's, not the text's
            }
            // Input methods compose where the cursor is now, which the last frame may not show yet.
            $this->caretCell = [$this->cursor->x, $this->cursor->y];
            if ($t->term->atBottom()) {
                $this->caretCell = $t->term->cursor();
            }
        }
        $copied = $t->clipOut;
        $t->clipOut = [];
        foreach ($copied as $s) {
            $this->writeClipboard($s, CopySource::CopyProgram);
        }
        if ($t->term === null) {
            [$this->reads, $this->clipPrompt, $this->clipOpen] = [[], null, false];
        } else {
            array_push($this->reads, ...$t->clipReads);
            $t->clipReads = [];
            $this->answerReads($c, $t->term);
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

    /**
     * Answers the programs' reads of the clipboard as the frame builds, in
     * order, as an OSC 52 reply does not tell which read it answers (mygo's
     * answerReads): those allowed served from the clipboard; one the app is
     * asked about, through Options::$onClipboardRequest or the terminal's
     * own prompt, holds back those after it.
     */
    private function answerReads(Context $c, VtTerminal $term): void
    {
        $this->prompted = false;
        while ($this->reads !== [] && $this->answerRead($c, $term, $this->reads[0])) {
            if ($this->clipPrompt === $this->reads[0]) { [$this->clipPrompt, $this->clipOpen] = [null, false]; }
            array_shift($this->reads);
        }
    }

    /** Answers $r if it can this frame; whether it did. */
    private function answerRead(Context $c, VtTerminal $term, ClipboardRequest $r): bool
    {
        if ($r->answer !== 0 || !$r->ask) {
            $this->finishRead($term, $r, $r->answer !== 2);
            return true;
        }
        if (($on = $this->t->opts->onClipboardRequest) !== null) {
            if (!$r->asked) {
                [$r->text, $r->asked] = [$this->readClipboard(), true];
                $on($r);
                if ($r->answer !== 0) {
                    $this->finishRead($term, $r, $r->answer === 1);
                    return true;
                }
            }
            return false;
        }
        if ($this->prompted) { // one prompt a pass: the next read's shows in the next frame
            $this->invalidate();
            return false;
        }
        $this->prompted = true;
        if ($this->clipPrompt !== $r) { // the terminal's own prompt, across the frames until it is answered
            $r->text = $this->readClipboard();
            [$this->clipPrompt, $this->clipOpen] = [$r, true];
        }
        $tx = L10n::in(\MyPHP\App::locale());
        $chosen = $c->alertDialog($this->clipOpen, $tx->get('clipboardRead'), $this->readMessage($r), $tx->get('clipboardReadDeny'), $tx->get('clipboardReadAllow'));
        if ($chosen === -1) {
            return false;
        }
        $this->finishRead($term, $r, $chosen === 1);
        return true;
    }

    /** Answers $r with what the clipboard holds of what the program asked for, the text too, as an OSC 52 read asks for it alone, or with its refusal (mygo's finishRead). */
    private function finishRead(VtTerminal $term, ClipboardRequest $r, bool $allow): void
    {
        if (!$allow || $this->services === null) {
            $term->answerClipboard($r->deferred, false);
            return;
        }
        $formats = array_values(array_unique([Format::TEXT, ...$r->mimes]));
        $d = ($this->services->readClipboardData)(...$formats);
        $contents = [];
        foreach ([...$r->mimes, Format::TEXT] as $mime) {
            if (!isset($contents[$mime]) && ($b = PasteOffer::readFormat($d, $mime)) !== '') { $contents[$mime] = $b; }
        }
        $available = [];
        foreach (($this->services->clipboardFormats)() as $f) {
            if (($m = PasteOffer::mime($f)) !== '' && !in_array($m, $available, true)) { $available[] = $m; }
        }
        if ($this->t->term === $term) { $term->answerClipboard($r->deferred, true, $contents, $available); } // a terminal closed since says nothing
    }

    /** What the prompt says a read asks for: the text it would read as one line of 200 characters at most, the types it asked for when the clipboard holds no text, or that the clipboard is empty. */
    private function readMessage(ClipboardRequest $r): string
    {
        if ($r->text !== '') {
            $one = strtr($r->text, "\n\r\t", '   ');
            return mb_strlen($one) > 200 ? mb_substr($one, 0, 200) . '…' : $one;
        }
        return $r->mimes !== [] ? implode(' ', $r->mimes) : L10n::in(\MyPHP\App::locale())->get('clipboardReadEmpty');
    }

    private function readClipboard(): string
    {
        return $this->services === null ? '' : ($this->services->readClipboard)();
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

    /** Writes $s to the clipboard, and tells Options::$onCopy, by what copied it (mygo's copied). */
    private function writeClipboard(string $s, CopySource $by): void
    {
        if ($this->services !== null) { ($this->services->writeClipboard)($s); }
        if (($on = $this->t->opts->onCopy) !== null) { $on($s, $by); }
    }

    /** The terminal's context menu. */
    public function menu(Menu $m): void
    {
        $t = $this->t;
        $text = $t->term?->selectionText();
        $tx = L10n::in(\MyPHP\App::locale());
        if ($this->menuItem($m, $tx->get('copy'), Action::ActionCopy)->disabled($text === null || $text === '')->chosen()) {
            $this->writeClipboard((string)$text, CopySource::CopyUser);
        }
        if ($this->menuItem($m, $tx->get('paste'), Action::ActionPaste)->chosen()) {
            $this->paste();
        }
        if ($this->menuItem($m, $tx->get('selectAll'), Action::ActionSelectAll)->chosen()) {
            $this->selectAll();
        }
        $m->separator();
        // Clear clears as Command-K does, as Ghostty's: clearing the scrollback alone would change nothing on the screen.
        if ($this->menuItem($m, $tx->get('clearTerminal'), Action::ActionClearScreen)->disabled($t->term?->altScreen() ?? false)->chosen()) {
            $this->clearScreen();
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
