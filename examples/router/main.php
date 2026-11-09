<?php
// A router: a sidebar choosing the pages, which keeps the focus; Back and
// Forward buttons (a right click lists the pages), Cmd+[ and Cmd+], and a
// mouse's back and forward buttons; links to paths; notes sliding in
// deeper and back; a settings layout whose sections change inside it.
// Run: myphp run -d macos examples/router
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\Content;

// MyPHP, and its declarative views (VStack, Text…): loaded already in a phone's app, whose launcher loads them
class_exists(\MyPHP\App::class) || require __DIR__ . '/../../src/autoload.php';

/** The app's state: the pages over the notes, the notes they show, the name the Account section edits. */
final class Pages
{
    public NavigationPath $path;
    /** @var array<int, string> */
    public array $notes = [1 => 'Groceries', 2 => 'Ideas', 3 => 'Travel'];
    public string $name = '';

    public function __construct()
    {
        $this->path = new NavigationPath();
    }

    /** Shows $page alone over the notes, as choosing it in the sidebar does. */
    public function show(string $page): void
    {
        $this->path->removeLast($this->path->count());
        if ($page !== '/') { $this->path->append($page); }
    }
}

$s = new Pages();
App::setName('Router');
App::whenReady(function () use ($s) {
    new Window(new WindowOptions(title: 'MyPHP native — Router', width: 760, height: 460,
        content: Content::file(__DIR__ . '/view.php', $s)));
});
App::run();
