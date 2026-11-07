<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

// The materials in a sidebar the window's material shows through; the one chosen, and how to choose it in code. The sidebar hides
// with the button by the window controls, the title then starting left of them.
return function () {
    [$value, $label, $about] = $this->find();
    return NavigationSplitView(
        columnVisibility: $this->visibility,
        sidebar: ListView(selection: $this->chosen)(
            Each(sections(), id: fn($s) => $s[0])(fn(array $s) => Section($s[0])(
                Each($s[2], id: fn($m) => $m[0])(fn(array $m) => Label($m[1], image: $s[1])->help($m[2])->tag($m[0])),
            )),
        )->navigationSplitViewColumnWidth(220),
        detail: ScrollView()(VStack(alignment: HorizontalAlignment::leading, spacing: 16)(
            Text(verbatim: $about)->font(Font::system(size: 15)),
            Text(verbatim: "new WindowOptions(vibrancy: '$value')  // or \$win->setVibrancy('$value')")->font(Font::caption)->monospaced()
                ->padding(Edge::horizontal, 14)->padding(Edge::vertical, 12)->background(Color::gray->opacity(0.12))->cornerRadius(8)->textSelection(TextSelectability::enabled),
            Text('A material shows wherever the view draws no background: the sidebar has none, this pane has one.'),
            Toggle('Show the material behind this pane too', isOn: $this->seeThrough)->toggleStyle(ToggleStyle::checkbox)->disabled(!$this->vibrant),
            Text($this->vibrant ? 'The material is the system\'s, behind the window, and follows the appearance and the desktop.'
                : 'This window shows no material here, so the sidebar draws a background of its own: materials show on macOS.')->font(Font::caption)->foregroundStyle(Color::secondary)->padding(Edge::top, 8),
        )->padding(Edge::horizontal, 28)->padding(Edge::vertical, 24)->frame(maxWidth: 560, alignment: Alignment::leading))
            ->frame(maxWidth: INF, maxHeight: INF, alignment: Alignment::topLeading)
            ->background($this->seeThrough && $this->vibrant ? Color::clear : Color::background) // the material behind it, or the window's background over it
            ->navigationTitle($label),
    )->onChange(of: $this->chosen, action: fn() => $this->win?->setVibrancy($this->chosen));
};
