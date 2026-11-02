# Toolbar

`$c->toolbar()` creates a row of controls, as along the top of a window:
buttons, [toggles](toggle.md), toggle groups, [segmented
controls](segmented.md) and [menu buttons](menu-button.md), which take no
face of their own until hovered. `$c->spacer()` pushes those after it to
the end.

```php
$app = new class {
    public Router $router;
    public bool $bold = false;
    public bool $italic = false;
    public int $shares = 0;
    public function __construct() { $this->router = new Router('/'); }
    public function share(): void { $this->shares++; }
};
$view = function (Context $c) use ($app) {
    $c->toolbar(function () use ($c, $app) {
        $c->backButton($app->router);
        $c->forwardButton($app->router);
        $c->toggleGroup(function () use ($c, $app) {
            $c->toggle($app->bold, 'Bold');
            $c->toggle($app->italic, 'Italic');
        });
        $c->spacer();
        if ($c->clicked($c->button('Share'))) {
            $app->share();
        }
    })->label('Format');
};
```

## Keyboard

A toolbar is one stop of Tab: Left and Right move the focus among its
controls, Home and End to the first and the last, and Tab goes on past it.

## Overflow

Controls that do not fit go, from the last, into a menu at the toolbar's
end, where choosing one clicks it, toggles a toggle, or opens a menu
button's menu.

## Accessibility

Assistive technology sees a toolbar, which `label()` names, holding its
controls.
