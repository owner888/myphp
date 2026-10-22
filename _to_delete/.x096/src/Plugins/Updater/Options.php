<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Updater;

/** Configure the plugin (mygo's updater.Options). */
final class Options
{
    /** @param array<string, Strings> $strings */
    public function __construct(
        /** Seconds between automatic checks: a day when 0. */
        public float $interval = 0,
        /** Turns automatic checks off until Updater::setAutomaticChecks() turns them on, for apps that ask the user first or only check from the menu. */
        public bool $disableAutomaticChecks = false,
        /** The PNG image shown in the update window: icon.png of the app (Resources/app/icon.png in its bundle) when null, when there is one. */
        public ?string $icon = null,
        /** The language of the window, a tag such as "fr" or "zh-Hant": the user's (App::locale()) when empty, as the plugin has it among its own and those of $strings, else English. */
        public string $language = '',
        /** Change the texts of the window by language tag, or add languages; their empty texts keep the plugin's, else the English ones. */
        public array $strings = [],
    ) {}
}
