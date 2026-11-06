<?php
// A scrolling inbox in native UI: the view is a function of $state, rebuilt
// on every frame, and frames happen when something changes. Hover the list
// for its scroll bar (drag the thumb); click a row to choose it, Cmd-click
// or Shift-click several.
// Run: ./run.sh inbox [rows]   (MYPHP_STATS=1 prints per-second frame stats; try 1000000 rows)
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, Image, ListState, Selection};

spl_autoload_register(fn($c) => str_starts_with($c, 'MyPHP\\') && require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php'); // MyPHP's classes alone: a probe for another class (class_exists) is not a missing file

$state = (object)['rows' => (int)($argv[1] ?? 300), 'replied' => [], 'list' => new ListState, 'last' => '', 'mailbox' => 'inbox', 'favorites' => true, 'local' => true];
$state->list->selected = -1; // rows can be chosen: click, Up/Down, Home/End; double click or Enter opens
$state->list->selection = new Selection; // several: Cmd-click adds or takes out, Shift-click and Shift+arrows extend, Cmd+A chooses all
$state->list->followEnd = true; // at the end, Compose (a new last row) keeps the end in view, as a chat does
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
