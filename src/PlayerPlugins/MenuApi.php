<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use Closure;

/**
 * IINA's iina.menu: the plugin's items of the Plugin menu, under its name
 * there (the global instance's first, then the player's), the first five
 * at the top and the rest under More… (MediaPlayerMenus::plugin).
 */
final class MenuApi extends Api
{
    /** An item titled $title, $action called with it as it is chosen; $options: enabled, selected, keyBinding. @param array<string, mixed> $options */
    public function item(string $title, ?Closure $action = null, array $options = []): PluginMenuItem
    {
        return new PluginMenuItem($title, $action, (bool)($options['selected'] ?? false), (bool)($options['enabled'] ?? true),
            isset($options['keyBinding']) ? (string)$options['keyBinding'] : null, $this->instance);
    }

    public function separator(): PluginMenuItem
    {
        $i = new PluginMenuItem('', null, false, false);
        $i->isSeparator = true;
        return $i;
    }

    public function addItem(PluginMenuItem $item): void
    {
        $this->instance->menuItems[] = $item;
        Plugins::menuChanged();
    }

    /** @return list<PluginMenuItem> */
    public function items(): array
    {
        return $this->instance->menuItems;
    }

    public function removeAt(int $index): bool
    {
        if (!isset($this->instance->menuItems[$index])) { return false; }
        array_splice($this->instance->menuItems, $index, 1);
        Plugins::menuChanged();
        return true;
    }

    public function removeAllItems(): void
    {
        $this->instance->menuItems = [];
        Plugins::menuChanged();
    }

    /** The Plugin menu brought up to date now (its keys too), not as it next opens. */
    public function forceUpdate(): void
    {
        Plugins::menuChanged();
    }
}
