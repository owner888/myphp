<?php
declare(strict_types=1);

namespace MyPHP\Native;

/** Where a custom scheme's response goes: a WKURLSchemeTask (WebKitTask), or a test's. Calls after the page stopped reading, or after finish(), do nothing. @internal */
interface SchemeTask
{
    /** @param array<string, string> $headers */
    public function respond(int $status, array $headers): void;

    public function write(string $bytes): void;

    public function finish(): void;

    public function fail(string $message): void;

    /** Whether the page stopped reading, or the task ended. */
    public function stopped(): bool;
}
