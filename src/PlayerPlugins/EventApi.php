<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use Closure;

/**
 * IINA's iina.event: on("mpv.<event>"), on("mpv.<property>.changed") and
 * on("iina.<event>") of the player (MediaPlayerState::emit), each its id,
 * off() by it; its listeners gone with the instance. The properties' are
 * of those MyPHP has the means to give (MpvApi::getString's).
 */
final class EventApi extends Api
{
    /** @var list<array{0: string, 1: string}> the listeners added, [id, name] */
    private array $added = [];

    public function __construct(Instance $instance)
    {
        parent::__construct($instance);
        $instance->onCleanUp(function (): void {
            foreach ($this->added as [$id, $name]) { $this->player()->events()->remove($id, $name); }
            $this->added = [];
        });
    }

    public function on(string $event, Closure $callback): string
    {
        $parts = explode('.', $event);
        $listener = count($parts) === 2;
        $changed = count($parts) === 3 && $parts[2] === 'changed';
        if (!($listener || $changed) || !in_array($parts[0], ['mpv', 'iina'], true)) {
            $this->fail("Incorrect event name syntax: \"$event\"");
        }
        $events = $this->player()->events();
        if ($parts[0] === 'mpv' && $changed) { $events->observe($parts[1]); }
        $instance = $this->instance;
        $id = $events->add($event, fn(mixed ...$args) => $instance->call($callback, ...$args));
        $this->added[] = [$id, $event];
        return $id;
    }

    public function off(string $event, string $id): void
    {
        if (!$this->player()->events()->remove($id, $event)) {
            $this->log("Event listener not found for id $id", \MyPHP\Logger::WARNING);
        }
    }
}
