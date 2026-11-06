<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\Plugins\Game\Game;
use MyPHP\UI\Context;

return function (Context $c): void {
    Game::view($c, $this)->fill()->background(0x272c3f)->label('Mayoi');
};
