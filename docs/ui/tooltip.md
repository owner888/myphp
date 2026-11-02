# Tooltip

`tooltip()` shows a tip once the pointer rests on a node, or the keyboard
focus comes to it, and describes the node to assistive technology:

```php
$c->button('Share')->tooltip('Share with others');
```

The tip shows once the pointer has rested on the node for 0.6 seconds, and
at once as the keyboard focus comes to it from the keyboard. It goes above
the node, centered, or below it where there is no room above, wherever the
pointer is on the node, as Base UI's does. Within 0.4 seconds of a tip
going, the next one shows at once, as the pointer moves along a toolbar.
It goes as the pointer leaves, and a press or a click on the node, or
Escape, hides it until the pointer leaves and comes back, or the focus
does: it does not show again after a click, nor beside the menu a menu
button opens. Over nodes inside one another, the innermost one's tip
shows, and one tip shows at a time. Give one to buttons showing only an
icon, and to what a label alone does not explain.

The tip takes the theme's `inverse` and `inverseText` colors, its text and
background turned over unless the theme sets them (see
[themes](styling.md#themes)).

## Without a look

`$c->tooltipBase()` shows a tip of your own by a node, as Base UI's
Tooltip does: it decides when the tip shows and goes, as above, and its
function styles the tip and builds its content. The tip goes above the
node, centered, or below it where there is no room above; place it
elsewhere with `attachTo()`, its margins keeping it apart from the node:

```php
$share = SVG::fromFile('icons/save.svg');
$view = function (Context $c) use ($share) {
    $t = $c->theme;
    $b = $c->buttonBase()->padding(6)->label('Share');
      $c->icon($share);
    $c->end();
    $c->tooltipBase($b, function (Node $tip) use ($c, $t, $b) {
        $tip->attachTo($b, Anchor::Right, Anchor::Left)->margin(0, 0, 0, 6);
        $tip->padding(4, 8)->radius(6)->background($t->text)->textColor($t->background);
        $c->text('Share with others');
        $c->text('⌘S')->textColor($t->textMuted);
    });
};
```

It returns the tip, or null while it does not show. The pointer goes
through the tip.

## Accessibility

Assistive technology reads the tip of `tooltip()` as the node's
description, after its name, as help text. It sees the tip of
`tooltipBase()` as a tooltip, which it does not read.
