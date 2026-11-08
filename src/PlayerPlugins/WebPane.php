<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use MyPHP\Logger;
use MyPHP\PageOptions;
use MyPHP\WebView;
use MyPHP\WebViewOptions;
use MyPHP\Window;

/**
 * A web view of a plugin's (IINA's overlay, sidebar and standalone window
 * views): its page given IINA's bridge (window.iina: onMessage,
 * postMessage, and simple mode's style and content), a file of the
 * plugin's loaded in it, or IINA's simple mode's page; the messages its
 * page posts given to the plugin's listeners (a MessageHub, cleared as
 * another page loads), the plugin's posted to its page. A link to a
 * domain of the plugin's allowedDomains is not followed, another is
 * (IINA's decidePolicyFor, as it is).
 */
final class WebPane
{
    /** IINA's bridge (JavascriptMessageHub.bridgeScript), its messages myphp's: the page's posted to the app, the app's an event of the page's ("iina"). */
    private const BRIDGE = <<<'JS'
(() => {
  if (window.iina) return;
  const post = (n, d) => window.webkit.messageHandlers.myphp.postMessage(JSON.stringify({ t: "post", n, d: d === undefined ? null : d }));
  window.iina = {
    listeners: {},
    _emit(name, data) {
      const callback = this.listeners[name];
      if (typeof callback === "function") callback.call(null, data);
    },
    _simpleModeSetStyle(string) { document.getElementById("style").innerHTML = string; },
    _simpleModeSetContent(string) { document.getElementById("content").innerHTML = string; },
    onMessage(name, callback) { this.listeners[name] = callback; },
    postMessage(name, data) { post(name, data); },
  };
  window.myphp.on("iina", (m) => window.iina._emit(m.n, m.d));
  window.myphp.on("iina-simple", (m) => {
    if (m.style !== undefined) window.iina._simpleModeSetStyle(m.style);
    if (m.content !== undefined) window.iina._simpleModeSetContent(m.content);
  });
})();
JS;
    /** What of an overlay's page takes clicks (IINA's _hitTest, its elements of data-clickable), told as it changes. */
    private const CLICKABLE = <<<'JS'
(() => {
  let queued = false;
  const report = () => {
    queued = false;
    const rects = [...document.querySelectorAll("[data-clickable]")].map((e) => { const b = e.getBoundingClientRect(); return [b.left, b.top, b.width, b.height]; });
    window.webkit.messageHandlers.myphp.postMessage(JSON.stringify({ t: "post", n: "__iina_clickable", d: rects }));
  };
  const later = () => { if (!queued) { queued = true; requestAnimationFrame(report); } };
  document.addEventListener("DOMContentLoaded", () => {
    later();
    new MutationObserver(later).observe(document.documentElement, { subtree: true, childList: true, attributes: true });
    addEventListener("resize", later);
    addEventListener("scroll", later, true);
  });
})();
JS;
    public readonly WebView $view;
    /** Whether it shows IINA's simple mode's page. */
    public bool $simple = false;
    /** @var list<array{0: float, 1: float, 2: float, 3: float}> what of its page takes clicks, where it reports them */
    public array $clickable = [];

    /** A web view of $instance's in $window, its messages $hub's, IINA's simple mode's page $simpleHtml its own; $clickable: it reports what takes clicks; $loaded called as a page loads. */
    public function __construct(private readonly Instance $instance, public readonly MessageHub $hub, Window $window, private readonly string $simpleHtml, bool $clickable = false, private readonly ?\Closure $loaded = null)
    {
        $this->view = $window->newWebView(new WebViewOptions(page: new PageOptions(preloadScript: self::BRIDGE . "\n" . $instance->plugin->stringsScript() . ($clickable ? "\n" . self::CLICKABLE : '')), transparent: true));
        $page = $this->view->page();
        $page->on('message', function (string $name, mixed $data): void {
            if ($name === '__iina_clickable') {
                $this->clickable = array_values(array_filter(is_array($data) ? $data : [], fn($r) => is_array($r) && count($r) === 4));
                $this->instance->player?->refresh();
                return;
            }
            $this->hub->call($name, $data);
        });
        $page->on('willNavigate', function (\MyPHP\NavigateEvent $e): void {
            if ($this->instance->plugin->canAccess($e->url)) { $e->preventDefault(); } // IINA's: a page of its allowed domains is not gone to, another is
        });
        $page->on('didFinishLoad', function (): void { if ($this->loaded !== null) { ($this->loaded)(); } });
        $instance->onCleanUp(fn() => $this->view->destroy());
    }

    /** Loads file $path of the plugin's folder (its listeners cleared, IINA's). */
    public function loadFile(string $path): void
    {
        $root = $this->instance->plugin->root;
        $file = Plugin::normalize("$root/$path");
        if (!str_starts_with($file, rtrim($root, '/') . '/') || !is_file($file)) {
            throw new PluginError("Failed to load $path");
        }
        $this->simple = false;
        $this->clickable = [];
        $this->view->page()->loadFile($file, $root); // the plugin's folder its to read, IINA's allowingReadAccessTo
        $this->hub->clear();
    }

    /** Shows IINA's simple mode's page (its listeners cleared); again, nothing. */
    public function simpleMode(): void
    {
        if ($this->simple) { return; }
        $this->simple = true;
        $this->clickable = [];
        $this->view->page()->loadHTML($this->simpleHtml);
        $this->hub->clear();
    }

    /** Simple mode's style, or its content, set ($what 'style' or 'content'); in another page, logged (IINA's). */
    public function setSimple(string $what, string $value, string $api): void
    {
        if (!$this->simple) {
            $this->instance->log("$api.set" . ucfirst($what) . " is only available in simple mode.", Logger::ERROR);
            return;
        }
        $this->view->page()->event('iina-simple', [$what => $value]);
    }

    /** Posts message $name of $data to its page. */
    public function postMessage(string $name, mixed $data): void
    {
        $this->view->page()->event('iina', ['n' => $name, 'd' => $data]);
    }
}
