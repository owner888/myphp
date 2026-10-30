<?php
// Web views in native UI without WebKit (mygo's ui/webview_test.go): the
// node paints a hole through the content where the web view shows, and
// notes where, in its content box, clipped; the pointer is the page's
// there, unless something painted over it takes it; Tab stops at it; the
// last press before a frame decides a popover.
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

// Placed in its content box, inside its border and padding; a popover's panel over it takes the pointer, a press on the page closes it (mygo's TestWebViewPlacement).
$page = (new \ReflectionClass(\MyPHP\WebView::class))->newInstanceWithoutConstructor();
$open = false;
$h = new Headless(function (Context $c) use ($page, &$open): void {
    $c->column(0, 0, 1)->align(Node::STRETCH);
      $menu = $c->button('Menu');
      if ($c->clicked($menu)) { $open = !$open; }
      $c->popover($menu, $open, fn() => $c->text('Item')->padding(4, 8));
      $c->webView($page)->grow(1)->padding(10)->borderWidth(1)->borderColor(0x000000); // mygo's Border, in the layout
    $c->end();
}, 400, 300);
$h->frame(); $h->paint();
$p = $h->rt->webPlaces[0];
check($p['frame'] == $p['clip'] && $p['frame'][0] == 11 && $p['frame'][2] == 378 && $p['frame'][1] + $p['frame'][3] == 289, 'in its content box: ' . json_encode($p));
check($h->rt->webViewAt(200, 200) === $page && $h->rt->webViewAt(5, 200) === null, 'the pointer is the page\'s where it shows');
$h->clickText('Menu'); $h->paint();
$item = $h->rect($h->byText('Item'));
check($h->rt->webViewAt($item[0] + 1, $item[1] + 1) === null, 'the popover\'s panel takes the pointer over the page');
$h->click(200, 250);
check(!$open && $h->byText('Item') === null, 'a press on the page closes the popover');

// The hole is rounded as its corners inside its border; at half opacity, it is still placed (mygo's TestWebViewHole, TestWebViewOpacity).
$h = new Headless(function (Context $c) use ($page): void {
    $c->column(20, 0, 1)->align(Node::STRETCH);
      $c->webView($page)->grow(1)->radius(12)->borderWidth(2)->borderColor(0x000000);
    $c->end();
}, 200, 140);
$h->frame(); $list = $h->paint();
same([[22.0, 22.0, 156.0, 96.0]], array_map(fn($p) => array_map('floatval', $p['frame']), $h->rt->webPlaces), 'the page in the content box');
same(1, count($list->holes), 'one hole');
$h = new Headless(function (Context $c) use ($page): void {
    $c->column(20, 0, 1)->align(Node::STRETCH);
      $c->webView($page)->grow(1)->round(12)->border(0x888888); // border(), as the web views example: its point inside the page's edge (v1.28)
    $c->end();
}, 200, 140);
$h->frame(); $h->paint();
same([[21.0, 21.0, 158.0, 98.0]], array_map(fn($p) => array_map('floatval', $p['frame']), $h->rt->webPlaces), 'the page inside border()\'s border');
$h = new Headless(function (Context $c) use ($page): void {
    $c->column(20, 0, 1)->align(Node::STRETCH)->opacity(0.5);
      $c->webView($page)->grow(1);
    $c->end();
}, 200, 140);
$h->frame(); $h->paint();
same(1, count($h->rt->webPlaces), 'a translucent web view is placed');

// Tab stops at a web view, to give its page the keyboard, both ways (mygo's TestWebViewTab).
$beforeField = new \MyPHP\UI\TextState(''); $afterField = new \MyPHP\UI\TextState(''); $wv = null;
$h = new Headless(function (Context $c) use ($page, $beforeField, $afterField, &$wv): void {
    $c->column(0, 0, 1)->align(Node::STRETCH);
      $c->input($beforeField, '', 0)->label('Before');
      $wv = $c->webView($page)->height(100);
      $c->input($afterField, '', 0)->label('After');
    $c->end();
}, 200, 200);
$h->frame();
$h->tab();
same('Before', $h->focused()?->label, 'Tab: Before');
$h->tab();
same($wv->id, $h->rt->focus, 'Tab: the web view');
$h->tab();
same('After', $h->focused()?->label, 'Tab out of the page: After');
$h->tab(true);
same($wv->id, $h->rt->focus, 'Shift+Tab from After: the web view');

