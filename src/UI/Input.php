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
    /** @var list<array> ['down'|'up'|'drag'|'scroll'|'key'|'command'|'insert', ...] in arrival order; down and up carry x, y, button, click count */
    public array $events = [];

    /** The focused text field's state, or null when no field has the keyboard. */
    public ?TextState $focus = null;
    /** Where the focused field's text starts (x, y), its line height and font size. */
    public array $focusText = [0.0, 0.0, 0.0, 14.0];
    public ?TextMetrics $metrics = null;
    /** For a focused text area: the caret rectangle of a byte of display(), set by Runtime. */
    public ?\Closure $caretAt = null;

    public function mouse(float $x, float $y): void
    {
        $this->mouseX = $x; $this->mouseY = $y; $this->mouseInside = true;
    }

    public function push(array $event): void
    {
        $this->events[] = $event;
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
