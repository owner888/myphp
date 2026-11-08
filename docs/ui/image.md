# Image

`Image()` shows a picture, as SwiftUI's: an `Image` (decoded once, outside
the view), an `SVG`, a file, or one of the system's symbols. It shows at its
own size; `->resizable()` makes it fill the box `->frame()` gives it, and
`->scaledToFit()` keeps its proportions inside it.

```php
$photo = Image::fromPixels(4, 3, str_repeat("\x80\x80\x80\xff", 12)); // once, not in the view
$view = fn() => HStack(spacing: 12)(
    Image($photo)->resizable()->scaledToFit()->frame(width: 160, height: 90)->cornerRadius(8),
    Image(systemName: 'photo'),
)->padding();
```

`Image(file: $path)` decodes a file the first time it shows. `Image::fromFile()`
and `Image::fromBytes()` decode PNG, JPEG, HEIC, GIF and the other formats
the system decodes (macOS); `Image::fromPixels($w, $h, $rgba)` makes one of
premultiplied RGBA pixels anywhere, which `->update()` changes in place, as
the frames of a camera. `->pixelated()` on the `Image` draws one shown
larger than its pixels as squares of their colors, as pixel art.

`Image(systemName: 'gearshape')` is an SF Symbol on Apple platforms, the GTK
icon theme's on Linux and a Material Symbol on Android, in the text's color
and size ([icon](icon.md)).

## SVGs

`SVG::parse()` parses an SVG, and `SVG::fromFile()` one in a file, once:
`Image($svg)` draws it in its own colors. MyPHP draws paths, shapes,
strokes, fills, gradients and transforms; not text, filters or masks.

## Accessibility

A picture is a decoration that assistive technology does not see unless
`->accessibilityLabel()` names it.
