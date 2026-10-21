<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Fetch;

use Closure;

/** Configure the plugin (mygo's fetch.Options). */
final class Options
{
    /** @param ?Closure(Request): bool $allow */
    public function __construct(
        /** Decides whether pages may make a request, when set; a request it refuses rejects with an error. */
        public ?Closure $allow = null,
    ) {}
}
