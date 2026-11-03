<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use Closure;
use MyPHP\Platform;

final class Page implements Platform\Page
{
    private static int $nextId = 1;
    public readonly int $id;
    private bool $closed = false;

    public function __construct(public ?Closure $onMessage = null)
    {
        $this->id = self::$nextId++;
    }

    public function handle(): mixed
    {
        return $this->id;
    }

    public function openDevTools(): void {}
    public function closeDevTools(): void {}
    public function isDevToolsOpened(): bool { return false; }
    public function setUserScripts(array $scripts): void {}

    public function loadURL(string $url): void {}
    public function loadHTML(string $html, string $baseURL): void {}
    public function eval(string $script, ?Closure $callback = null): void
    {
        if ($callback !== null) {
            $callback(null, null);
        }
    }

    public function postMessage(string $message): void {}

    public function reload(): void {}
    public function stopLoading(): void {}
    public function canGoBack(): bool { return false; }
    public function canGoForward(): bool { return false; }
    public function goBack(): void {}
    public function goForward(): void {}

    public function find(string $text, bool $forward, bool $matchCase, bool $findNext): void {}
    public function stopFinding(bool $keepSelection): void {}

    public function print(): void {}
    public function printToPDF(string $path, ?array $options = null, ?Closure $callback = null): void
    {
        if ($callback !== null) {
            $callback(false);
        }
    }

    public function zoom(): float { return 1.0; }
    public function setZoom(float $zoom): void {}
    public function setBackground(int $color): void {}

    public function close(): void
    {
        $this->closed = true;
    }
}
