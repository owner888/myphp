<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

// The title bar is the view's own: as high as the window's controls (or 32), clear of them, and a handle that moves the
// window, as SwiftUI's WindowDragGesture; the field and the button in it keep their presses.
return fn() => GeometryReader(fn(GeometryProxy $g) => VStack(spacing: 0)(
    HStack(spacing: 8)(
        Text(verbatim: "Title bar: {$this->style}")->bold(),
        Text(verbatim: sprintf('controls take %d × %d', $g->titleBar['left'], $g->titleBar['height']))->foregroundStyle(Color::secondary),
        Spacer(),
        TextField('Search', text: $this->query)->frame(width: 180),
        Button('Count', action: fn() => $this->count++),
    )->padding(Edge::leading, $g->titleBar['left'] + 8)->padding(Edge::trailing, $g->titleBar['right'] + 12)
        ->frame(height: max($g->titleBar['height'], 32))->background(Color::gray->opacity(0.12))->gesture(WindowDragGesture()),
    Divider(),
    VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
        Text('Drag the bar above to move the window; double-click it to zoom.'),
        Text(verbatim: "Counted {$this->count}; searching “{$this->query}”.")->foregroundStyle(Color::secondary),
    )->padding(20)->frame(maxWidth: INF, maxHeight: INF, alignment: Alignment::topLeading),
));
