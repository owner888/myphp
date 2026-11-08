# Layout

Views lay out as SwiftUI's do, in points: a stack offers its children room,
each takes what it needs, and a flexible view (`Spacer`, a `ListView`, a
`ScrollView`, a `frame(maxWidth: INF)`) takes the rest.

```php
$view = fn() => HStack(spacing: 8)(
    Image(systemName: 'folder'),
    Text('Documents'),
    Spacer(),
    Text('12 items')->foregroundStyle(Color::secondary),
)->padding(12);
```

## Stacks

- `VStack(alignment:, spacing:)` stacks its children from top to bottom,
  `HStack(alignment:, spacing:)` from the leading edge, `ZStack(alignment:)`
  over one another. Their `spacing:` is the gap between children; without
  it, the platform's.
- `Spacer(minLength:)` takes the free space of its stack, pushing its
  siblings apart; `Divider()` draws a thin line across its stack.
- `LazyVGrid(columns:)` lays its children out in columns ([grid](grid.md));
  `LazyVStack` builds only the rows in view. Containers that scroll are
  [scroll views](scroll.md) and [lists](list.md).

## Sizes

A view is as large as its content. `->frame(width:, height:)` gives it a
size; `->frame(minWidth:, maxWidth:, minHeight:, maxHeight:)` bounds it, and
`maxWidth: INF` makes it take the width it is offered, as SwiftUI's. A stack
holding a flexible view becomes flexible the same way, so an `HStack` of a
sidebar and a `NavigationStack` fills the window.

```php
$view = fn() => VStack(spacing: 0)(
    Text('Header')->padding()->frame(maxWidth: INF, alignment: Alignment::leading)->background(Color::secondarySystemBackground),
    Text('Body')->frame(maxWidth: INF, maxHeight: INF),
);
```

A `Text` wraps when its stack is narrower than it; `->lineLimit(1)` keeps
it on one line, shortened with an ellipsis.

## Spacing

`->padding()` pads a view on every side by the platform's padding;
`->padding(16)` by 16 points; `->padding(Edge::horizontal, 12)` on two
sides, as SwiftUI's. A padding after a background pads outside it, one
before it inside: modifiers apply in order.

## Alignment

A `VStack`'s `alignment:` (`HorizontalAlignment::leading`, `center`,
`trailing`) aligns its children across it; an `HStack`'s
(`VerticalAlignment::top`, `center`, `bottom`) does the same down it. A
`frame()`'s `alignment:` (`Alignment::topLeading`…) places the view inside
the frame. Leading and trailing follow the language: right to left, leading
is the right ([right to left](right-to-left.md)).

## Overlays and offsets

`->overlay($view, alignment:)` draws a view over another, sized by it, as a
badge on an icon; `->background($view)` one behind it. `->offset(x:, y:)`
moves a view where it draws without moving the others, as SwiftUI's.

```php
$view = fn() => Image(systemName: 'bell')->font(Font::title)
    ->overlay(Circle()->fill(Color::red)->frame(width: 8, height: 8), alignment: Alignment::topTrailing)->padding();
```

## Clipping and visibility

`->clipShape(RoundedRectangle(cornerRadius: 12))` and `->cornerRadius(12)`
cut what a view draws outside its shape. `->hidden()` keeps a view's room
but draws nothing; `$cond ? $view : null` takes it out.
