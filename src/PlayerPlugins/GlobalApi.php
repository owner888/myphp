<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use Closure;
use MyPHP\Logger;
use MyPHP\UI\MediaPlayerState;

/**
 * IINA's iina.global of a plugin's global instance (its controller):
 * players it makes (createPlayerInstance: a window, its id; $options' url,
 * label, disableUI, enablePlugins: all plugins, else its own alone), its
 * messages to their instances of it (postMessage to one by id or label,
 * to all for null) and theirs to it (onMessage); its players closed as it
 * goes.
 */
final class GlobalApi extends Api
{
    public readonly MessageHub $hub;
    /** @var array<int, MediaPlayerState> */
    private array $players = [];
    private int $counter = 0;

    public function __construct(Instance $instance)
    {
        parent::__construct($instance);
        $this->hub = new MessageHub($instance);
        $instance->onCleanUp(function (): void {
            foreach ($this->players as $s) { $s->quit(); }
            $this->players = [];
        });
    }

    /** A new player's window, $options' url opened in it: its id, false where it could not be. @param array<string, mixed> $options */
    public function createPlayerInstance(array $options = []): int|false
    {
        $url = null;
        if (is_string($options['url'] ?? null)) {
            try {
                $url = $this->shouldOpenURL($options['url']);
            } catch (PluginError) {
                $this->log("Player not created, URL needs permission: {$options['url']}", Logger::ERROR);
                return false;
            }
        }
        $make = Plugins::$newPlayer ?? $this->fail('global.createPlayerInstance: the app makes no players for plugins');
        $s = $make();
        $id = ++$this->counter;
        $s->managedLabel = "$id-{$this->instance->plugin->identifier}";
        if (is_string($options['label'] ?? null)) { $s->userLabel = $options['label']; }
        if (($options['disableUI'] ?? false) === true) { $s->setUIDisabled(true); }
        $s->loadPlugins(($options['enablePlugins'] ?? false) === true ? null : $this->instance->plugin);
        $this->players[$id] = $s;
        if ($url !== null) { $s->open($url); }
        return $id;
    }

    /** Sends $data as message $name to the plugin's instance of player $target (its id, its label), or of them all (null). */
    public function postMessage(int|string|null $target, string $name, mixed $data = null): void
    {
        $players = match (true) {
            $target === null => $this->players,
            is_int($target) => array_filter([$this->players[$target] ?? null]),
            default => array_filter(Plugins::players(), fn(MediaPlayerState $s) => ($s->managedLabel ?? $s->logName()) === $target), // the label its messages came with
        };
        foreach ($players as $s) {
            $child = $s->pluginInstance($this->instance->plugin)?->iina->global;
            if ($child instanceof GlobalChildApi) { $child->hub->call($name, $data); }
        }
    }

    /** Has $fn($data, $label) called as a player's instance sends message $name. */
    public function onMessage(string $name, Closure $fn): void
    {
        $this->hub->add($name, $fn);
    }
}
