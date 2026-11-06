<?php
// The window's view of the Terminal main.php starts, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\Plugins\Terminal\Terminal;
use MyPHP\UI\Context;

return function (Context $c): void {
    $v = Terminal::view($c, $this)->autoFocus();
    $v->grow = 1;
};
