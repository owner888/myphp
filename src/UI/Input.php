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

    /** The caret's rectangle for UTF-16 index $u16 of the focused field's text, in view points. */
    public function caretRect(int $u16): array
    {
        [$x, $y, $h, $size] = $this->focusText;
        if ($this->focus === null || $this->metrics === null) {
            return [$x, $y, 1.0, $h];
        }
        $display = $this->focus->display();
        $byte = TextState::byteIndex($display, $u16);
        if ($this->caretAt !== null) {
            return ($this->caretAt)($byte);
        }
        return [$x + ($this->focus->multiline ? 0.0 : $this->focus->offsetX) + $this->metrics->xForIndex($this->focus->shown(), $size, $this->focus->shownIndex($byte)), $y, 1.0, $h];
    }
}
