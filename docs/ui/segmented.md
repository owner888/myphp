# Segmented control

`$c->segmented()` creates a segmented control showing labels, of which an
`int` (by reference) is the index of the one chosen, as a switch between
views: a click chooses a segment, as do the arrows while it has the
keyboard focus, and `$c->changed()` reports a new choice.

```php
$app = new class { public int $view = 0; };
$view = function (Context $c) use ($app) {
    $c->segmented(['List', 'Grid'], $app->view)->label('View');
    switch ($app->view) {
        case 0: $c->text('The list'); break;
        case 1: $c->text('The grid'); break;
    }
};
```

It is one stop of Tab, which goes to the segment chosen.

## Icons and other content

For segments of icons or of your own look, build them with
`$c->segmentedBase()`, a `track` of `segment()`s without a look, each
built in until `end()`, the track too:

```php
$icons = [SVG::fromFile('icons/list.svg'), SVG::fromFile('icons/grid.svg')];
$app = new class { public int $view = 0; };
$view = function (Context $c) use ($app, $icons) {
    $t = $c->theme;
    $seg = $c->segmentedBase($app->view, 2);
    $seg->track->padding(3)->radius(8)->background($t->surface);
      foreach ($icons as $i => $icon) {
          $s = $seg->segment($i)->padding(4, 10)->radius(6)->label(['List', 'Grid'][$i]);
          if ($i === $app->view) {
              $s->background($t->background);
          }
            $c->icon($icon);
          $c->end();
      }
    $c->end();
};
```

See [custom widgets](custom-widgets.md).

## Accessibility

Assistive technology sees a radio group, named by its `label()`, of radio
buttons named by their labels.
