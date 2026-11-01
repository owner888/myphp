<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use Closure;

/**
 * An item of a plugin's menu (IINA's JavascriptPluginMenuItem): its title,
 * whether it is checked (selected) and enabled, its key (keyBinding, as
 * IINA's key bindings write one: "Meta+k"), its submenu's items, its action
 * called with it; a separator. Its changes show as the Plugin menu opens.
 */
final class PluginMenuItem
{
    /** @var list<PluginMenuItem> */
    public array $items = [];
    public bool $isSeparator = false;

    /** @param ?Closure(PluginMenuItem): mixed $action */
    public function __construct(
        public string $title,
        public readonly ?Closure $action = null,
        public bool $selected = false,
        public bool $enabled = true,
        public ?string $keyBinding = null,
        private readonly ?Instance $instance = null,
    ) {}

    /** Adds $item to its submenu: itself. */
    public function addSubMenuItem(PluginMenuItem $item): self
    {
        $this->items[] = $item;
        return $this;
    }

    /** Calls its action with it, as the plugin's (IINA's callAction): none does nothing. */
    public function callAction(): void
    {
        if ($this->action !== null) { $this->instance?->call($this->action, $this); }
    }
}
