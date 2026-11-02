# Grid

`$c->grid()` lays its children out in columns and rows, as CSS grid does:
they fill its cells row by row, and it adds rows as they need them. An int,
as `$c->grid(3)`, makes three columns of equal width; an array of `Track`s
sets them one by one, as `Track::fixed(220)` points, `Track::fr(1)`, a
share of the room the others leave, or `Track::fit()`, as wide as their
content. `end()` closes it, as any container:

```php
$photos = array_fill(0, 6, null);
$view = function (Context $c) use ($photos) {
    $c->grid(3, 12);
      foreach ($photos as $p) {
          $c->box(0, 0, $c->theme->surface)->aspectRatio(1)->radius(8);
      }
    $c->end();
};
```

```php
$app = new class {
    public TextState $email;
    public TextState $bio;
    public function __construct() { $this->email = new TextState(); $this->bio = new TextState(); }
};
$view = function (Context $c) use ($app) {
    $c->grid([Track::fit(), Track::fr(1)])->gapX(16)->gapY(8);
      $c->text('Name')->bold()->columnSpan(-1); // across every column
      $c->text('Email');
      $c->input($app->email);
      $c->text('Bio');
      $c->textarea($app->bio)->rowSpan(2);
    $c->end();
};
```

`columns($n)` and `columnTracks(...)` on the node set the columns too.

## Placing children

`columnSpan()` and `rowSpan()` make a child span tracks, a negative span
every track to the last, and `columnStart()` and `rowStart()` put it in a
given column and row, counting from 1. The third argument of `grid()`, or
`rowTracks()` and `rows()`, set the first rows, which `Track::fr()` makes
share the grid's height.

## Sizing children

A child's percentages, as `fillWidth()`, `widthPercent()` and
`heightPercent()`, their `Percent` minimums and maximums, and
`leftPercent()` and the like, are of its cell, the tracks it spans, not of
the grid, as in CSS. A `Track::fit()` track sizes to such a child's
content as if it had no size set, and the child then takes its share of
the track.

## Aligning

Children stretch to fill their cells unless `justifyItems()`
(horizontally) and `alignItems()` (vertically) say otherwise, or
`justifySelf()` and `alignSelf()` for one; automatic margins center them.
`alignItems(Node::BASELINE)` lines up the first lines of text of the items
of each row, as in a [row](layout.md#alignment). Tracks that fit their
content share the room left over unless `justify()` or `alignContent()`
place them instead.

A grid builds all its children. For a grid of many items, built only as
they show, use a [grid view](grid-view.md); for labels beside controls, a
[form](form.md).
