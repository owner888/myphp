<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;

/** The lifetime of a clipboard write (mygo's ClipboardOptions). */
final class ClipboardOptions
{
    public function __construct(
        /** Runs once on the run loop after the data's providers are no longer needed: replaced, cleared, flushed, or the app quitting; the system may also finish with them after reading all their data. It may write the clipboard again. It does not run when the write fails. */
        public ?Closure $onRelease = null,
    ) {}
}
