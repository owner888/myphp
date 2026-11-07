<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\{App, InstalledApp};

/** A shortcut spelled as macOS does: ⌥⇧Space. */
$keys = fn(string $shortcut): string => strtr($shortcut, ['CmdOrCtrl+' => '⌘', 'Cmd+' => '⌘', 'Ctrl+' => '⌃', 'Alt+' => '⌥', 'Shift+' => '⇧']);

/** An app: its icon (its initial while it has none), its name, and where it is. */
$row = fn(InstalledApp $app) => HStack(spacing: 12)(
    ($icon = $this->icon($app)) !== null
        ? Image($icon)->resizable()->frame(width: ICON_SIZE, height: ICON_SIZE)
        : Text(verbatim: mb_strtoupper(mb_substr($app->name, 0, 1)))->bold()->frame(width: ICON_SIZE, height: ICON_SIZE)->background(Color::gray->opacity(0.3))->cornerRadius(ICON_SIZE / 2),
    VStack(alignment: HorizontalAlignment::leading, spacing: 1)(
        Text(verbatim: $app->name),
        Text(verbatim: where($app))->font(Font::system(size: 11))->foregroundStyle(Color::secondary),
    )->lineLimit(1),
    Spacer(),
);

return fn() => ScrollViewReader(fn(ScrollViewProxy $proxy) => VStack(spacing: 0)(
    HStack(spacing: 12)(
        Image($this->searchIcon)->font(Font::system(size: 17))->foregroundStyle(Color::secondary),
        TextField('Search apps', text: $this->query)->textFieldStyle(TextFieldStyle::plain)->font(Font::system(size: 22))
            ->focused($this->searching)
            ->onSubmit($this->open(...))
            // The arrows choose; an Escape with a query clears it, left to the panel it closes it, which hides it; ⌘Q quits.
            ->onKeyPress(KeyEquivalent::downArrow, function () use ($proxy) { $this->choose($this->chosen() + 1); $this->chosen === null ?: $proxy->scrollTo($this->chosen); return KeyPressResult::handled; })
            ->onKeyPress(KeyEquivalent::upArrow, function () use ($proxy) { $this->choose($this->chosen() - 1); $this->chosen === null ?: $proxy->scrollTo($this->chosen); return KeyPressResult::handled; })
            ->onKeyPress(KeyEquivalent::escape, function () {
                if ($this->query === '') { return KeyPressResult::ignored; }
                $this->query = ''; $this->filter();
                return KeyPressResult::handled;
            })
            ->onKeyPress('q', function (KeyPress $k) {
                if (!in_array(EventModifiers::command, $k->modifiers, true)) { return KeyPressResult::ignored; }
                App::quit();
                return KeyPressResult::handled;
            }),
    )->padding(Edge::horizontal, 18)->padding(Edge::vertical, 16),
    Divider(),
    $this->results === []
        ? Text($this->loading ? 'Finding apps…' : 'No apps found')->foregroundStyle(Color::secondary)->padding(16)->frame(maxWidth: INF, maxHeight: INF, alignment: Alignment::topLeading)
        : ListView($this->results, id: 'path', selection: $this->chosen)($row)->listStyle(ListStyle::plain)->padding(6)
            ->contextMenu(primaryAction: fn() => $this->open()),
    Divider(),
    HStack(spacing: 16)(
        Text(verbatim: $keys($this->shortcut) . ' to show or hide'),
        Spacer(),
        Text(verbatim: '↑↓ Choose   ↵ Open   esc Close'),
    )->font(Font::system(size: 11))->foregroundStyle(Color::secondary)->padding(Edge::horizontal, 16)->padding(Edge::vertical, 8),
))->onChange(of: $this->query, action: $this->filter(...));
