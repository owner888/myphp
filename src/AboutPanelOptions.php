<?php
declare(strict_types=1);

namespace MyPHP;

/** What App::showAboutPanel shows in place of the bundle's (mygo's AboutPanelOptions); what is '' the panel takes from Info.plist. */
final class AboutPanelOptions
{
    public function __construct(
        public string $applicationName = '',
        public string $applicationVersion = '',
        /** The build version, shown next to $applicationVersion. */
        public string $version = '',
        public string $copyright = '',
        public string $credits = '',
    ) {}
}
