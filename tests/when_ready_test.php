<?php
// App::whenReady callbacks run each in a fiber of its own, as SwiftUI's .task: one that waits (a request, a sleep)
// suspends there and lets the launch go on. A request made in whenReady outside a fiber held App::ready(), and so
// the first frame and the callbacks after it, until it ended: a black screen on iOS (#27).
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\App;
use MyPHP\Native\Fibers;

$ready = new \ReflectionProperty(App::class, 'ready');
$was = $ready->getValue();
$ready->setValue(null, true); // launched: whenReady calls at once
try {
    $said = [];
    App::whenReady(function () use (&$said): void { $said[] = 'first starts'; Fibers::sleep(0.01); $said[] = 'first ends'; });
    App::whenReady(function () use (&$said): void { $said[] = 'second'; });
    same(['first starts', 'second'], $said, 'a callback that waits suspends, and the next one runs meanwhile');
    Fibers::drain();
    same(['first starts', 'second', 'first ends'], $said, 'it goes on once its wait is over');
} finally {
    $ready->setValue(null, $was);
}
