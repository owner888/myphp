<?php
// UIKit's text styles (Node::preferredFont, Font::preferred; not mygo's):
// the HIG's sizes and weights, iOS's on a touch screen and macOS's on the
// desktop, scaled with the theme's text size; an unknown style throws.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Font, Theme};

// The tables: a phone's body is 17 regular and its headline semibold; the desktop's body 13 and its headline bold.
same([17.0, 400], Font::preferred(Font::BODY, true), 'body on a phone: 17 regular');
same([17.0, 600], Font::preferred(Font::HEADLINE, true), 'headline on a phone: 17 semibold');
same([34.0, 400], Font::preferred(Font::LARGE_TITLE, true), 'large title on a phone: 34');
same([13.0, 400], Font::preferred(Font::BODY, false), 'body on the desktop: 13 regular');
same([13.0, 700], Font::preferred(Font::HEADLINE, false), 'headline on the desktop: 13 bold');
same([26.0, 400], Font::preferred(Font::LARGE_TITLE, false), 'large title on the desktop: 26');
same([10.0, 500], Font::preferred(Font::CAPTION2, false), 'caption2 on the desktop: 10 medium');
closeFloat(28.6, Font::preferred(Font::TITLE2, true, 1.3)[0], 'a larger text size scales the style: title2 22 × 1.3');
$threw = false;
try { Font::preferred('title', false); } catch (\InvalidArgumentException) { $threw = true; }
check($threw, 'a style that is not UIKit\'s throws (title is title1)');

// The theme's text scale: 1 for the default 13 points, the ratio for a larger text size.
same(1.0, Theme::light()->textScale(), 'the default theme scales text by 1');
$t = Theme::light(); $t->fontSize = 19.5;
closeFloat(1.5, $t->textScale(), 'a theme following 1.5× larger text scales by 1.5');

// On a node: the desktop's sizes by default, a phone's with a touch screen, both with the theme's scale.
$title = $body = null;
$h = new Headless(function (Context $c) use (&$title, &$body): void {
    $c->column();
      $title = $c->text('Settings')->preferredFont(Font::TITLE1);
      $body = $c->text('Plain')->preferredFont(Font::BODY);
    $c->end();
});
$h->frame();
same([22.0, 400], [$title->textStyle()->size, $title->textStyle()->weight], 'title1 on the desktop: 22 regular');
same(13.0, $body->textStyle()->size, 'body on the desktop: 13');
$h = new Headless(function (Context $c) use (&$title, &$body): void {
    $c->column();
      $title = $c->text('Settings')->preferredFont(Font::TITLE1);
      $body = $c->text('Plain')->preferredFont(Font::HEADLINE)->fontWeight(400);
    $c->end();
}, 390, 844);
$h->rt->touch = true;
$h->frame();
same(28.0, $title->textStyle()->size, 'title1 on a phone: 28');
same([17.0, 400], [$body->textStyle()->size, $body->textStyle()->weight], 'a modifier after preferredFont overrides its weight');
$h = new Headless(function (Context $c) use (&$body): void {
    $body = $c->text('Plain')->preferredFont(Font::BODY);
});
$h->theme = Theme::light(); $h->theme->fontSize = 26;
$h->frame();
same(26.0, $body->textStyle()->size, 'the theme twice as large doubles the style');
