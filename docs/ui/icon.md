# Icon

`$c->icon()` shows an SVG as an icon: in the color of the text, which it
takes from its ancestors as text does, whatever colors the file has, and as
high as the font size, keeping the SVG's aspect ratio. Icon sets such as
Lucide, Heroicons, Tabler or Material Symbols work as they are. Parse the
SVG once, not in the view:

```php
$save = SVG::fromFile('icons/save.svg'); // or SVG::parse($markup)
$view = function (Context $c) use ($save) {
    $c->row(gap: 6);
      $c->icon($save);
      $c->text('Save');
    $c->end();
    $c->icon($save, 24, $c->theme->danger);
};
```

The second and third arguments size and color it, as `fontSize()` and
`textColor()` do on the icon; `size()` gives it another box, which it fits
in, centered. An icon does not stretch across a column.

## In buttons

A button of `$c->button()` shows a label only. An icon with a label goes in
a button of your own, built on `$c->buttonBase()`, a row that takes the
focus and reports `clicked()`, given its look:

```php
$save = SVG::fromFile('icons/save.svg');
$view = function (Context $c) use ($save) {
    $t = $c->theme;
    $b = $c->buttonBase()->padding($t->space(1.5), $t->space(3.5))->gap(6)->radius($t->radius)->background($t->accent)->textColor($t->accentText)->label('Save');
      $c->icon($save);
      $c->text('Save')->singleLine();
    $c->end();
    if ($c->clicked($b)) {
        // save
    }
};
```

`rotate()` turns an icon, as a spinner or an arrow that opens:

```php
$loader = SVG::fromFile('icons/loader.svg');
$view = function (Context $c) use ($loader) {
    $spin = $c->icon($loader);
    $spin->rotate($c->loop($spin, 'spin', 1.0, Easing::linear(...)) * 360);
};
```

How MyPHP draws SVGs, and what of them it draws, is on the
[Image](image.md#svgs) page. In `draw()` callbacks, `Painter::icon()` draws
an icon.

## Accessibility

Icons are decorations that assistive technology does not see, unless
`label()` names them: name a button showing only an icon instead.
