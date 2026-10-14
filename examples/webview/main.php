<?php
// A WKWebView window whose page calls PHP and gets an answer back.
// Run: php -d ffi.enable=1 examples/webview/main.php
declare(strict_types=1);

use MyPHP\Native\ObjC;

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');
ObjC::boot(['Foundation', 'AppKit', 'WebKit']);

$app = ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication');
ObjC::msg('b:l', $app, 'setActivationPolicy:', 0);
$webview = null;

ObjC::defineClass('MyPHPBridge', 'NSObject', [
    'windowWillClose:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $note) use ($app) {
        ObjC::msg('v:p', $app, 'terminate:', null);
    }],
    'userContentController:didReceiveScriptMessage:' => ['v@:@@', 'v(pppp)', function ($self, $cmd, $ucc, $msg) use (&$webview) {
        $body = ObjC::phpStr(ObjC::msg('p:', $msg, 'body'));
        printf("[PHP] JS says: %s\n", $body);
        $reply = json_encode("PHP got \"$body\" at " . date('H:i:s'));
        ObjC::msg('v:pp', $webview, 'evaluateJavaScript:completionHandler:', ObjC::str("document.getElementById('out').textContent = $reply;"), null);
    }],
]);
$bridge = ObjC::msg('p:', ObjC::alloc('MyPHPBridge'), 'init');

$config = ObjC::msg('p:', ObjC::alloc('WKWebViewConfiguration'), 'init');
ObjC::msg('v:pp', ObjC::msg('p:', $config, 'userContentController'), 'addScriptMessageHandler:name:', $bridge, ObjC::str('php'));

$rect = ObjC::rect(0, 0, 640, 420);
$win = ObjC::msg('p:rLLb', ObjC::alloc('NSWindow'), 'initWithContentRect:styleMask:backing:defer:', $rect, 15, 2, false);
ObjC::msg('v:p', $win, 'setTitle:', ObjC::str('MyPHP + WKWebView'));
ObjC::msg('v:p', $win, 'setDelegate:', $bridge);
$webview = ObjC::msg('p:rp', ObjC::alloc('WKWebView'), 'initWithFrame:configuration:', $rect, $config);
ObjC::msg('v:p', $win, 'setContentView:', $webview);

$html = <<<'HTML'
<!doctype html><meta charset="utf-8">
<body style="font: 15px -apple-system; padding: 24px">
<h2>WKWebView driven by PHP</h2>
<button onclick="window.webkit.messageHandlers.php.postMessage('hello ' + (++n))">Call PHP</button>
<p id="out">(click the button)</p>
<script>let n = 0;</script>
HTML;
ObjC::msg('p:pp', $webview, 'loadHTMLString:baseURL:', ObjC::str($html), null);

ObjC::msg('v:', $win, 'center');
ObjC::msg('v:p', $win, 'makeKeyAndOrderFront:', null);
ObjC::msg('v:b', $app, 'activateIgnoringOtherApps:', true);
ObjC::msg('v:', $app, 'run');
