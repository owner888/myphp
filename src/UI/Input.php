<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * What the window received since the last frame, in points, y down, and
 * the text field that has the keyboard, which the native view's text
 * input client edits directly (an input method expects its changes to be
 * visible as soon as it makes them).
 */
final class Input
{
    public float $mouseX = -1, $mouseY = -1;
    public bool $mouseInside = false;
    public bool $mouseDown = false;
    /** The modifiers held (Key::SHIFT…), as of the last key, flag change or pointer event (mygo's Context.Modifiers). */
    public int $mods = 0;
    /** @var list<array> ['down'|'up'|'move'|'drag'|'scroll'|'key'|'command'|'insert'|'accessOn'|'access', ...] in arrival order; down and up carry x, y, button, click count, modifier flags; access an action of assistive technology: id, AccessNode action, text, from, to */
    public array $events = [];

    /** The focused text field's state, or null when no field has the keyboard. */
    public ?TextState $focus = null;
    /** Where the focused field's text starts (x, y), its line height and font size. */
    public array $focusText = [0.0, 0.0, 0.0, 14.0];
    public ?TextMetrics $metrics = null;
    /** For a focused text area: the caret rectangle of a byte of display(), set by Runtime. */
    public ?\Closure $caretAt = null;
    /** The text-input client of the focused node that has one (Node::handleTextInput), which the native view's text input client talks to in place of a field, and where its box is (x, y). */
    public ?TextInputClient $client = null;
    public array $clientOrigin = [0.0, 0.0];
    /** The caret of the focused node taking text (Node::textCaret), in the window's layout, or null; and what an input method composes for it. @var ?array{0: float, 1: float, 2: float, 3: float} */
    public ?array $textCaret = null;
    public string $composing = '';
    /** The map from the focused node's layout to the window, its transforms' (Node::$world): the caret and the client's rectangles show through it. */
    public Affine $world;

    public function __construct()
    {
        $this->world = Affine::identity();
    }
    /** Whether the input methods compose text for the client (Node::inputMethod); without them, keys type their characters. */
    public bool $inputMethod = true;
    /** Delivers an event at once to the node taking its input as it comes, reporting whether it took it: set by the runtime. */
    public ?\Closure $direct = null;

    /**
     * Offers a key pressed in the focused field to its onTextKey before the
     * field sees it, and reports whether fn took it (mygo's OnTextKey):
     * only keys that type no text, Enter, Tab, Escape, the arrows, Delete
     * and chords of Command or Control among them; typing goes to the
     * field.
     */
    public function textKey(int $code, string $chars, int $mods): bool
    {
        $f = $this->focus;
        if ($f?->onTextKey === null) {
            return false;
        }
        $types = ($mods & (Key::COMMAND | Key::CONTROL)) === 0 && $chars !== '' && ord($chars[0]) >= 0x20 && ord($chars[0]) !== 0x7f && preg_match('/^[\x{F700}-\x{F8FF}]/u', $chars) !== 1; // function keys come as Unicode's private use
        return !$types && (bool)($f->onTextKey)($code, $chars, $mods & Key::MODIFIERS, $f);
    }

    public function mouse(float $x, float $y): void
    {
        $this->mouseX = $x; $this->mouseY = $y; $this->mouseInside = true;
    }

    /** Gives an event to the node taking its input as it comes, as it arrives (Runtime's deliver, set by the runtime), and queues it for the next frame when that node leaves it. Reports whether the node took it. */
    public function push(array $event): bool
    {
        if ($this->direct !== null && ($this->direct)($event)) { return true; }
        $this->events[] = $event;
        return false;
    }

    /** The caret's rectangle for UTF-16 index $u16 of the focused field's text, in view points, as it shows through the field's transforms. */
    public function caretRect(int $u16): array
    {
        [$x, $y, $h, $size] = $this->focusText;
        if ($this->focus === null && $this->textCaret !== null) {
            return $this->shown($this->textCaret);
        }
        if ($this->focus === null || $this->metrics === null) {
            return $this->shown([$x, $y, 1.0, $h]);
        }
        $display = $this->focus->display();
        $byte = TextState::byteIndex($display, $u16);
        if ($this->caretAt !== null) {
            return $this->shown(($this->caretAt)($byte));
        }
        return $this->shown([$x + ($this->focus->multiline ? 0.0 : $this->focus->offsetX) + $this->metrics->xForIndex($this->focus->shown(), $size, $this->focus->shownIndex($byte)), $y, 1.0, $h]);
    }

    /** The rectangle of range $r of the client's text in view points, as it shows through the node's transforms, and the range it is of; the node's corner where the client gives none. @return array{0: array{0: float, 1: float, 2: float, 3: float}, 1: ?TextInputRange} */
    public function clientRect(TextInputRange $r): array
    {
        [$ox, $oy] = $this->clientOrigin;
        $b = $this->client?->boundsForRange($r);
        return $b === null ? [$this->shown([$ox, $oy, 0.0, 0.0]), null] : [$this->shown([$ox + $b[0][0], $oy + $b[0][1], $b[0][2], $b[0][3]]), $b[1]];
    }

    /** The index, in UTF-16 units, of the client's text at view point ($x, $y), through the node's transforms; null where none shows, as a node collapsed to nothing (mygo's IndexForPoint). */
    public function clientIndex(float $x, float $y): ?int
    {
        $inv = $this->world->inverse();
        if ($this->client === null || $inv === null) {
            return null;
        }
        [$lx, $ly] = $inv->point($x, $y);
        return $this->client->indexForPoint($lx - $this->clientOrigin[0], $ly - $this->clientOrigin[1]);
    }

    /** $r, a rectangle of the focused node's layout, as it shows in the window. @param array{0: float, 1: float, 2: float, 3: float} $r @return array{0: float, 1: float, 2: float, 3: float} */
    private function shown(array $r): array
    {
        return $this->world->set ? $this->world->bounds(...$r) : $r;
    }
}
