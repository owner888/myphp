<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\App;

// The tabs in the sidebar; the page of the one shown, rounded, a badge of likes over its corner, under a toolbar whose menu,
// picker, help, sheet and alert show over the page. The other tabs keep their pages.
return function () {
    if ($this->tabs === []) { return Spacer(); } // the web views come once the window shows
    $shown = $this->shown();
    $page = $shown->view->page();
    return NavigationSplitView(
        columnVisibility: $this->visibility,
        sidebar: ListView(selection: $this->tab)(
            Each($this->tabs, id: fn($tb) => $tb->id)(fn($tb) => Label($tb->title, systemImage: $tb->id === 'notes' ? 'note.text' : 'globe')->tag($tb->id)),
        )->navigationSplitViewColumnWidth(200),
        detail: WebView($shown->view)
            ->cornerRadius(12)->border(Color::separator)
            ->overlay(Text(verbatim: "♥ {$this->likes}")->font(Font::caption)->bold()->foregroundStyle(Color::white)
                ->padding(Edge::horizontal, 10)->padding(Edge::vertical, 4)->background(Color::accentColor)->clipShape(Capsule())->shadow(radius: 8)
                ->padding(Edge::top, 12)->padding(Edge::trailing, 16), alignment: Alignment::topTrailing)
            ->padding(Edge::horizontal, 12)->padding(Edge::bottom, 12)
            ->navigationTitle($shown->title)
            ->toolbar(
                Button(action: fn() => $page->goBack(), label: Label('Back', systemImage: 'chevron.left'))->disabled(!$page->canGoBack())->help('Back'),
                Button(action: fn() => $page->goForward(), label: Label('Forward', systemImage: 'chevron.right'))->disabled(!$page->canGoForward())->help('Forward'),
                Button(action: fn() => $page->reload(), label: Label('Reload', systemImage: 'arrow.clockwise'))->help('Reload'),
                Menu('Menu')(
                    Button('Like the note', action: fn() => App::later(fn() => (new Notes($this))->like())), // after the frame: it tells every page
                    Button('Open the sheet', action: fn() => $this->dialog = true),
                    Button('Show an alert', action: fn() => $this->alert = true),
                ),
                Picker('Zoom', selection: $this->zoom)(Each(array_keys(ZOOMS), id: fn($z) => $z)(fn(string $z) => Text(verbatim: $z)->tag(array_search($z, array_keys(ZOOMS), true))))->labelsHidden()
                    ->onChange(of: $this->zoom, action: fn() => $this->zoomed()),
                Button(action: fn() => null, label: Label('Help', systemImage: 'questionmark.circle'))->help('Web views show under what MyPHP paints after them.'),
                Text(verbatim: "{$this->likes} likes")->foregroundStyle(Color::secondary),
            )
            ->sheet(isPresented: $this->dialog, content: fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
                Text('A sheet over the page')->font(Font::title3)->bold(),
                Text('Its backdrop takes the pointer: the page under it does not.')->foregroundStyle(Color::secondary),
                HStack()(Spacer(), Button('Done', action: fn() => $this->dialog = false)->buttonStyle(ButtonStyle::borderedProminent)->keyboardShortcut(KeyEquivalent::return, modifiers: [])),
            )->padding(20)->frame(width: 360))
            ->alert('Alerts show over the page too', isPresented: $this->alert),
    );
};
