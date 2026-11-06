# Pages and navigation

A `Router` keeps the history of the pages of a window, or of a part of
one, as a browser does for a tab. A page is a path, as `/notes/42`, which
`view()` matches to build the page shown, a chain of patterns whose
`{name}` takes one part of the path and `{name...}` the rest.

The router is part of your app's state: make it once, with the page to
show first, and keep it in a property. The view gives `view()` a table of
patterns and pages, the first pattern the path matches the page shown, as
SwiftUI's `navigationDestination` and Flutter's `go_router` do. A page is
a file of its own, which returns its view and runs bound to the window's
state, as `view.php` does (`Content::file`), and hot reloads:

<!-- no-test -->
```php
<?php // view.php — $this is the NotesApp app.php passes to Content::file
return function (Context $c): void {
    $c->row()->fill()->alignItems(Node::STRETCH);
      $sidebar = $this->router->path();
      $c->sidebar($sidebar, function () use ($c) { // stays as the pages change, see below
          $c->sidebarItem('/notes', 'Notes');
          $c->sidebarItem('/files', 'Files');
      }, 220)->onChange(fn() => $this->router->push($sidebar));
      $this->router->view($c, [
          '/notes'           => __DIR__ . '/pages/notes.php',
          '/notes/{id}'      => __DIR__ . '/pages/note.php',
          '/files/{path...}' => __DIR__ . '/pages/files.php',
          '*'                => __DIR__ . '/pages/not-found.php',
      ]);
    $c->end();
};
```

<!-- no-test -->
```php
<?php // pages/note.php — {id} of /notes/{id}, an int as the page asks
return function (Context $c, Route $r, int $id): void {
    $r->title('Note');
    $c->text("Note $id");
};
```

A page takes the `Context` first, then by their names the parts of the
path its pattern names (`{id}`, `{path...}`), as their types say (`int`,
`float`, `bool`, `string`), a default for one its pattern lacks, and the
`Route` by its type. `'*'` is the page of any other path; without it, a
path of no page shows so and says so in the log. A page can be a closure
in the table too, for a small one:

```php
final class NotesApp
{
    public Router $router;

    public function __construct()
    {
        $this->router = new Router('/notes');
    }
}

$app = new NotesApp();
$view = function (Context $c) use ($app) {
    $app->router->view($c, [
        '/notes' => function (Context $c, Route $r) { $r->title('Notes'); $c->text('All notes'); },
        '/notes/{id}' => function (Context $c, Route $r, int $id) { $r->title('Note'); $c->text("Note $id"); },
        '/files/{path...}' => fn(Context $c, string $path) => $c->text("Files in /$path"),
        '*' => fn(Context $c) => $c->text('Not found'),
    ]);
};
```

`view()` still takes a function of the `Route` instead, which tells the
pages apart itself with `$r->match($pattern)` and `$r->param($name)`.

And the window, as the app starts:

<!-- no-test -->
```php
App::whenReady(function () use ($app) {
    new Window(new WindowOptions(title: 'Notes', content: Content::file(__DIR__ . '/view.php', $app)));
});
App::run();
```

## Going to pages

`push()` goes to a page, after the page shown, `replace()` shows one in its
place, and `back()`, `forward()` and `go($n)` move through the history;
paths relative to the page shown resolve as links in a web page do, so
`push('?tab=info')` changes the query and `push('edit')` goes to a
sibling. A [link](link.md) to a path in a page goes there in its router,
and choosing an item of a [sidebar](sidebar.md) pushes its page, as the
sidebar of the app above does:

```php
// In a page:
$c->link('Open note', '/notes/42');
```

`path()`, `query($name)` and `location()` read the page shown;
`location()` with its query, which `new Router()` takes to show it again
when the app starts. `canGoBack()` and `canGoForward()` tell whether there
is a page to go to. A deep link (`App::onOpenURL()`) pushes its page.

## Back and forward

Users go back and forward as in browsers and Finder: Cmd+[ and Cmd+] on
macOS, Alt+Left and Alt+Right on Linux, the back and forward buttons of a
mouse and the keys of keyboards that have them, and the
[back and forward buttons](history-buttons.md), which a toolbar holds, and
whose right click lists the pages to go back or forward to, by their
titles. The keys go to the router holding the keyboard focus, else to the
first of the window.

## Layouts

Anything built around `view()`, as a sidebar or a toolbar, stays as pages
change. A page can also be a layout around the pages of the rest of its
path, as a page of settings with a list of its sections beside the section
shown: `view()` on its route builds them, matching what the pattern's
`{name...}` took. The layout stays as they change, keeping its state, and
they slide or fade in its place; `param()` reads the layout's wildcards
too. A page of settings, its sections a table of their own:

```php
$router = new Router('/settings/general');
$view = function (Context $c) use ($router) {
    $router->view($c, [
        '/settings/{section...}' => function (Context $c, Route $r) {
            $c->row(grow: 1);
              $c->column(gap: 4); // pushes "/settings/general", "/settings/fonts"
                $c->link('General', '/settings/general');
                $c->link('Fonts', '/settings/fonts');
              $c->end();
              $r->view($c, [ // the rest of the path: "/general", "/fonts"
                  '/general' => function (Context $c, Route $r) { $r->title('General'); $c->text('General settings'); },
                  '/fonts' => function (Context $c, Route $r) { $r->title('Fonts'); $c->text('Font settings'); },
              ]);
            $c->end();
        },
    ]);
};
```

A second `Router` inside a page, in a property of its own, is a history of
its own, as the detail of a split view going deeper while the list beside
it stays.

## Pages keep their state

A page keeps the state of its nodes while it is in the history, ten pages
either way (`Router::KEPT_PAGES`): going back to one finds it scrolled
where it was, with its text and the keyboard focus where they were. A path
with another query is the same page, in another entry of the history,
keeping its state, as a page whose tabs or search are in its query.

## The focus and screen readers

Going to a page moves the keyboard focus into it, to the node that had it
there, else to the page itself, which Tab goes into; screen readers read
the page's `title()`. With the focus outside the page that changes, as in a
sidebar or a layout's list choosing the pages, it stays there, and screen
readers hear the title of the page.

## Transitions

A page deeper in the paths, as `/notes/42` after `/notes`, slides in from
the right over the page it leaves, and back up from the left; others fade
in. With less motion asked of the desktop, all fade, and
`$router->transition = Router::NONE` shows them at once (`Router::FADE`
fades them all). The page going away takes neither the pointer nor the
keyboard as it slides.

`$router->animation` sets how pages move, as a spring or a curve (see
[Motion](motion.md)), in place of easing out over 250 ms as they slide and
150 ms as they fade:

```php
$router = new Router('/');
$router->animation = Animation::snappy();
```
