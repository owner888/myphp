<?php
// A scrolling inbox in native UI: the view is a function of $state, rebuilt
// on every frame, and frames happen when something changes. Hover the list
// for its scroll bar (drag the thumb); click a row to choose it, Cmd-click
// or Shift-click several.
// Run: myphp run -d macos examples/inbox [rows]   (MYPHP_STATS=1 prints per-second frame stats; try 1000000 rows)
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, Image};

require __DIR__ . '/../../src/autoload.php'; // MyPHP, and its declarative views (VStack, Text…)

// chosen: the rows chosen, an array, so several: Cmd-click adds or takes out, Shift-click and Shift+arrows extend, Cmd+A chooses all
$state = (object)['rows' => (int)($argv[1] ?? 300), 'replied' => [], 'chosen' => [], 'last' => '', 'mailbox' => 'inbox'];
// Avatars: a few system app icons (ICNS, decoded by ImageIO at 64 px), made once and kept. Apps whose
// icon lives in Assets.car ship a tiny, fully transparent icns as a placeholder: those are skipped by size.
$icons = array_filter(glob('/System/Applications/*.app/Contents/Resources/AppIcon.icns'), fn($p) => filesize($p) > 20000);
$state->avatars = array_map(fn($p) => Image::fromFile($p, 64), array_slice(array_values($icons), 0, 12));
$state->avatars !== [] || throw new RuntimeException('no app icons under /System/Applications');

App::setName('Inbox');
App::whenReady(function () use ($state) {
    new Window(new WindowOptions(
        title: 'MyPHP native — scroll, click, hover',
        width: 900,
        height: 640,
        content: Content::file(__DIR__ . '/view.php', $state),
    ));
});
App::run();
