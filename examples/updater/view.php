<?php
// The native window's view (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\Plugins\Updater\Updater;
use MyPHP\UI\Context;

return function (Context $c): void {
    $c->column(24, 12, 1);
    $c->text('Check from the app menu, or here:');
    $c->row();
    if ($c->clicked($c->button('Check for Updates…'))) {
        Updater::checkForUpdates();
    }
    $c->end();
    $c->end();
};
