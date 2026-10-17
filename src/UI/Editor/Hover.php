<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

use Closure;

/** The popover of what the text under the pointer is, and the diagnostics there (mygo's hover). */
final class Hover
{
    /** How long a hover stays once the pointer has left the text hovered, for the pointer to go into it, as VS Code's editor.hover.hidingDelay. */
    public const HIDE_DELAY = 0.3;

    public bool $shown = false;
    public int $start = 0, $end = 0;
    /** @var ?Closure(\MyPHP\UI\Context): void */
    public ?Closure $build = null;
    public string $text = '';
    /** The offset a request was made for. */
    public int $asked = -1;
    public float $restAt = 0.0;
    public int $restOff = -1;
    public bool $pending = false;
    /** @var ?list<Diagnostic> */
    public ?array $diags = null;
    /** The pointer is over the popover. */
    public bool $over = false;
    /** A hover the resting pointer asked for, which goes as the pointer leaves, rather than the keyboard's. */
    public bool $byPointer = false;
    /** When the hover goes (seconds of hrtime, 0 for not), the pointer having left the text hovered, unless it goes into the hover first; $focused reports the keyboard in the hover, as its text is selected and copied, which keeps it. */
    public float $hideAt = 0.0;
    public bool $focused = false;

    public function open(): bool { return $this->shown; }

    public function close(): void
    {
        $this->shown = false; $this->build = null; $this->text = ''; $this->diags = null; $this->byPointer = false;
        $this->hideAt = 0.0; $this->focused = false;
        $this->asked = -1;
        $this->pending = false;
    }
}
