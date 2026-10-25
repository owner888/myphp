<?php
// The theme's units (mygo's style_test.go TestThemeUnits), and only text
// inside a text (inline_test.go TestInlineOnlyText).
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Theme};

$th = Theme::light();
$th->spacing = 5; $th->fontSize = 16;
same([10.0, 24.0], [$th->space(2), $th->rem(1.5)], 'Space(2) and Rem(1.5)');
$th->fontSize = 0;
same(13.0, $th->rem(1), "a theme without a font size: the system's");

$thrown = null;
$h = new Headless(function (Context $c) use (&$thrown): void {
    try {
        $c->richText([])->children(function () use ($c): void { $c->button('Now'); });
    } catch (\LogicException $e) { $thrown = $e->getMessage(); }
}, 100, 100);
$h->frame(1);
same('only text nodes (text, link, richText) go inside a text', $thrown, 'a button inside a text');
