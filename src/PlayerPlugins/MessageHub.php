<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use Closure;

/** The listeners of messages by name, one each (IINA's JavascriptMessageHub), called as their plugin's. */
final class MessageHub
{
    /** @var array<string, Closure> */
    private array $listeners = [];

    public function __construct(private readonly Instance $instance) {}

    public function add(string $name, Closure $fn): void
    {
        $this->listeners[$name] = $fn;
    }

    public function clear(): void
    {
        $this->listeners = [];
    }

    /** Calls $name's listener with $data and $userInfo (the label of the player it came from), if there is one. */
    public function call(string $name, mixed $data, mixed $userInfo = null): void
    {
        $fn = $this->listeners[$name] ?? null;
        if ($fn !== null) { $this->instance->call($fn, $data, $userInfo); }
    }
}
