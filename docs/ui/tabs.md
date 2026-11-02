# Tabs

`$c->tabs()` creates a row of tabs showing labels, of which an `int` (by
reference) is the index of the one chosen. A click chooses a tab, as do
the arrows, Home and End while one has the keyboard focus, which follows
the choice; `$c->changed()` reports a new choice. Build the page of the
tab chosen below them:

```php
$app = new class { public int $tab = 0; };
$view = function (Context $c) use ($app) {
    $c->tabs(['General', 'Appearance', 'Advanced'], $app->tab);
    switch ($app->tab) {
        case 0: $c->text('General settings'); break;
        case 1: $c->text('Appearance settings'); break;
        case 2: $c->text('Advanced settings'); break;
    }
};
```

The tabs are one stop of Tab, which goes to the tab chosen. For pages with
a history, which Back returns to, use a [router](navigation.md); for a
choice of view in a toolbar, a [segmented control](segmented.md).

## Without a look

`$c->tabsBase()` is a tab list without a look: the `list` holding the tabs,
whose `tab()`s choose the index, with the arrows moving the choice and the
focus. Each tab, and the list, is built in until `end()`:

```php
$names = ['Inbox', 'Sent', 'Drafts'];
$app = new class { public int $tab = 0; };
$view = function (Context $c) use ($app, $names) {
    $t = $c->theme;
    $tabs = $c->tabsBase($app->tab, count($names));
    $tabs->list->gap(4);
      foreach ($names as $i => $name) {
          $tab = $tabs->tab($i)->padding(6, 12)->radius(6);
          if ($i === $app->tab) {
              $tab->background($t->surface);
          }
            $c->text($name);
          $c->end();
      }
    $c->end();
};
```

See [custom widgets](custom-widgets.md).

## Accessibility

Assistive technology sees a tab list of tabs named by their labels, the one
chosen selected.
