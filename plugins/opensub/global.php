<?php
// The app's instance (IINA's plugin-opensub global.js).
use MyPHP\PlayerPlugins\Iina;

return function (Iina $iina) {
    $iina->console->log('Plugin is running');
};
