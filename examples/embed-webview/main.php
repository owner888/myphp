<?php
// Web pages in a window of native UI (mygo's examples/embed-webview): a
// sidebar of tabs, each a web view, under a toolbar whose menu, select,
// tooltip and dialog show over the page, as the badge over its corner
// does. The notes page calls PHP and hears from it through the bridge.
// Run: ./run.sh embed-webview
declare(strict_types=1);

use MyPHP\{App, Event, Ipc, WebView, WebViewOptions, Window, WindowOptions};
use MyPHP\UI\{Content, Context, Font, Node};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

const ZOOMS = ['75%' => 0.75, '100%' => 1.0, '125%' => 1.25, '150%' => 1.5];
const MENU = ['Like the note', 'Open the dialog', 'Show a toast'];

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
    public bool $menu = false, $dialog = false;
    public int $zoom = 1;
    public int $likes = 0;
    /** Tells pages how many likes there are. */
    public Event $liked;

    public function __construct() { $this->liked = new Event('liked', 'int'); }
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

function view(Context $c, Browser $a): void
{
    $t = $c->theme;
    if ($a->tabs === []) { return; } // the web views come once the window shows
    $shown = $a->tabs[0];
    foreach ($a->tabs as $tb) { if ($tb->id === $a->tab) { $shown = $tb; } }
    $page = $shown->view->page();
    $c->row(0, 0, 1)->alignItems(Node::STRETCH);
      $c->sidebar($a->tab, function () use ($c, $a) {
          foreach ($a->tabs as $tb) { $c->sidebarItem($tb->id, $tb->title); }
      }, 180);
      $c->column(0, 0, 1)->minWidth(0)->minHeight(0);
        $c->row(0, 8)->padding(8, 12)->alignItems(Node::CENTER);
          if ($c->clicked($c->button('‹')->label('Back')->disabled(!$page->canGoBack()))) { $page->goBack(); }
          if ($c->clicked($c->button('›')->label('Forward')->disabled(!$page->canGoForward()))) { $page->goForward(); }
          if ($c->clicked($c->button('Reload'))) { $page->reload(); }
          $menu = $c->button('Menu ▾');
          if ($c->clicked($menu)) { $a->menu = !$a->menu; }
          // The popover shows over the page.
          $c->popover($menu, $a->menu, function () use ($c, $a, $t) {
              foreach (MENU as $item) {
                  $entry = $c->key($item)->row(0, 0)->padding(6, 12)->radius(5)->size(200, 0);
                  if ($c->hovered($entry)) { $entry->background($t->accent); }
                    $c->text($item, color: $c->hovered($entry) ? $t->accentText : $t->text);
                  $c->end();
                  if ($c->clicked($entry)) {
                      $a->menu = false;
                      match ($item) {
                          'Like the note' => App::later(fn() => (new Notes($a))->like()),
                          'Open the dialog' => $a->dialog = true,
                          default => $c->toast('Toasts show over the page too'),
                      };
                  }
              }
          });
          if ($c->changed($c->select(array_keys(ZOOMS), $a->zoom, 100))) {
              foreach ($a->tabs as $tb) { $tb->view->page()->setZoomFactor(array_values(ZOOMS)[$a->zoom]); }
          }
          $c->button('?')->label('Help')->tooltip('Web views show under what MyPHP paints after them.');
          $c->spacer();
          $c->muted("{$a->likes} likes");
        $c->end();
        // The page of the tab shown, rounded, with a badge over its corner; the others keep their pages.
        $c->column(0, 0, 1)->margin(0, 12, 12, 12);
          $c->webView($shown->view)->grow(1)->radius(12)->border(1, $t->border);
          $c->row(0, 0, 0, $t->accent)->absolute()->top(12)->right(16)->padding(4, 10)->radius(12)->shadow(0, 2, 8, 0, 0x000000, 60 / 255);
            $c->text("♥ {$a->likes}", 12, $t->accentText, Font::BOLD);
          $c->end();
        $c->end();
      $c->end();
    $c->end();
    $c->modal($a->dialog, function () use ($c, $a, $t) {
        $c->text('A dialog over the page', 18, style: Font::BOLD);
        $c->muted('Its backdrop takes the pointer: the page under it does not.');
        $c->row(0, 8)->justify(Node::END);
          if ($c->clicked($c->primaryButton('Done'))) { $a->dialog = false; }
        $c->end();
    });
}

$s = new Browser();
App::setFrontend(__DIR__ . '/frontend'); // a page loaded from a URL reloads, unlike one loaded as HTML
Ipc::bind(new Notes($s));
App::setName('Web views');
App::whenReady(function () use ($s) {
    $s->win = new Window(new WindowOptions(title: 'Web views', width: 1000, height: 680, minWidth: 560, content: Content::view(fn(Context $c) => view($c, $s))));
    $s->tabs = [
        new Tab('notes', 'Notes', $s->win->newWebView(new WebViewOptions('/notes.html'))),
        new Tab('web', 'example.com', $s->win->newWebView(new WebViewOptions('https://example.com'))),
    ];
    foreach ($s->tabs as $tb) { $tb->view->page()->on('didNavigate', fn() => $s->win->invalidate()); } // navigation changes what Back and Forward can do
    $s->win->invalidate();
});
App::run();
