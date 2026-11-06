<?php
// Liquid Glass in native UI: a photo and a strip of colors scroll under a
// glass toolbar and a glass pill that a slider moves, so the blur, the
// bend near the edge and the lit rim show; Regular or Clear, tinted or not,
// and an interactive glass button that grows while pressed. A hard scroll
// edge at the bottom (macOS's, under a bar of controls) and a fading blur
// band in the middle use the same backdrop.
// Run: ./run.sh glass
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\Plugins\Glass\Glass;
use MyPHP\UI\{Content, Image, ScrollState};

require __DIR__ . '/../../src/autoload.php'; // MyPHP, and its declarative views (VStack, Text…)

$pictures = array_values(array_filter(glob('/System/Library/Desktop Pictures/*.heic') ?: [], fn($p) => filesize($p) < 30_000_000));
$pictures !== [] || throw new RuntimeException('no pictures under /System/Library/Desktop Pictures');
$s = (object)['photo' => Image::fromFile($pictures[0], 1600), 'x' => 40.0, 'clear' => false, 'tinted' => false, 'scroll' => new ScrollState, 'presses' => 0, 'hard' => true];

App::setName('Glass');
App::whenReady(function () use (&$s) {
    new Window(new WindowOptions(title: 'MyPHP native — Liquid Glass', width: 900, height: 620,
        content: Content::file(__DIR__ . '/view.php', $s)));
});
App::run();
