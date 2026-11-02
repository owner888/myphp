<?php
declare(strict_types=1);

namespace MyPHP\Platform;

use Closure;

/**
 * The web page of a window or of a web view of native UI (mygo's
 * platform.Page): WKWebView on macOS. The core page (\MyPHP\Page) sets its
 * handlers as public closures, where mygo has a WindowHandler: onNavigate,
 * onCommit, onFinish, onFail, onGone, onTitle, onChange, onOpen,
 * onClosedByPage, onPanel, onContextMenu, onPermission, onDownload,
 * onDownloadDone, onScheme, onSchemeDownload, onAuth, onCertificate; and
 * $scrollTo, the native view a page in native UI scrolls instead of itself.
 */
interface Page
{
    /** The native web view (WKWebView). */
    public function handle(): mixed;

    public function openDevTools(): void;

    public function closeDevTools(): void;

    public function isDevToolsOpened(): bool;

    /** @param list<string|array{0: string, 1: bool}> $scripts a script, or one and whether it goes in every frame */
    public function setUserScripts(array $scripts): void;

    /** @param list<mixed> $lists compiled content rule lists */
    public function setContentRules(array $lists): void;

    public function isAudible(): bool;

    public function setAudioMuted(bool $muted): void;

    public function isAudioMuted(): bool;

    public function captureState(int $kind): int;

    public function setCaptureState(int $kind, int $state): void;

    public function processID(): int;

    public function sessionState(): string;

    public function restoreSessionState(string $state): bool;

    public function close(): void;

    public function reload(bool $ignoreCache): void;

    public function stop(): void;

    public function goBack(): void;

    public function goForward(): void;

    public function canGoBack(): bool;

    public function canGoForward(): bool;

    public function isLoading(): bool;

    public function setZoom(float $f): void;

    public function zoom(): float;

    public function setUserAgent(string $ua): void;

    public function userAgent(): string;

    public function loadFile(string $path, string $readAccessDir): void;

    /** Runs $body as an async function's, $done($result, $error) once it settles. */
    public function callAsync(string $body, Closure $done): void;

    /** $done(?string $png) with a picture of the page as it shows. */
    public function capture(Closure $done): void;

    public function loadHTML(string $html, string $baseURL = ''): void;

    public function url(): string;

    public function loadURL(string $url): void;

    /** @param mixed $window the native window it prints from */
    public function print($window): void;

    /** @param mixed $window the native window @param array{0: float, 1: float, 2: float, 3: float} $margins */
    public function printToPDF($window, float $width, float $height, bool $landscape, array $margins, bool $background, Closure $done): void;

    public function allowsPictureInPicture(): ?bool;

    public function togglePictureInPicture(string $script): void;

    public function pictureInPictureActive(): bool;

    public function leavingPage(bool $pip): void;

    public function evaluate(string $js, $completion = null): void;

    /** Hands a bridge message (reply or event) to the page. */
    public function deliver(array $message): void;

    /**
     * Shows the context menu $menu the web view is about to open, from
     * $event, as $listen's listeners change it: $listen gets the menu as
     * MenuItems and where the pointer opened it (points of the web view,
     * null for the keyboard), and returns the event its listeners made of
     * it. The page's onContextMenu calls it when it has listeners.
     * @param \Closure(\MyPHP\Menu, ?array{0: float, 1: float}): \MyPHP\ContextMenuEvent $listen
     */
    public function showContextMenu($menu, $event, \Closure $listen): void;
}
