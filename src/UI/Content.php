<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;

/**
 * Native UI for a window: a view function of the app's state, run every
 * frame with a Context to build into. One Content can serve several windows.
 */
final class Content
{
    private function __construct(public readonly Closure $view) {}

    /** @param Closure(Context): void $view */
    public static function view(Closure $view): self
    {
        return new self($view);
    }
}
