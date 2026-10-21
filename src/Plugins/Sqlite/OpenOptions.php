<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Sqlite;

/** Configure a connection (mygo's sqlite.OpenOptions). Foreign keys are enabled by default. */
final class OpenOptions
{
    public function __construct(
        /** Opens an existing database without allowing writes. */
        public bool $readOnly = false,
        /** How long an operation waits for another connection's lock, in seconds: five when 0, no wait when negative. */
        public float $busyTimeout = 0,
    ) {}
}
