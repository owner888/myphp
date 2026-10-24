<?php
// Web views in native UI without WebKit (mygo's ui/webview_test.go): the
// node paints a hole through the content where the web view shows, and
// notes where; the pointer is the page's there, unless something painted
// over it takes it.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Node};

$docs = (new \ReflectionClass(\MyPHP\WebView::class))->newInstanceWithoutConstructor(); // its native side is AppKit's
$node = null; $over = null; $show = true; $cover = false;
$h = new Headless(function (Context $c) use ($docs, &$node, &$over, &$show, &$cover): void {
    $c->row(0, 0, 1)->size(400, 300);
      $c->box(100, 0, 0x333333);
      if ($show) { $node = $c->webView($docs)->grow(1)->padding(10)->radius(8); }
    $c->end();
    if ($cover) { $over = $c->button('Over')->absolute()->at(150, 40)->size(80, 30); }
}, 400, 300);
$h->frame();
$list = $h->paint();
same(1, count($list->holes), 'a hole');
$p = $h->rt->webPlaces[0] ?? null;
same([[110.0, 10.0, 280.0, 280.0], $node->id], [$p['frame'] ?? null, $p['id'] ?? null], 'its content box, noted with its node');
check($node->self === Node::STRETCH && $node->focusable, 'it stretches across and takes the focus');
check($h->rt->webViewAt(200, 100) === $docs, 'the pointer is the page\'s');
check($h->rt->webViewAt(50, 100) === null, 'not beside it');
$cover = true; $h->frame(); $h->paint();
check($h->rt->webViewAt(160, 50) === null && $h->rt->webViewAt(300, 200) === $docs, 'a button painted over it takes the pointer there');
$show = false; $h->frame(); $list = $h->paint();
same([[], []], [$list->holes, $h->rt->webPlaces], 'not built: no hole, nowhere to show');
