<?php
// The window's view of the Pages main.php makes, $this in it (Content::file): a sidebar choosing the pages, and a
// NavigationStack of the notes, a note, the settings and About, each page a file of pages/; saved, each hot reloads.
declare(strict_types=1);

return fn() => HStack(spacing: 0)(
    VStack(alignment: HorizontalAlignment::leading, spacing: 6)(
        Button('Notes', action: fn() => $this->show('/')),
        Button('Settings', action: fn() => $this->show('/settings/general')),
        Button('About', action: fn() => $this->show('/about')),
        Spacer(),
    )->buttonStyle(ButtonStyle::borderless)->padding(12)->frame(width: 170, maxHeight: INF, alignment: Alignment::topLeading),
    Divider(),
    NavigationStack(path: $this->path)(
        ListView()(
            Section(header: 'Notes')(Each($this->notes)(fn(string $title, int $id) => NavigationLink($title, value: "/notes/$id"))),
        )->navigationTitle('Notes'),
    )
        ->navigationDestination(for: '/notes/{id}', destination: __DIR__ . '/pages/note.php')
        ->navigationDestination(for: '/settings/{section}', destination: __DIR__ . '/pages/settings.php')
        ->navigationDestination(for: '/about', destination: __DIR__ . '/pages/about.php'),
);
