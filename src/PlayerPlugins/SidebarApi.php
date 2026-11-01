<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use Closure;

/**
 * IINA's iina.sidebar: the plugin's tab of the plugins' sidebar (its
 * Info.json's sidebarTab, MediaPlayer::pluginsSidebar), a web view of a
 * file of the plugin's; the sidebar shown at its tab, or hidden; messages
 * to and from its page. Made once the window is.
 */
final class SidebarApi extends Api
{
    /** @internal MediaPlayer: its web view, null till loaded. */
    public ?WebPane $pane = null;
    private readonly MessageHub $hub;

    public function __construct(Instance $instance)
    {
        parent::__construct($instance);
        $this->hub = new MessageHub($instance);
    }

    private function window(string $call): \MyPHP\Window
    {
        return $this->player()->window() ?? $this->fail("sidebar.$call called when window is not available. Please call it after receiving the \"iina.window-loaded\" event.");
    }

    public function loadFile(string $path): void
    {
        $s = $this->player();
        $this->pane ??= new WebPane($this->instance, $this->hub, $this->window('loadFile'), '', false, fn() => $s->emitForPlugins('iina.plugin-overlay-loaded'));
        $this->pane->loadFile($path);
    }

    public function show(): void
    {
        $this->window('show');
        $this->player()->showPluginsSidebar($this->instance->plugin->identifier);
    }

    public function hide(): void
    {
        $this->window('hide');
        $this->player()->showPluginsSidebar(null);
    }

    public function postMessage(string $name, mixed $data = null): void
    {
        $this->pane?->postMessage($name, $data);
    }

    public function onMessage(string $name, Closure $callback): void
    {
        $this->hub->add($name, $callback);
    }
}
