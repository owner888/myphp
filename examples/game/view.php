<?php
// The window's view of the Meadow main.php makes, $this in it (Content::file): saved, it hot reloads; the game's update and draw are Meadow's.
declare(strict_types=1);

use MyPHP\Plugins\Game\Game;
use MyPHP\UI\Context;

return function (Context $c): void {
    Game::view($c, $this)->grow = 1;
};
