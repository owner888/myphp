<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use MyPHP\Logger;

/** IINA's iina.preferences: the plugin's preferences, its preferenceDefaults' where unset; kept on disk by sync(). */
final class PreferencesApi extends Api
{
    public function get(string $key): mixed
    {
        $p = $this->instance->plugin;
        if (!$p->hasPreference($key)) {
            $this->log("Trying to get preference value for undefined key $key", Logger::WARNING);
            return null;
        }
        return $p->preference($key);
    }

    public function set(string $key, mixed $value): void
    {
        $this->instance->plugin->setPreference($key, $value);
    }

    public function sync(): void
    {
        $this->instance->plugin->syncPreferences();
    }
}
