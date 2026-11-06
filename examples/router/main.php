<?php
// A router: a sidebar choosing the pages, which keeps the focus; Back and
// Forward buttons (a right click lists the pages), Cmd+[ and Cmd+], and a
// mouse's back and forward buttons; links to paths; notes sliding in
// deeper and back; a settings layout whose sections change inside it.
// Run: ./run.sh router
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, Router, TextState};

spl_autoload_register(fn($c) => str_starts_with($c, 'MyPHP\\') && require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php'); // MyPHP's classes alone: a probe for another class (class_exists) is not a missing file

/** The app's state: the router, the notes it shows, the name the Account section edits. */
final class Pages
{
    public Router $router;
    /** @var array<int, string> */
    public array $notes = [1 => 'Groceries', 2 => 'Ideas', 3 => 'Travel'];
    public TextState $name;

    public function __construct()
    {
        $this->router = new Router('/notes');
        $this->name = new TextState('');
    }
}

$s = new Pages();
App::setName('Router');
App::whenReady(function () use ($s) {
    new Window(new WindowOptions(title: 'MyPHP native — Router', width: 760, height: 460,
        content: Content::file(__DIR__ . '/view.php', $s)));
});
App::run();
