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
    $webKits = $O::msg('L:', $O::msg('p:', $O::alloc('WKWebViewConfiguration'), 'init'), 'mediaTypesRequiringUserActionForPlayback'); // WebKit's default, which macOS sets: none on recent ones
    same([0, $webKits], [$O::msg('L:', $cfg($free), 'mediaTypesRequiringUserActionForPlayback'), $O::msg('L:', $cfg($plain), 'mediaTypesRequiringUserActionForPlayback')], 'autoplay: no media type requires a gesture; unless asked, WebKit\'s default');
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
    // The page's sound and capture (mygo's 37f56f6, 95de2cd): silent and capturing nothing before it loads; muted as asked, where WebKit mutes.
    same([false, 0, 0, 0], [$free->isAudible(), $free->captureState(0), $free->captureState(1), $free->captureState(2)], 'a page loading nothing: silent, no capture');
    if ($O::msg('b:p', $free->ns, 'respondsToSelector:', $O::sel('_setPageMuted:'))) {
        $free->setAudioMuted(true);
        $muted = $free->isAudioMuted();
        $free->setAudioMuted(false);
        same([true, false], [$muted, $free->isAudioMuted()], 'muted, and not again');
    }
    $free->setCaptureState(1, 2); // capturing nothing: nothing
    same(0, $free->captureState(1), 'no capture to mute');
    // HTTP sign-in (mygo's d9d1ef6): the handler's credentials, a cancel the server's refusal, none WebKit's handling; a client certificate WebKit's.
    $challenge = function (string $method, bool $proxy = false) use ($O) {
        $space = $proxy
            ? $O::msg('p:plppp', $O::alloc('NSURLProtectionSpace'), 'initWithProxyHost:port:type:realm:authenticationMethod:', $O::str('proxy.lan'), 3128, $O::str('HTTPSProxy'), null, $O::str($method))
            : $O::msg('p:plppp', $O::alloc('NSURLProtectionSpace'), 'initWithHost:port:protocol:realm:authenticationMethod:', $O::str('router.lan'), 80, $O::str('http'), $O::str('admin'), $O::str($method));
        return $O::msg('p:pplppp', $O::alloc('NSURLAuthenticationChallenge'), 'initWithProtectionSpace:proposedCredential:previousFailureCount:failureResponse:error:sender:', $space, null, 0, null, null, null);
    };
    $answer = function ($challenge) use ($free, $O): array {
        $got = null;
        $handler = $O::block('lp', function (int $disposition, $cred) use (&$got, $O): void { $got = [$disposition, $cred === null ? null : $O::phpStr($O::msg('p:', $cred, 'user')) . ':' . $O::phpStr($O::msg('p:', $cred, 'password'))]; });
        (fn() => $this->authChallenge($challenge, $handler))->call($free);
        return $got;
    };
    same([1, null], $answer($challenge('NSURLAuthenticationMethodHTTPBasic')), 'sign-in: no handler, WebKit\'s handling');
    $asked = [];
    $free->onAuth = function (string $host, int $port, string $realm, string $scheme, bool $proxy) use (&$asked): array {
        $asked[] = [$host, $port, $realm, $scheme, $proxy];
        return $proxy ? [] : ['admin', 'hunter2'];
    };
    same([0, 'admin:hunter2'], $answer($challenge('NSURLAuthenticationMethodHTTPBasic')), 'sign-in: the handler\'s credentials');
    same([3, null], $answer($challenge('NSURLAuthenticationMethodHTTPDigest', true)), 'sign-in: canceled, the server\'s refusal');
    same([['router.lan', 80, 'admin', 'basic', false], ['proxy.lan', 3128, '', 'digest', true]], $asked, 'sign-in: what the handler was asked');
    same([1, null], $answer($challenge('NSURLAuthenticationMethodClientCertificate')), 'sign-in: a client certificate, WebKit\'s');
    $free->onAuth = null;
}

