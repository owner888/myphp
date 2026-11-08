<?php
// Notes is a notes app in native UI (mygo's examples/notes): notes in a
// sidebar, each edited as rich text (MyPHP\UI\RichText), with a toolbar of
// its formats, the Format menu, links, Markdown shortcuts and export, and
// the notes saved as JSON in the app's folder of user data. The search
// field over the sidebar filters the notes; Cmd+N makes one, and
// Cmd+Backspace deletes the one shown, once you say so.
// Run: myphp run -d macos examples/notes
declare(strict_types=1);

use MyPHP\{App, Menu, MenuItem, PathName, Window, WindowOptions};
use MyPHP\UI\Content;

require __DIR__ . '/../../src/autoload.php'; // MyPHP, and its declarative views (VStack, Text…)
require __DIR__ . '/src/Note.php';
require __DIR__ . '/src/Notes.php';

App::setName('Notes'); // before App::path(), which is named after the app
$s = new Notes(App::path(PathName::UserData) . '/notes.json');
App::whenReady(function () use ($s) {
    App::setMenu(new Menu([
        new MenuItem(role: 'appMenu'),
        new MenuItem(role: 'fileMenu'),
        new MenuItem(role: 'editMenu'),
        new MenuItem(role: 'formatMenu'),
        new MenuItem(role: 'windowMenu'),
    ]));
    new Window(new WindowOptions(title: 'Notes', width: 900, height: 640, minWidth: 560, minHeight: 400, stateKey: 'notes',
        content: Content::file(__DIR__ . '/view.php', $s)));
});
App::run();
