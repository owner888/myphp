<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\Plugins\Updater\Updater;

return fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
    Text('Check from the app menu, or here:'),
    Button('Check for Updates…', action: fn() => Updater::checkForUpdates()),
)->padding(24)->frame(maxWidth: INF, maxHeight: INF, alignment: Alignment::topLeading);
