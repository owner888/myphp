# Scroll view

A `ScrollView` scrolls its content up and down, as SwiftUI's
(`ScrollView(Axis::horizontal)` across), with the wheel, the touchpad, its
scroll bar, a finger with inertia on a phone, and the keyboard: the arrow
keys, Page Up and Page Down, Space, Home and End scroll the view around the
focus, or under the pointer, unless the focused view takes those keys, as a
text field does. A notch of a mouse wheel glides over a short ease out, as
browsers scroll it; a touchpad follows the fingers; with Reduce Motion a
notch scrolls at once.

```php
$app = new class { public array $posts = ['First', 'Second', 'Third']; };
$view = fn() => ScrollView()(
    VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
        Each($app->posts)(fn(string $p) => Text(verbatim: $p)->padding()->frame(maxWidth: INF, alignment: Alignment::leading)->background(Color::secondarySystemBackground)->cornerRadius(8)),
    )->padding(),
);
```

A scroll view takes the room it is offered. For the rows of a collection,
a [list](list.md), which builds only those in view.

## Scrolling from code

`->scrollPosition($position)` binds a `ScrollPosition`, whose
`scrollTo(y:)` and `scrollTo(edge: Edge::bottom)` move the view, as
SwiftUI's; `->onScrollGeometryChange(for:, action:)` follows where it is.
A `ScrollViewReader` gives its content a proxy whose `scrollTo($id,
UnitPoint::center)` glides a list inside to a row:

```php
$app = new class { public array $rows; public function __construct() { $this->rows = array_map(fn($i) => ['id' => $i], range(1, 500)); } };
$view = fn() => ScrollViewReader(fn(ScrollViewProxy $proxy) => VStack()(
    Button('Go to 250', action: fn() => $proxy->scrollTo(250, UnitPoint::center)),
    ListView($app->rows, id: 'id')(fn(array $r) => Text(verbatim: "Row {$r['id']}")),
));
```

## Keeping the offset

A scroll view keeps where it was scrolled from frame to frame by its
identity, and while its page is in a `NavigationStack`'s history. The room a
phone's floating tab bar takes is added after the content, so the last
rows scroll above it.

## Scroll bars

On macOS the scroll bars follow the system's setting: overlaid and shown
while scrolling, or always shown; elsewhere they show while scrolling and
fade. A phone's are thin and show only while a finger scrolls.

## Accessibility

Assistive technology sees a scroll area, which it scrolls by pages.
