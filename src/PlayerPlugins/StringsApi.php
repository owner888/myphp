<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

/**
 * $iina->strings: the plugin's messages, of the catalogs Info.json's i18n
 * names (locales/<tag>.json, as an app's), in the language the app shows:
 * get('Opening media…'), get('{count, plural, one {# file} other {# files}}',
 * ['count' => 3]). A key its catalogs lack is said as it is. Not IINA's,
 * whose plugins have only Info.json's localized.
 */
final class StringsApi extends Api
{
    /** Message $key in the app's language, $args in its placeholders. @param array<string, mixed> $args */
    public function get(string $key, array $args = []): string
    {
        return $this->instance->plugin->string($key, $args);
    }

    /** The language the plugin's messages are said in: the app's (App::locale). */
    public function locale(): string
    {
        return \MyPHP\App::locale();
    }
}
