<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use Closure;
use MyPHP\Native\iOS;
use MyPHP\Platform;

/**
 * iOS system WebView page via WKWebView over WebKit.framework.
 */
final class Page implements Platform\Page
{
    /** @var array<int, self> */
    private static array $pages = [];
    private static int $nextId = 1;

    public readonly int $id;
    private bool $closed = false;

    /** @var ?Closure(string): void */
    public ?Closure $onMessage = null;

    public function __construct(?Closure $onMessage = null)
    {
        $this->id = self::$nextId++;
        $this->onMessage = $onMessage;
        self::$pages[$this->id] = $this;

        iOS::ffi()->myphp_ios_create_web_view($this->id);
    }

    public static function onWebMessage(int $pageId, string $message): void
    {
        $page = self::$pages[$pageId] ?? null;
        if ($page !== null && $page->onMessage !== null) {
            ($page->onMessage)($message);
        }
    }

    public function handle(): mixed
    {
        return $this->id;
    }

    public function openDevTools(): void {}
    public function closeDevTools(): void {}
    public function isDevToolsOpened(): bool { return false; }
    public function setUserScripts(array $scripts): void {}
    public function setContentRules(array $lists): void {}
    public function isAudible(): bool { return false; }
    public function setAudioMuted(bool $muted): void {}
    public function isAudioMuted(): bool { return false; }
    public function captureState(int $kind): int { return 0; }
    public function setCaptureState(int $kind, int $state): void {}
    public function processID(): int { return getmypid(); }
    public function sessionState(): string { return ''; }
    public function restoreSessionState(string $state): bool { return false; }

    public function reload(bool $ignoreCache): void
    {
        $this->evaluate('location.reload();');
    }

    public function stop(): void
    {
        $this->evaluate('window.stop();');
    }

    public function goBack(): void
    {
        $this->evaluate('history.back();');
    }

    public function goForward(): void
    {
        $this->evaluate('history.forward();');
    }

    public function canGoBack(): bool { return false; }
    public function canGoForward(): bool { return false; }
    public function isLoading(): bool { return false; }
    public function setZoom(float $f): void {}
    public function zoom(): float { return 1.0; }
    public function setUserAgent(string $ua): void {}
    public function userAgent(): string { return ''; }
    public function loadFile(string $path, string $readAccessDir): void
    {
        $this->loadURL('file://' . $path);
    }

    public function callAsync(string $body, Closure $done): void
    {
        $done(null, null);
    }

    public function capture(Closure $done): void
    {
        $done(null);
    }

    public function loadHTML(string $html, string $baseURL = ''): void
    {
        if (!$this->closed) {
            iOS::ffi()->myphp_ios_load_html($this->id, $html, $baseURL);
        }
    }

    public function url(): string
    {
        return '';
    }

    public function loadURL(string $url): void
    {
        if (!$this->closed) {
            iOS::ffi()->myphp_ios_load_url($this->id, $url);
        }
    }

    public function print($window): void {}
    public function printToPDF($window, float $width, float $height, bool $landscape, array $margins, bool $background, Closure $done): void
    {
        $done(null);
    }

    public function allowsPictureInPicture(): ?bool { return false; }
    public function togglePictureInPicture(string $script): void {}
    public function pictureInPictureActive(): bool { return false; }
    public function leavingPage(bool $pip): void {}

    public function evaluate(string $js, $completion = null): void
    {
        if (!$this->closed) {
            iOS::ffi()->myphp_ios_eval_js($this->id, $js);
        }
    }

    public function deliver(array $message): void
    {
        $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $script = "if(window.myphp&&window.myphp.__deliver){window.myphp.__deliver({$json});}";
        $this->evaluate($script);
    }

    public function contextMenu(array $menu, array $event, \MyPHP\EventListen $listen): void {}
    public function scrollTo(): mixed { return null; }

    public function close(): void
    {
        if (!$this->closed) {
            $this->closed = true;
            unset(self::$pages[$this->id]);
            iOS::ffi()->myphp_ios_destroy_web_view($this->id);
        }
    }
}
