# Image

`$c->image()` shows a bitmap, an `Image`, or an SVG in its own colors, by
default at its size in points (its pixels over the window's scale), scaled
to fit when given another size:

<!-- no-test -->
```php
$logo = Image::fromFile(__DIR__ . '/logo.png'); // once, not in the view
$view = function (Context $c) use ($logo) {
    $c->image($logo, 64, 64, radius: 12)->fit(Node::CONTAIN);
};
```

Its arguments after the image are the width, the height, the radius of its
corners and its opacity.

## Bitmaps

`Image::fromFile()` and `Image::fromBytes()` decode PNG, JPEG, HEIC, GIF
(its first frame), WebP or whatever else the system reads, turning photos
upright as their camera's EXIF orientation says, at most 1024 pixels on a
side unless the second argument says otherwise, off the main thread: in a
fiber (`App::go()`) it waits while the app draws and takes events.
`Image::fromPixels($w, $h, $rgba)` makes one of premultiplied RGBA pixels,
four bytes a pixel, top row first. Make images once, not in the view:
MyPHP keeps an image on the GPU as long as you use it. An image shown much
smaller than its pixels, as a photo in a thumbnail, is drawn from a copy
halved as many times as that keeps it no smaller, made once, so that it
shows every pixel's part rather than shimmering.

An image keeps its pixels on the CPU as well as on the GPU, which
applications full of photos notice. Once an image `fromBytes()` made has
shown, `releasePixels()` drops its CPU copies, of the picture and of the
halved ones: it draws as before from the GPU, and the pixels are decoded
again from the bytes it took, which it keeps, when something needs them
again, as the image atlas starting over or a halved copy for drawing it
smaller, and an upload drops them again. The bytes cost a fraction of the
pixels. An image `fromPixels()` made, a canvas's, a symbol, or one
`update()` changed has nothing else to make its pixels from, and keeps
them. Call `releasePixels()` on the main thread, as drawing does:

<!-- no-test -->
```php
$cover = Image::fromBytes($fileData);
// … once the cover has shown:
$cover->releasePixels();
```

`update($w, $h, $rgba)` shows another picture in an image, for one that
changes as video does. Of the same size, it keeps the image's slot of the
atlas, which the GPU uploads, where a new image each frame would take a
slot each. Frames made outside the view reach the image through
`Window::update()`, which redraws the window:

```php
$frame = Image::fromPixels(2, 2, str_repeat("\xff\x00\x00\xff", 4));
$view = function (Context $c) use ($frame) {
    $frame->update(2, 2, str_repeat("\x00\x00\xff\xff", 4)); // as each frame comes
    $c->image($frame, 64, 64);
};
```

[`$c->video()`](video.md) plays a video's frames so, decoded as they come
due, with animated GIFs out of the box on macOS.

`pixelated()` draws an image shown larger than its pixels as squares of
their colors, as pixel art and game sprites are drawn, where it would
blend neighbors smoothly. Shown smaller, it is as smooth as any.

```php
$tiles = Image::fromPixels(16, 16, str_repeat("\x20\x80\x20\xff", 256))->pixelated();
$view = function (Context $c) use ($tiles) {
    $c->image($tiles, 64, 64); // 16×16 pixels, each 4×4
};
```

`Image::symbol('gearshape', 16)` draws one of the system's symbols, SF
Symbols on macOS and the GTK icon theme's on Linux, as an image.

## Fitting

`fit()` says how an image fills its box: `Node::CONTAIN` (the default)
fits it inside, `COVER` covers the box and crops the rest, `FILL_BOX`
stretches it, `SCALE_DOWN` shows it at its own size unless that does not
fit, and `NATURAL_SIZE` at its own size, cropped. `grayscale()` draws it in
shades of gray, as for what is disabled. `radius()` rounds its corners,
and `aspectRatio()` keeps its proportions in a box that grows.

```php
$photo = Image::fromPixels(4, 3, str_repeat("\x80\x80\x80\xff", 12));
$view = function (Context $c) use ($photo) {
    $c->image($photo)->aspectRatio(16 / 9)->fit(Node::COVER)->radius(8);
};
```

## SVGs

`SVG::parse()` parses an SVG, and `SVG::fromFile()` one in a file,
throwing when it is in error. `$c->image()` shows an SVG in its own
colors, with the text color for its `currentColor`, at the size the SVG
gives (its `width` and `height`, or those of its `viewBox`) unless given
another:

```php
$illustration = SVG::fromFile('icons/home.svg');
$view = function (Context $c) use ($illustration) {
    $c->image($illustration, 240);
};
```

To show an SVG in the color of the text, as an icon, use [Icon](icon.md).

MyPHP draws an SVG's shapes into the GPU's atlas once for each size it
shows at, the way it draws text, and the GPU draws them from there: moving
an image, scrolling it or changing its color draws nothing again. MyPHP
draws paths and the basic shapes, groups, `use` and `symbol` elements,
transforms, fills and strokes (with their joins, caps and dashes) of
colors, `currentColor` and linear and radial gradients, opacity, clip
paths, masks and style sheets of simple selectors (type, class and id), in
PHP. It leaves out text, embedded images, patterns, markers and filters:
convert text to paths in your editor before exporting.

In `draw()` callbacks, `Painter::image()` draws images and SVGs too.

## Accessibility

Images are decorations that assistive technology does not see, unless
`label()` names them, as a photo that matters.
