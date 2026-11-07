<?php
// Web pages in a window of native UI (mygo's examples/embed-webview): a
// sidebar of tabs, each a web view, under a toolbar whose menu, picker,
// tooltip, sheet and alert show over the page, as the badge over its
// corner does. The notes page calls PHP and hears from it through the bridge.
// Run: ./run.sh embed-webview
declare(strict_types=1);

use MyPHP\{App, Event, Ipc, WebView, WebViewOptions, Window, WindowOptions};
use MyPHP\UI\Content;

require __DIR__ . '/../../src/autoload.php'; // MyPHP, and its declarative views (VStack, Text…)

const ZOOMS = ['75%' => 0.75, '100%' => 1.0, '125%' => 1.25, '150%' => 1.5];

final class Tab
{
    public function __construct(public string $id, public string $title, public WebView $view) {}
}

final class Browser
{
    public ?Window $win = null;
    /** @var list<Tab> */
    public array $tabs = [];
    public string $tab = 'notes';
    public bool $dialog = false, $alert = false;
    public int $zoom = 1;
    public NavigationSplitViewVisibility $visibility = NavigationSplitViewVisibility::all;
    public int $likes = 0;
    /** Tells pages how many likes there are. */
    public Event $liked;

    public function __construct() { $this->liked = new Event('liked', 'int'); }

    /** The tab shown. */
    public function shown(): Tab
    {
        foreach ($this->tabs as $tb) { if ($tb->id === $this->tab) { return $tb; } }
        return $this->tabs[0];
    }

    /** Zooms every page by the zoom chosen. */
    public function zoomed(): void
    {
        foreach ($this->tabs as $tb) { $tb->view->page()->setZoomFactor(array_values(ZOOMS)[$this->zoom]); }
    }
}

/** Bound for the pages: like() counts a like and tells every page. */
final class Notes
{
    public function __construct(private Browser $b) {}

    public function like(): int
    {
        $this->b->liked->broadcast(++$this->b->likes);
        $this->b->win?->invalidate(); // the native badge shows them too
        return $this->b->likes;
    }
}

$s = new Browser();
App::setFrontend(__DIR__ . '/frontend'); // a page loaded from a URL reloads, unlike one loaded as HTML
Ipc::bind(new Notes($s));
App::setName('Web views');
App::whenReady(function () use ($s) {
    // The title bar hidden, the traffic lights inset over the sidebar, which the window's material shows through.
    $s->win = new Window(new WindowOptions(title: 'Web views', width: 1000, height: 680, minWidth: 560, titleBarStyle: 'hiddenInset', vibrancy: 'sidebar',
        content: Content::file(__DIR__ . '/view.php', $s)));
    $s->tabs = [
        new Tab('notes', 'Notes', $s->win->newWebView(new WebViewOptions('/notes.html'))),
        new Tab('web', 'example.com', $s->win->newWebView(new WebViewOptions('https://example.com'))),
    ];
    foreach ($s->tabs as $tb) { $tb->view->page()->on('didNavigate', fn() => $s->win->invalidate()); } // navigation changes what Back and Forward can do
    $s->win->invalidate();
});
App::run();
