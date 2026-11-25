<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\Plugins\Updater\Updater;

$autoBinding = new Binding(
    get: fn(): bool => Updater::automaticDownloads(),
    set: fn(bool $on) => Updater::setAutomaticDownloads($on),
);

return fn() => TabView(selection: $this->tab)(
    Tab('Overview', systemImage: 'sparkles', value: 'home')(
        VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
            Text('Hover the update pill at the bottom-left of the sidebar to expand the Arc update card, or check via window:'),
            HStack(spacing: 10)(
                Button('Check for Updates…', action: fn() => Updater::checkForUpdates()),
                $this->available === '' ? Button('Simulate New Version', action: function (): void {
                    $this->available = '9.9.9';
                    $this->status = 'available';
                    $this->progress = 0.0;
                }) : null,
            ),
        )->padding(24)->frame(maxWidth: INF, maxHeight: INF, alignment: Alignment::topLeading)->navigationTitle('Overview'),
    ),
    Tab('Settings', systemImage: 'gearshape', value: 'settings')(
        Form()(
            Section('Software Update')(
                Toggle('Automatic Updates', isOn: $autoBinding),
            ),
        )->navigationTitle('Settings'),
    ),
)
    ->tabViewStyle(TabViewStyle::sidebarAdaptable)
    ->bottomAccessory(fn() => UpdateCapsule(
        version: $this->available,
        status: $this->status,
        progress: $this->progress,
        onAction: $this->install,
    ));
