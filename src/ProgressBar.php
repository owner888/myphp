<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * The progress of a task, shown on the application's Dock icon
 * (Window::setProgressBar, mygo's ProgressBar). new ProgressBar() shows
 * no progress.
 */
final class ProgressBar
{
    /** States: none, a bar, an activity of unknown length; paused and error show as a bar on macOS. */
    public const NONE = '', NORMAL = 'normal', INDETERMINATE = 'indeterminate', PAUSED = 'paused', ERROR = 'error';

    public function __construct(
        /** NORMAL when $value is above 0, else NONE, when not given. */
        public readonly ?string $state = null,
        /** The fraction done, between 0 and 1. */
        public readonly float $value = 0.0,
    ) {}
}
