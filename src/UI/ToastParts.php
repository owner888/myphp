<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** The parts of a toast without a look, which Context::toastBase makes (mygo's ToastParts): $root, a status Escape closes while the focus is in it, to build in until end(). */
final class ToastParts
{
    public function __construct(public readonly Node $root, private readonly Context $c, private readonly string $id, private readonly float $age, private readonly float $left) {}

    /** How long the toast shows still, in seconds, to count down; 0 for one that shows until it is closed. It does not move while the time of the toasts stops. */
    public function left(): float
    {
        return $this->left;
    }

    /** How long the toast has shown, in seconds. */
    public function age(): float
    {
        return $this->age;
    }

    /** A button without a look (Context::buttonBase) that runs the toast's onAction and closes it; build its label in it until end(). */
    public function actionButton(): Node
    {
        $b = $this->c->buttonBase();
        if ($this->c->clicked($b)) {
            $this->c->runtime?->toastNamed($this->id)?->onAction?->__invoke();
            $this->c->closeToast($this->id);
        }
        return $b;
    }

    /** A button without a look that closes the toast; build its content in it until end(), and label() it when that is not text. */
    public function closeButton(): Node
    {
        $b = $this->c->buttonBase();
        if ($this->c->clicked($b)) {
            $this->c->closeToast($this->id);
        }
        return $b;
    }
}
