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
    /** @var list<array> ['down'|'up'|'move'|'drag'|'scroll'|'key'|'command'|'insert', ...] in arrival order; down and up carry x, y, button, click count, modifier flags */
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
        return [$x + $this->metrics->xForIndex($display, $size, $byte), $y, 1.0, $h];
    }
}