// In a scroll container, it shows the part of it in view, none once out of view or not built; a press on it takes the focus from the input (mygo's TestWebViewClipped).
$shown = true; $text = new \MyPHP\UI\TextState(''); $scroll = new \MyPHP\UI\ScrollState;
$h = new Headless(function (Context $c) use ($page, &$shown, $text, $scroll): void {
    $c->column(0, 0, 1)->align(Node::STRETCH);
      $c->input($text, '', 300)->label('Name');
      $c->column(0, 0, 1)->align(Node::STRETCH)->scroll($scroll);
        $c->box(0, 100, 0);
        if ($shown) { $c->webView($page)->height(300); }
        $c->box(0, 400, 0);
      $c->end();
    $c->end();
}, 300, 300);
$h->frame(); $h->paint();
$p = $h->rt->webPlaces[0] ?? null;
check($p !== null && $p['frame'][3] == 300 && $p['clip'][1] + $p['clip'][3] == 300 && $p['clip'][1] == $p['frame'][1], 'clipped by the scroll container at the window\'s bottom: ' . json_encode($p));
$h->clickLabel('Name');
same('Name', $h->focused()?->label, 'the input has the focus');
$h->click(150, 200);
check($h->focused()?->label !== 'Name', 'a press on the page takes it');
$h->input->push(['scroll', 150, 250, 0, -1000]); $h->frame(); $h->paint();
same([], $h->rt->webPlaces, 'scrolled out of view, it shows nowhere');
$h->input->push(['scroll', 150, 250, 0, 1000]); $shown = false; $h->frame(); $h->paint();
same([], $h->rt->webPlaces, 'not built, nowhere');

// A press on a popover's anchor after a press elsewhere before the next frame opens it; the last press decides (mygo's TestPopoverLastPressDecides).
$open = false; $behind = 0; $more = null; $other = null;
$h = new Headless(function (Context $c) use (&$open, &$behind, &$more, &$other): void {
    $c->row(10, 100)->align(Node::START);
      $more = $c->buttonBase()->size(60, 20)->label('more'); $c->end();
      if ($c->clicked($more)) { $open = true; }
      $other = $c->buttonBase()->size(60, 20)->label('behind'); $c->end();
      if ($c->clicked($other)) { $behind++; }
      $c->popoverBase($more, $open, function (Node $panel) use ($c): void { $c->buttonBase()->size(80, 20)->label('item'); $c->end(); });
    $c->end();
}, 400, 300);
$h->frame();
$press = function (Node $n) use ($h): void {
    [$x, $y] = $h->middle($n);
    $h->input->mouse($x, $y);
    $h->input->push(['down', $x, $y, 0, 1, 0]); $h->input->push(['up', $x, $y, 0, 1, 0]);
};
[$m, $o] = [$h->byLabel('more'), $h->byLabel('behind')];
$press($o); $press($m); $h->frame();
check($open && $behind === 1, "a press elsewhere then on the anchor: open, the other clicked $behind time");
$press($m); $press($o); $h->frame();
check(!$open, 'a press on the anchor then elsewhere leaves it closed');

