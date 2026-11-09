<?php
// Popovers from a small window of native UI (mygo's examples/flyout): a
// list of colors, a note to edit and an About box, as SwiftUI's popovers on
// a Mac, the system's there. They take the keyboard, and close as the user
// clicks elsewhere or presses Escape. They extend beyond the window, and
// flip or slide to stay on the screen.
// Run: myphp run -d macos examples/flyout
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\Content;

// MyPHP, and its declarative views (VStack, Text…): loaded already in a phone's app, whose launcher loads them
class_exists(\MyPHP\App::class) || require __DIR__ . '/../../src/autoload.php';

const COLORS = [['Red', 0xff3b30], ['Orange', 0xff9500], ['Yellow', 0xffcc00], ['Green', 0x34c759], ['Blue', 0x007aff], ['Purple', 0xaf52de]];

/** The app's state, read and changed on the main thread by the view (view.php) and its popovers. */
final class Flyouts
{
    public ?Window $win = null;
    /** Whether the list of colors, the note editor and the About box show. */
    public bool $choosing = false, $editing = false, $about = false;
    /** The chosen color. */
    public int $color = 0;
    public string $note = '';
}

$a = new Flyouts();
App::whenReady(function () use ($a) {
    $a->win = new Window(new WindowOptions(title: 'Flyout', width: 300, height: 240, content: Content::file(__DIR__ . '/view.php', $a)));
});
App::run();
