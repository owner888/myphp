# Transforms

`translate()`, `scale()`, and `rotate()` change a node and everything
inside it visually, while layout reserves its original space. They work on
boxes, text, images, controls, and custom drawings. The pointer follows
what is drawn, including nested transforms and clips:

```php
$app = new class { public TextState $name; public function __construct() { $this->name = new TextState(); } };
$view = function (Context $c) use ($app) {
    $c->column(12, 8)->size(180, 110)
        ->scale(1.1, 0.9)->rotate(12)->translate(40, 0);
      $c->text('Transformed controls');
      $c->input($app->name);
    $c->end();
};
```

Transforms in a method chain act in call order: this example scales, then
rotates, then translates. A parent's transform acts after its children's.
Each scale and rotation uses the center of the node's border box by
default. `transformOrigin(0, 0)` uses its top-left; `(1, 1)` its
bottom-right. Other fractions, including values outside the box, are
allowed. Negative scales reflect content; a zero scale makes the node
invisible and gives it no pointer hit region. Transform values must be
finite.

For reusable compositions, build a `Transform` and set it with
`transform()`:

```php
$pose = Transform::scaling(1.2, 1.2)->then(Transform::rotation(15))->then(Transform::translation(30, 0));
$c->column()->size(60, 40)->transform($pose)->transformOrigin(0, 0);
$c->end();
```

`Transform::identity()` changes nothing. Setting `transform()` replaces the
node's previous transform; the convenience methods append to it. Set
transforms in the view, before the frame is laid out. `rotate()` on an icon
keeps its existing behavior of rotating the SVG artwork around its center.
To rotate an icon's whole border box, use
`transform(Transform::rotation($degrees))`.

## Skew and 3D

`skew($x, $y)` slants a node by degrees along each axis, as CSS's
`skew()`. `rotateX()` and `rotateY()` turn it in 3D around the horizontal
and vertical axes through its origin, and `perspective($distance)` after
them sees it from that many points in front: the shorter, the deeper; 800
or so looks natural. Without `perspective()`, a turned node only looks
narrower or shorter:

```php
$app = new class { public bool $flipped = true; };
$view = function (Context $c) use ($app) {
    $t = $c->theme;
    // A card turning over: its back, turned half a turn more, shows past
    // 90 degrees, and each face hides while it is turned away.
    $card = $c->column()->size(160, 100);
    $turn = $c->animateTo($card, 'turn', $app->flipped ? 180 : 0, Animation::snappy());
      $c->column()->background($t->surface)->absolute()->fill()->rotateY($turn)->perspective(800)->backfaceHidden();
      $c->end();
      $c->column()->background($t->accent)->absolute()->fill()->rotateY($turn + 180)->perspective(800)->backfaceHidden();
      $c->end();
    $c->end();
};
```

`Transform` values compose the same way, with `Transform::skewing()`,
`rotationX()`, `rotationY()` and `perspective()`. Each node flattens what
its 3D transform makes of it into its parent's plane, as CSS's default
`transform-style: flat`: a parent turning in 3D turns its children's
drawing with it. `backfaceHidden()` hides a node, and takes it from the
pointer, while its own 3D transform turns its back to the viewer.

The pointer, clips, text input and accessibility follow 3D transforms as
they follow 2D ones, and the renderer draws them in perspective, smooth
edges included.

## Coordinates, clips, and overlays

`$c->bounds($n)` is the previous frame's transformed bounding rectangle in
window points. `$c->layoutBounds($n)` is its box before transforms.
`$c->localToWindow()` and `$c->windowToLocal()` convert points relative to
that box; `windowToLocal()` returns null when the transform has no
inverse. `pointerPosition()`, `dragged()`, and `handleInput()` report local
positions and vectors. Captured pointer input stays local even outside the
transformed box.

`draw()` and `drawOver()` receive the original layout coordinates: the
painter applies the transform to everything they draw, including paths,
text, images, shadows, and effects. Clips retain the coordinate system in
which they were pushed and compose through nested nodes. Input method
candidate windows and accessibility receive the transformed bounding
rectangles of carets and nodes. Popovers stay upright in the overlay and
attach to the transformed bounds of their anchors, including during layout
transitions.

## Animating transforms

Transforms take animated values as well as fixed ones: a spring that keeps
its velocity, a curve, keyframes, or a value following the pointer (see
[Motion](motion.md)):

```php
$app = new class { public bool $expanded = true; };
$view = function (Context $c) use ($app) {
    $tile = $c->key('tile')->column()->size(80, 50)->background($c->theme->accent);
    $c->end();
    $tile->translate($c->animateTo($tile, 'x', $app->expanded ? 120 : 0, Animation::snappy()), 0);
};
```

[Transitions](transitions.md) scale and rotate nodes coming and going, and
move a node appearing from where another showed (`match`), with transforms
too.

## Rendering

Metal and OpenGL apply general transforms to their instanced geometry on
the GPU. Fills, borders, shadows, images, text, and effects keep local
coordinates; backdrop reads map back into window coordinates. Metal
preserves wide-gamut colors under transforms. Text is sampled bilinearly
when transformed. To zoom a whole window, sharp, use the content's zoom
(`$c->setZoom()`, see [Windows](windows.md#zoom)) rather than a transform.