if (PHP_OS_FAMILY === 'Darwin') { // the app's name and version after the user agent, WebKit's or the one set (mygo's UserAgentSuffix)
    \MyPHP\Native\ObjC::boot(['Foundation', 'AppKit', 'WebKit']);
    $rect = \MyPHP\Native\ObjC::rect(0, 0, 100, 100);
    $w = \MyPHP\Native\WebView::create($rect, [], function () {}, suffix: 'MyApp/1.2');
    check(str_ends_with($w->userAgent(), ' MyApp/1.2'), 'WebKit\'s user agent, the suffix after it: ' . $w->userAgent());
    $w->setUserAgent('Custom/1');
    same('Custom/1 MyApp/1.2', $w->userAgent(), 'one set: the suffix still after it');
    $plain = \MyPHP\Native\WebView::create($rect, [], function () {}, userAgent: 'Custom/1');
    same('Custom/1', $plain->userAgent(), 'without a suffix: the one set alone');
    $both = \MyPHP\Native\WebView::create($rect, [], function () {}, swipe: true, pinch: true);
    $ns = fn($w, string $p): bool => \MyPHP\Native\ObjC::msg('b:', $w->ns, $p);
    same([false, false, true, true], [$ns($plain, 'allowsBackForwardNavigationGestures'), $ns($plain, 'allowsMagnification'), $ns($both, 'allowsBackForwardNavigationGestures'), $ns($both, 'allowsMagnification')], 'swipes and pinches off unless asked (mygo\'s 32188db)');
    // Autoplay, background throttling, the preload in every frame, the process (mygo's 03ac070, 510c23b, 3d9d668, 15cb4a3).
    $O = \MyPHP\Native\ObjC::class;
    $free = \MyPHP\Native\WebView::create($rect, ['a', ['b', true]], function () {}, autoplay: true, throttle: false);
    $cfg = fn($w) => $O::msg('p:', $w->ns, 'configuration');
    same([0, true], [$O::msg('L:', $cfg($free), 'mediaTypesRequiringUserActionForPlayback'), $O::msg('L:', $cfg($plain), 'mediaTypesRequiringUserActionForPlayback') !== 0], 'autoplay: no media type requires a gesture, unless asked');
    $prefs = $O::msg('p:', $cfg($free), 'preferences');
    if ($O::msg('b:p', $prefs, 'respondsToSelector:', $O::sel('inactiveSchedulingPolicy'))) {
        same(2, $O::msg('l:', $prefs, 'inactiveSchedulingPolicy'), 'throttling off: WKInactiveSchedulingPolicyNone');
    }
    if ($O::msg('b:p', $prefs, 'respondsToSelector:', $O::sel('_hiddenPageDOMTimerThrottlingEnabled'))) {
        same(false, $O::msg('b:', $prefs, '_hiddenPageDOMTimerThrottlingEnabled'), 'throttling off: a hidden page\'s timers');
    }
    $scripts = $O::msg('p:', $O::msg('p:', $cfg($free), 'userContentController'), 'userScripts');
    same([true, false], [$O::msg('b:', $O::msg('p:L', $scripts, 'objectAtIndex:', 0), 'isForMainFrameOnly'), $O::msg('b:', $O::msg('p:L', $scripts, 'objectAtIndex:', 1), 'isForMainFrameOnly')], 'a script in the main frame, one in every frame');
    check($free->processID() >= 0, 'a process ID, or 0 before one runs');
}

// Session states (mygo's session_state_test.go): the system, the URL and WebKit's own state, which a later version's or anything else is not.
$st = \MyPHP\Page::encodeSessionState('darwin', 'app://localhost/docs?page=2', "bplist\0state");
same(['darwin', 'app://localhost/docs?page=2', "bplist\0state"], \MyPHP\Page::decodeSessionState($st), 'session state: read back, WebKit\'s NULs kept');
same(['darwin', '', ''], \MyPHP\Page::decodeSessionState(\MyPHP\Page::encodeSessionState('darwin', '', '')), 'session state: of a blank page');
same(null, \MyPHP\Page::decodeSessionState('app://localhost/saved'), 'session state: not one');
$later = $st; $later[strlen('mygo-session')] = chr(2);
same(null, \MyPHP\Page::decodeSessionState($later), "session state: a later version's");
same(false, (new \MyPHP\WindowOpenRequest('https://x.org'))->userInitiated, 'a window-open request: not a gesture\'s unless told');
