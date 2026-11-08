# Pages and navigation

A `NavigationStack` shows a history of pages, as SwiftUI's: its root view,
and the pages pushed over it, each with its title in the bar and a way
back. Its path is a `Router`, part of the state, which keeps the history as
a browser does for a tab: a page is a path, as `/notes/42`, which the
stack's `navigationDestination(for:)` patterns match, `{name}` taking one
part of the path and `{name...}` the rest. A page is a file of its own,
which returns its view and runs bound to the state, as `view.php` does,
and hot reloads:

<!-- no-test -->
```php
<?php // view.php — $this is the NotesApp app.php passes to Content::file
return fn() => NavigationStack(path: $this->path)(
    ListView($this->notes, id: 'id')(fn(array $n) => NavigationLink($n['title'], value: "/notes/{$n['id']}"))->navigationTitle('Notes'),
)
    ->navigationDestination(for: '/notes/{id}', destination: __DIR__ . '/pages/note.php')
    ->navigationDestination(for: '/files/{path...}', destination: __DIR__ . '/pages/files.php');
```

<!-- no-test -->
```php
<?php // pages/note.php — {id} of /notes/{id}, by name and type
return fn(int $id) => Text($this->notes[$id]['body'])->padding()->navigationTitle($this->notes[$id]['title']);
```

A destination is a file, or a closure taking the path's parts by name and
type:

```php
$app = new class {
    public Router $path; public array $notes = [1 => 'Groceries', 2 => 'Ideas'];
    public function __construct() { $this->path = new Router('/'); }
};
$view = fn() => NavigationStack(path: $app->path)(
    ListView()(Each($app->notes)(fn(string $title, int $id) => NavigationLink($title, value: "/notes/$id")))->navigationTitle('Notes'),
)->navigationDestination(for: '/notes/{id}', destination: fn(int $id) => Text($app->notes[$id] ?? 'No such note')->padding()->navigationTitle('Note'));
```

## Going to pages

`NavigationLink($title, value: $path)` pushes a page; from an action,
`$this->path->push('/notes/42')`, `replace()` shows one in place of the
page shown, and `back()`, `forward()` and `go($n)` move through the
history. Paths relative to the page shown resolve as links in a web page
do: `push('?tab=info')` changes the query, `push('edit')` goes to a
sibling. `path()`, `query($name)` and `location()` read the page shown;
`canGoBack()` and `canGoForward()` tell whether there is one to go to. A
deep link (`App::onOpenURL()`) pushes its page.

## Back and forward

On a phone the bar's back button and a swipe from the screen's start edge
go back; on the desktop ⌘[ and ⌘] on macOS, Alt+Left and Alt+Right on
Linux, the back and forward buttons of a mouse; on Android the Back button
too, after the keyboard and what is open over the page. The keys go to the
stack holding the keyboard focus, else to the first of the window.

## Titles and bars

`->navigationTitle()` on a page is its title in the bar;
`->navigationBarTitleDisplayMode(TitleDisplayMode::large)` makes it a large
title in the page's scroll, going under the bar as it scrolls, as iOS's;
`->toolbar()` puts buttons in the bar. On the desktop the bar is the
window's toolbar.

## Pages keep their state

A page keeps the state of its views while it is in the history, ten pages
either way (`Router::KEPT_PAGES`): going back to one finds it scrolled
where it was, with its text and the keyboard focus where they were. A path
with another query is the same page, in another entry of the history.

## The focus and screen readers

Going to a page moves the keyboard focus into it, to the view that had it
there, else to the page itself; screen readers read the page's title.

## Transitions

Pages slide in from the trailing edge and the one behind dims, as UIKit's;
going back they slide out. With Reduce Motion they settle at once. An app's
top-level pages are not a stack but a `TabView` ([tabs](tabs.md)); a
sidebar of the app's data, a `NavigationSplitView` ([sidebar](sidebar.md)).
