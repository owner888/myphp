<?php
declare(strict_types=1);

namespace MyPHP\Native;

use Closure;
use RuntimeException;

/**
 * A WKWebView with the MyPHP bridge installed: the bridge script runs at
 * document start, messages the page posts reach $onMessage, and deliver()
 * hands replies and events back to the page.
 */
final class WebView
{
    private const INJECT_AT_DOCUMENT_START = 0;
    private const AUTORESIZE_WIDTH_HEIGHT = 2 | 16;
    private static bool $defined = false;
    /** @var array<int, WebView> by WKWebView address */
    private static array $views = [];

    public $ns;

    private function __construct(private Closure $onMessage) {}

    public static function create($frame, array $config, Closure $onMessage, string $preload = ''): self
    {
        self::define();
        $self = new self($onMessage);
        $wk = ObjC::msg('p:', ObjC::alloc('WKWebViewConfiguration'), 'init');
        $ucc = ObjC::msg('p:', $wk, 'userContentController');
        ObjC::msg('v:pp', $ucc, 'addScriptMessageHandler:name:', ObjC::msg('p:', ObjC::alloc('MyPHPScriptHandler'), 'init'), ObjC::str('myphp'));
        $source = str_replace('__CONFIG__', json_encode($config, JSON_THROW_ON_ERROR), file_get_contents(__DIR__ . '/bridge.js'));
        $script = ObjC::msg('p:plb', ObjC::alloc('WKUserScript'), 'initWithSource:injectionTime:forMainFrameOnly:', ObjC::str($source), self::INJECT_AT_DOCUMENT_START, true);
        ObjC::msg('v:p', $ucc, 'addUserScript:', $script);
        if ($preload !== '') {
            $pre = ObjC::msg('p:plb', ObjC::alloc('WKUserScript'), 'initWithSource:injectionTime:forMainFrameOnly:', ObjC::str($preload), self::INJECT_AT_DOCUMENT_START, true);
            ObjC::msg('v:p', $ucc, 'addUserScript:', $pre);
        }
        $self->ns = ObjC::msg('p:rp', ObjC::alloc('MyPHPWebView'), 'initWithFrame:configuration:', $frame, $wk);
        Drop::register($self->ns);
        ObjC::msg('v:L', $self->ns, 'setAutoresizingMask:', self::AUTORESIZE_WIDTH_HEIGHT);
        self::$views[ObjC::addr($self->ns)] = $self;
        return $self;
    }

    private static function define(): void
    {
        if (self::$defined) {
            return;
        }
        self::$defined = true;
        // A WKWebView that hands dropped files to PHP (the page's own drop events do not see paths).
        ObjC::defineClass('MyPHPWebView', 'WKWebView', Drop::methods(false), ['NSDraggingDestination']);
        ObjC::defineClass('MyPHPScriptHandler', 'NSObject', [
            'userContentController:didReceiveScriptMessage:' => ['v@:@@', 'v(pppp)', function ($self, $cmd, $ucc, $message) {
                $view = self::$views[ObjC::addr(ObjC::msg('p:', $message, 'webView'))] ?? null;
                if ($view === null) {
                    return;
                }
                $body = ObjC::phpStr(ObjC::msg('p:', $message, 'body'));
                ($view->onMessage)($body);
            }],
        ]);
    }

    public function loadHTML(string $html, ?string $baseURL = null): void
    {
        $base = $baseURL === null ? null : ObjC::msg('p:p', ObjC::cls('NSURL'), 'URLWithString:', ObjC::str($baseURL));
        ObjC::msg('p:pp', $this->ns, 'loadHTMLString:baseURL:', ObjC::str($html), $base);
    }

    /** Loads an http(s) URL, or a file: URL with read access to its directory. */
    public function loadURL(string $url): void
    {
        $nsurl = ObjC::msg('p:p', ObjC::cls('NSURL'), 'URLWithString:', ObjC::str($url))
            ?? throw new RuntimeException("bad URL $url");
        if (str_starts_with($url, 'file:')) {
            $dir = ObjC::msg('p:', $nsurl, 'URLByDeletingLastPathComponent');
            ObjC::msg('p:pp', $this->ns, 'loadFileURL:allowingReadAccessToURL:', $nsurl, $dir);
            return;
        }
        ObjC::msg('p:p', $this->ns, 'loadRequest:', ObjC::msg('p:p', ObjC::cls('NSURLRequest'), 'requestWithURL:', $nsurl));
    }

    /** Runs JavaScript; $completion is an ObjC block (id result, NSError* error) or null. */
    public function evaluate(string $js, $completion = null): void
    {
        ObjC::msg('v:pp', $this->ns, 'evaluateJavaScript:completionHandler:', ObjC::str($js), $completion);
    }

    /** Hands a bridge message (reply or event) to the page. */
    public function deliver(array $message): void
    {
        $this->evaluate('window.myphp.__deliver(' . json_encode($message, JSON_THROW_ON_ERROR) . ')');
    }
}
