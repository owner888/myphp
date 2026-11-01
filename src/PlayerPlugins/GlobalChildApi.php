<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use Closure;

/**
 * IINA's iina.global of a player's instance, where the plugin has a
 * globalEntry: the label the global instance gave its player (null for
 * one it did not make), its messages to the global instance (with the
 * player's label) and those from it.
 */
final class GlobalChildApi extends Api
{
    public readonly MessageHub $hub;

    public function __construct(Instance $instance)
    {
        parent::__construct($instance);
        $this->hub = new MessageHub($instance);
    }

    public function getLabel(): ?string
    {
        return $this->player()->userLabel;
    }

    public function postMessage(string $name, mixed $data = null): void
    {
        $parent = $this->instance->plugin->globalInstance?->iina->global;
        if ($parent instanceof GlobalApi) { $parent->hub->call($name, $data, $this->player()->managedLabel ?? $this->player()->logName()); }
    }

    public function onMessage(string $name, Closure $fn): void
    {
        $this->hub->add($name, $fn);
    }
}
