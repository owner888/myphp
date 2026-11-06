<?php
// A phone app in native UI: a tab bar, a navigation bar over a router's
// pages, a list of 200 000 rows, a form with the keyboard and the system's
// pickers, an HTTPS request, the sheets, and a settings page of inset
// grouped sections: the text styles, haptics, and the system's settings
// the theme follows. The same app runs on the
// desktop in a phone-sized window, where the list's scrolling is measured.
// Run: myphp run -d android examples/mobile   (ios, macos)
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, Image, ListState, Router, ScrollState, SVG, TextState};

spl_autoload_register(fn($c) => str_starts_with($c, 'MyPHP\\') && require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php'); // MyPHP's classes alone: a probe for another class (class_exists) is not a missing file

final class Mobile
{
    public const ROWS = 200_000;
    public int $tab = 0;
    public Router $router;
    public ListState $rows;
    public TextState $name;
    public ?Image $photo = null;
    public string $photoPath = '';
    /** @var list<string> */
    public array $files = [];
    public string $http = '';
    public bool $sheet = false, $actions = false, $alert = false;
    public string $lastAction = '';
    public ScrollState $meScroll;
    public bool $refreshing = false;
    public int $refreshedAt = 0, $refreshes = 0;
    public SVG $homeIcon, $formIcon, $meIcon;

    public function __construct()
    {
        $this->router = new Router('/home');
        $this->rows = new ListState;
        $this->name = new TextState;
        $this->meScroll = new ScrollState;
        $this->homeIcon = SVG::parse('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M3 11 12 3l9 8v10h-6v-6H9v6H3z"/></svg>');
        $this->formIcon = SVG::parse('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M4 4h16v4H4zm0 6h16v4H4zm0 6h10v4H4z"/></svg>');
        $this->meIcon = SVG::parse('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0z"/></svg>');
    }
    
    
    
    
    
}

$app = new Mobile;
App::setName('Mobile');
App::whenReady(function () use ($app): void {
    new Window(new WindowOptions(title: 'Mobile', width: 900, height: 640, content: Content::file(__DIR__ . '/view.php', $app))); // the desktop: a sidebar; a phone fills its screen
});
App::run();
