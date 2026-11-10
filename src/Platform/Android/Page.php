<?php
declare(strict_types=1);

namespace MyPHP\Platform\Android;

use Closure;
use MyPHP\Native\Android;
use MyPHP\Platform;

/**
 * Android system WebView page via android.webkit.WebView over JNI.
 */
final class Page implements Platform\Page
{
    /** @var array<int, self> */
    private static array $pages = [];
    private static int $nextId = 1;

    public readonly int $id;
    private bool $closed = false;
    private string $url = '';

    /** @var ?Closure(string): void */
    public ?Closure $onMessage = null;
    public ?Closure $onNavigate = null;
    public ?Closure $onCommit = null;
    public ?Closure $onFinish = null;
    public ?Closure $onFail = null;
    public ?Closure $onGone = null;
    public ?Closure $onTitle = null;
    public ?Closure $onChange = null;
    public ?Closure $onOpen = null;
    public ?Closure $onClosedByPage = null;
    public ?Closure $onPanel = null;
    public ?Closure $onContextMenu = null;
    public ?Closure $onPermission = null;
    public ?Closure $onDownload = null;
    public ?Closure $onDownloadDone = null;
    public ?Closure $onScheme = null;
    public ?Closure $onSchemeDownload = null;
    public ?Closure $onAuth = null;
    public ?Closure $onCertificate = null;
    public mixed $scrollTo = null;

    public function __construct(?Closure $onMessage = null, array $scripts = [])
    {
        $this->id = self::$nextId++;
        $this->onMessage = $onMessage;
        self::$pages[$this->id] = $this;

        Android::ffi()->myphp_create_web_view($this->id);
        if ($scripts !== []) {
            $this->setUserScripts($scripts);
        }
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
        $done(null, 'myphp: page capture is not supported on Android');
    }

    public function loadHTML(string $html, string $baseURL = ''): void
    {
        if (!$this->closed) {
            $this->url = $baseURL !== '' ? $baseURL : 'about:blank';
            Android::ffi()->myphp_load_html($this->id, $html, $baseURL);
        }
    }

    public function url(): string
    {
        return $this->url;
    }

    public function loadURL(string $url): void
    {
        if (!$this->closed) {
            $this->url = $url;
            Android::ffi()->myphp_load_url($this->id, $url);
        }
    }

    public function print($window): void {}
    public function printToPDF($window, float $width, float $height, bool $landscape, array $margins, bool $background, Closure $done): void
    {
        $done(null, 'myphp: printToPDF is not supported on Android');
    }

    public function allowsPictureInPicture(): ?bool { return false; }
    public function togglePictureInPicture(string $script): void {}
    public function pictureInPictureActive(): bool { return false; }
    public function leavingPage(bool $pip): void {}

    public function evaluate(string $js, $completion = null): void
    {
        if (!$this->closed) {
            Android::ffi()->myphp_eval_js($this->id, $js);
        }
    }

    public function deliver(array $message): void
    {
        $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $script = "if(window.myphp&&window.myphp.__deliver){window.myphp.__deliver({$json});}";
        $this->evaluate($script);
    }

    public function showContextMenu($menu, $event, Closure $listen): void {}

    public function close(): void
    {
        if (!$this->closed) {
            $this->closed = true;
            unset(self::$pages[$this->id]);
            Android::ffi()->myphp_destroy_web_view($this->id);
        }
    }
}