<?php
// A part of the window in another theme (mygo's themed_test.go): a light
// Context::themed() column in a dark window builds and paints with the
// light theme, its text and widgets, what it opens over the window too;
// the root and what follows keep the window's; themes nest; a null theme
// is a plain column; the scroll bars of a scope are its theme's width.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Node, ScrollState, Theme};

$dark = Theme::dark(); $light = Theme::light();
$n = []; $themes = [];
$open = true;
$t = new Headless(function (Context $c) use (&$n, &$themes, &$open, $light) {
    $c->row(0, 16)->alignItems(Node::START);
      $c->text('dark side');
      $n['panel'] = $c->themed($light, 8);
        $themes['inside'] = $c->theme;
        $n['text'] = $c->text('preview');
        $n['ok'] = $c->button('OK');
        $n['pop'] = $c->popover($n['ok'], $open, function () use ($c, &$n) { $n['popText'] = $c->text('popped'); });
      $c->end();
      $themes['after'] = $c->theme;
      $n['later'] = $c->text('later');
      $n['laterOK'] = $c->button('Later');
    $c->end();
}, 400, 300);
$t->theme = $dark;
$t->frame();
same([$light, $dark], [$themes['inside'], $themes['after']], 'the theme inside the scope is its own, after it the window\'s');
same([$light->text, $light->fontSize], [$n['text']->fg, $n['text']->fontSize], 'bare text in the scope: the light theme\'s color and size');
same([$light->surface, $light->text], [$n['ok']->color, $t->byText('OK')->fg], 'a button in the scope: the light face and label');
same([$dark->text, $dark->surface], [$n['later']->fg, $n['laterOK']->color], 'after the scope, the window\'s theme');
same([$light->background, $light], [$n['panel']->color, $n['panel']->theme], 'the column paints the light background, with the light theme');
same([$dark->background, null], [$t->root->color, $t->root->theme], 'the root keeps the window\'s');
check($n['pop'] !== null && $n['pop']->theme === $light && $n['popText']->fg === $light->text, 'a popover opened in the scope, over the window, takes its theme');
same($light, $n['text']->theme, 'what is built in the scope paints with its theme');

// Nested, and a null theme.
$seen = [];
$h = new Headless(function (Context $c) use (&$seen, $light, $dark) {
    $c->themed($light);
      $seen[] = [$c->theme, $c->text('outer')->fg];
      $c->themed($dark);
        $seen[] = [$c->theme, $c->text('inner')->fg];
      $c->end();
      $seen[] = [$c->theme, $c->text('back')->fg];
      $c->themed(null);
        $seen[] = [$c->theme, $c->text('plain')->fg];
      $c->end();
    $c->end();
}, 400, 300);
$h->frame(1);
same([[$light, $light->text], [$dark, $dark->text], [$light, $light->text], [$light, $light->text]], array_slice($seen, -4), 'nested scopes, and a null theme a plain column');

// The metrics of a scope: its spacing and radius in its widgets, its scroll bars' width where the pointer takes them.
$roomy = Theme::light(); $roomy->spacing = 6; $roomy->scrollbarWidth = 11;
$s = new ScrollState();
$h = new Headless(function (Context $c) use (&$n, $roomy, $s) {
    $c->themed($roomy)->size(200, 200);
      $n['btn'] = $c->button('Roomy');
      $n['scroll'] = $c->column(0, 0)->fill()->scroll($s);
        $c->column(0, 0)->size(100, 2000); $c->end();
      $c->end();
    $c->end();
    $n['plainBtn'] = $c->button('Roomy');
}, 300, 300);
$h->frame();
same(14.0, $n['btn']->fw - $n['plainBtn']->fw, 'a button in the scope: padded by its theme\'s spacing');
[$sx, $sy, $sw, $sh] = $h->rect($n['scroll']);
$h->hover($sx + $sw - 15, $sy + 20); // within an 11-point bar and the 4 points around it, beyond the window's 6
$h->press($sx + $sw - 15, $sy + 20);
$h->drag($sx + $sw - 15, $sy + 120);
$h->release($sx + $sw - 15, $sy + 120);
check($s->y > 0, 'the scope\'s wider scroll bar takes the pointer: dragged to ' . $s->y);
