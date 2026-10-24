<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** A toast Context::addToast shows, and that a view builds with Context::toastViewportBase to give toasts a look of its own (mygo's Toast). */
final class Toast
{
    public function __construct(
        /** Names the toast: adding one with the id of a toast showing changes that toast, which shows anew; addToast makes one up when it is empty. */
        public string $id = '',
        /** What the toast says, which screen readers read out as it shows. */
        public string $title = '',
        public string $description = '',
        /** What kind of toast it is, as "error" or "success", for the look to tell. */
        public string $type = '',
        /** How long it shows, in seconds: 4 when 0, until it is closed when negative; the time stops while the pointer rests on the toasts, the focus is in them or the window is in the background. */
        public float $timeout = 0.0,
        /** Labels a button that runs $onAction, as Undo after deleting, and closes the toast. */
        public string $action = '',
        public ?\Closure $onAction = null,
        /** Runs as the toast closes: its time is over, or the user, its action or closeToast closed it. */
        public ?\Closure $onClose = null,
        /** What else the look of the toast needs. */
        public mixed $data = null,
    ) {}

    /** How long the toast shows, in seconds, 0 until it is closed. */
    public function life(): float
    {
        return match (true) { $this->timeout < 0 => 0.0, $this->timeout == 0.0 => Runtime::TOAST_FOR, default => $this->timeout };
    }
}