// Session states (mygo's session_state_test.go): the system, the URL and WebKit's own state, which a later version's or anything else is not.
$st = \MyPHP\Page::encodeSessionState('darwin', 'app://localhost/docs?page=2', "bplist\0state");
same(['darwin', 'app://localhost/docs?page=2', "bplist\0state"], \MyPHP\Page::decodeSessionState($st), 'session state: read back, WebKit\'s NULs kept');
same(['darwin', '', ''], \MyPHP\Page::decodeSessionState(\MyPHP\Page::encodeSessionState('darwin', '', '')), 'session state: of a blank page');
same(null, \MyPHP\Page::decodeSessionState('app://localhost/saved'), 'session state: not one');
$later = $st; $later[strlen('mygo-session')] = chr(2);
same(null, \MyPHP\Page::decodeSessionState($later), "session state: a later version's");
same(false, (new \MyPHP\WindowOpenRequest('https://x.org'))->userInitiated, 'a window-open request: not a gesture\'s unless told');

// The theme color as the bridge tells it (mygo's 9bf2ce4): #rrggbbaa, "" or anything else none.
same([[0x1e90ff, 1.0], [0xff0000, 128 / 255], null, null], [\MyPHP\Page::parseThemeColor('#1e90ffff'), \MyPHP\Page::parseThemeColor('#FF000080'), \MyPHP\Page::parseThemeColor(''), \MyPHP\Page::parseThemeColor('#fff')], 'theme colors: as [rgb, alpha]');
check(in_array('themeColorChanged', \MyPHP\Page::EVENTS, true) && in_array('audibleChanged', \MyPHP\Page::EVENTS, true) && in_array('captureStateChanged', \MyPHP\Page::EVENTS, true), 'the page\'s events of its theme color, sound and capture');

// Untrusted pages (mygo's untrusted_test.go, the part without WebKit): their calls rejected whatever their URL, the origin they navigate
// within, and the page a scheme's request came from.
$ut = (new \ReflectionClass(\MyPHP\Page::class))->newInstanceWithoutConstructor();
(new \ReflectionProperty(\MyPHP\Page::class, 'untrusted'))->setValue($ut, true);
(new \ReflectionProperty(\MyPHP\Page::class, 'trustedOrigins'))->setValue($ut, ['*']);
$reply = \MyPHP\Ipc::handle(json_encode(['t' => 'call', 'id' => 7, 'k' => 'sec', 'm' => 'myphp:window.close', 'a' => []]), 'sec', $ut);
same([7, false, true], [$reply['id'] ?? null, $reply['ok'] ?? null, str_contains((string)($reply['e'] ?? ''), 'untrusted')], 'an untrusted page\'s call, even to myphp:window, rejected');
same([true, false], [$ut->isUntrusted(), $ut->trusts('https://example.com')], 'trusting no origin, "*" in its trusted origins or not');
same(false, (new \MyPHP\PageOptions())->untrusted, 'pages trusted as their URL says by default');
same(['https://example.com', 'plugin://a', 'https://x.org:8443', 'plugin://a'], [\MyPHP\Page::origin('https://Example.com/a?b'), \MyPHP\Page::origin('plugin://a/index.html'), \MyPHP\Page::origin('https://x.org:8443/'), \MyPHP\Page::origin('blob:plugin://a/1234-5678')], 'the origins of pages, a blob\'s its maker\'s');
same($ut, (new \MyPHP\ProtocolRequest('GET', 'plugin://a/x', [], '', null, $ut))->page, 'a scheme\'s request tells the page that made it');

// Picture in picture (mygo's 10c5033), the part without WebKit: off by default; the bridge tells whether the document has a video out.
same(false, (new \MyPHP\PageOptions())->pictureInPicture, 'pages\' videos kept out of picture in picture by default, as WKWebView does');
$pp = (new \ReflectionClass(\MyPHP\Page::class))->newInstanceWithoutConstructor();
(new \ReflectionProperty(\MyPHP\Page::class, 'untrusted'))->setValue($pp, false);
(new \ReflectionProperty(\MyPHP\Page::class, 'pip'))->setValue($pp, true);
$msg = new \ReflectionMethod(\MyPHP\Page::class, 'message');
$pipActive = new \ReflectionProperty(\MyPHP\Page::class, 'pipActive');
$msg->invoke($pp, '{"t":"pip","v":true}');
$out = [$pipActive->getValue($pp)];
$msg->invoke($pp, '{"t":"pip","v":false}');
$out[] = $pipActive->getValue($pp);
same([true, false], $out, 'a video of the document in picture in picture, then out, as the bridge tells');
