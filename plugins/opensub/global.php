<?php
// The app's instance (IINA's plugin-opensub global.js).
use MyPHP\PlayerPlugins\PlayerContext;

return function (PlayerContext $iina) {
    $iina->console->log('Plugin is running');
};
