<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use MyPHP\Platform;

/**
 * Keeps the display awake while a film plays on Linux (Media::awake), as
 * Native\Awake does on macOS: the backend's Power::keepAwake (the desktop
 * portal's inhibit, else the freedesktop screen saver's), held until
 * released.
 *
 * @internal
 */
final class Awake implements Platform\Awake
{
    /** What lets it go, while held. */
    private ?\Closure $letGo = null;
    private bool $display = true;

    public function __construct(private readonly Power $power, private readonly string $reason) {}

    public function hold(): void
    {
        $this->letGo ??= $this->power->keepAwake($this->display, $this->reason);
    }

    public function setDisplay(bool $display): void
    {
        if ($display === $this->display) {
            return;
        }
        $this->display = $display;
        if ($this->letGo !== null) {
            $this->release();
            $this->hold();
        }
    }

    public function release(): void
    {
        $letGo = $this->letGo;
        $this->letGo = null;
        $letGo?->__invoke();
    }

    public function held(): bool
    {
        return $this->letGo !== null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
