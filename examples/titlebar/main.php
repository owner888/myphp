<?php
// A window with a hidden title bar: the traffic lights stay, over a title
// bar the view draws itself, which drags the window (double-click zooms),
// keeping clear of the lights; a toggle moves to the inset style. In full
// screen the lights hide and the bar's room goes to zero.
// Run: ./run.sh titlebar [hidden|hiddenInset] [x,y]   — x,y moves the traffic lights (their top-left corner from the window's)
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, TextState};

spl_autoload_register(fn($c) => str_starts_with($c, 'MyPHP\\') && require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php'); // MyPHP's classes alone: a probe for another class (class_exists) is not a missing file

$style = $argv[1] ?? 'hidden';
$lights = isset($argv[2]) ? array_map('floatval', explode(',', $argv[2])) : null;
$s = (object)['count' => 0, 'query' => new TextState, 'style' => $style];

App::whenReady(function () use ($s, $style, $lights) {
    new Window(new WindowOptions(
        title: 'MyPHP — title bar',
        width: 720,
        height: 420,
        titleBarStyle: $style,
        trafficLightPosition: $lights,
        content: Content::file(__DIR__ . '/view.php', $s),
    ));
});
App::run();
