<?php
// A page of WebView2 on Windows (windows_webview_test.php): a window of HTML shown, its document read
// by eval (the DevTools protocol's Runtime.evaluate), a call of the page's bridge to PHP answered (the
// window's title set from JS), new HTML loaded; a page of a scheme PHP serves (from
// http://<scheme>.localhost/, its URL the scheme's), its fetch of the scheme answered with the method
// and body it sent; cookies set, listed and deleted before a page shows (a hidden web view's cookie
// manager) and after (the page's), a named session's and an ephemeral one's apart; content rules blocking
// a script of a page and hiding an element.
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};

require __DIR__ . '/../../src/autoload.php';

$log = function (string $where): void { fwrite(STDERR, "at: $where\n"); fflush(STDERR); };
$r = ['available' => null];
App::setName('WebViewTest');
// A scheme PHP serves: its page, and an echo of the method and body of what the page sends.
\MyPHP\Protocol::handle('wvtest', function (\MyPHP\ProtocolRequest $req): \MyPHP\ProtocolResponse {
    if (str_ends_with($req->url, '/blocked.js')) {
        return new \MyPHP\ProtocolResponse(200, ['Content-Type' => 'text/javascript'], 'window.ran = 1');
    }
    if (str_ends_with($req->url, '/rules.html')) {
        return new \MyPHP\ProtocolResponse(200, ['Content-Type' => 'text/html; charset=utf-8'], '<!doctype html><div class="ad" id="ad">ad</div><p id="r">rules</p><script src="/blocked.js"></script>');
    }
    if (str_ends_with($req->url, '/echo')) {
        return new \MyPHP\ProtocolResponse(200, ['Content-Type' => 'text/plain'], $req->method . ':' . $req->body);
    }
    return new \MyPHP\ProtocolResponse(200, ['Content-Type' => 'text/html; charset=utf-8'], '<!doctype html><title>Served</title><p id="s">served at ' . htmlspecialchars($req->url) . '</p>');
});
App::whenReady(function () use (&$r, $log) {
    $r['available'] = App::backend()->webViewAvailable();
    // Cookies before any page: those of a hidden web view's cookie manager.
    $cookies = function () use ($log): array {
        $out = [];
        try {
            $log('cookies set');
            \MyPHP\Cookies::set(new \MyPHP\Cookie('k', 'v', 'example.com', expires: new DateTimeImmutable('+30 days 00:00 UTC'))); // within Chromium's 400 days, past which it shortens an expiry
            $log('cookies listed');
            $out['listed'] = array_values(array_map(fn($c) => "$c->name=$c->value;$c->domain;$c->path;" . $c->expires?->format('Y-m-d'), array_filter(\MyPHP\Cookies::list(), fn($c) => $c->domain === 'example.com')));
            $log('cookies deleted');
            \MyPHP\Cookies::delete(new \MyPHP\Cookie('k', '', 'example.com'));
            $out['after'] = count(array_filter(\MyPHP\Cookies::list(), fn($c) => $c->domain === 'example.com'));
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
        }
        return $out;
    };
    $r['cookiesHidden'] = $cookies();
    $log('a page');
    $w = new Window(new WindowOptions(title: 'Start', width: 400, height: 300, html: '<!doctype html><title>Start</title><h1 id="h">Hello</h1>'));
    $w->show();
    // The page's document, once there: looked for every 0.2 s for 20 s.
    $until = function (string $js, mixed $want, Closure $then) use ($w, $log): void {
        $tries = 0;
        $look = function () use ($w, $js, $want, $then, &$tries, &$look, $log): void {
            try {
                $v = $w->page()?->eval($js);
            } catch (\Throwable $e) {
                $v = $e->getMessage();
            }
            if ($v === $want || ++$tries >= 100) {
                $then($v);
                return;
            }
            App::after(0.2, $look);
        };
        App::after(0.2, $look);
    };
    $until("document.getElementById('h')?.textContent", 'Hello', function ($v) use ($w, &$r, $log, $until, $cookies) {
        $r['heading'] = $v;
        $log('the bridge');
        try {
            $w->page()->eval("await window.myphp.call('myphp:window.SetTitle', 'From JS'); return 1");
        } catch (\Throwable $e) {
            $r['bridgeError'] = $e->getMessage();
        }
        $r['title'] = $w->title();
        $log('new HTML');
        $w->page()->loadHTML('<!doctype html><p id="p">Second</p>');
        $until("document.getElementById('p')?.textContent", 'Second', function ($v) use ($w, &$r, $log, $until, $cookies) {
            $r['second'] = $v;
            $r['canGoBack'] = $w->page()->canGoBack();
            $r['zoom'] = $w->page()->zoomFactor();
            $log('a scheme');
            $w->page()->loadURL('wvtest://localhost/index.html');
            $until("document.getElementById('s')?.textContent", 'served at wvtest://localhost/index.html', function ($v) use ($w, &$r, $log, $until, $cookies) {
                $r['served'] = $v;
                $r['servedURL'] = $w->page()->url();
                try {
                    $r['fetched'] = $w->page()->eval("const r = await fetch('/echo', {method: 'POST', body: 'hi'}); return await r.text()");
                } catch (\Throwable $e) {
                    $r['fetched'] = $e->getMessage();
                }
                $r['cookiesShown'] = $cookies();
                // Sessions: a named one's profile and an ephemeral one's InPrivate profile, each its own cookies.
                $log('sessions');
                try {
                    $named = \MyPHP\Session::newSession('wvtest');
                    $private = \MyPHP\Session::newEphemeralSession();
                    $named->cookies->set(new \MyPHP\Cookie('s', 'named', 'example.org'));
                    $private->cookies->set(new \MyPHP\Cookie('s', 'private', 'example.org'));
                    $of = fn(array $cookies) => array_values(array_map(fn($c) => $c->value, array_filter($cookies, fn($c) => $c->domain === 'example.org')));
                    $r['sessions'] = [$of($named->cookies->list()), $of($private->cookies->list()), $of(\MyPHP\Cookies::list())];
                    $named->cookies->clear();
                } catch (\Throwable $e) {
                    $r['sessions'] = $e->getMessage();
                }
                $log('content rules');
                try {
                    $rules = \MyPHP\ContentRules::compile('wvtest', '[{"trigger":{"url-filter":"blocked\\\\.js"},"action":{"type":"block"}},{"trigger":{"url-filter":".*"},"action":{"type":"css-display-none","selector":".ad"}}]');
                    $w->page()->setContentRules($rules);
                } catch (\Throwable $e) {
                    $r['rulesError'] = $e->getMessage();
                }
                $w->page()->loadURL('wvtest://localhost/rules.html');
                $until("document.getElementById('r') ? getComputedStyle(document.getElementById('ad')).display : ''", 'none', function ($v) use ($w, &$r) {
                    $r['hidden'] = $v;
                    try {
                        $r['ran'] = $w->page()->eval("return String(window.ran === 1)");
                    } catch (\Throwable $e) {
                        $r['ran'] = $e->getMessage();
                    }
                    echo json_encode($r, JSON_PRESERVE_ZERO_FRACTION), "\n"; // a zoom of 1.0 a float still
                    App::quit();
                });
            });
        });
    });
});
App::run();
