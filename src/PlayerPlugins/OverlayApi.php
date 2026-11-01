<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use Closure;
use MyPHP\Logger;

/**
 * IINA's iina.overlay, of the video-overlay permission: a web view over
 * the picture, under the controls (MediaPlayer::pluginOverlays), loaded
 * with a file of the plugin's or IINA's simple mode's page (its style and
 * content set), hidden till shown, at an opacity; the pointer through it
 * but where setClickable lets its elements of data-clickable take it;
 * messages to and from its page. Made once the window is.
 */
final class OverlayApi extends Api
{
    /** IINA's simple mode's page of an overlay. */
    private const SIMPLE = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Overlay</title>
    <style>
    body {
        font-size: 13px;
        font-family: -apple-system, BlinkMacSystemFont, 'Helvetica Neue', sans-serif;
        color: white;
        text-shadow: 0 1px 0 black, 0 -1px 0 black, -1px 0 0 black, 1px 0 0 black;
    }
    </style>
    <style id="style"></style>
</head>
<body>
    <div id="content"></div>
</body>
</html>
HTML;

    /** @internal MediaPlayer: its web view, null till loaded; whether it shows, its opacity, whether its elements of data-clickable take the pointer. */
    public ?WebPane $pane = null;
    public bool $shown = false, $clickable = false;
    public float $opacity = 1.0;

    /** Its web view, made in the player's window (which must be there: after iina.window-loaded, IINA's). */
    private function pane(string $call): WebPane
    {
        $this->require(Plugin::OVERLAY);
        $s = $this->player();
        $w = $s->window() ?? $this->fail("overlay.$call called when window is not available. Please call it after receiving the \"iina.window-loaded\" event.");
        return $this->pane ??= new WebPane($this->instance, new MessageHub($this->instance), $w, self::SIMPLE, true, fn() => $s->emitForPlugins('iina.plugin-overlay-loaded'));
    }

    /** Whether it may act: loaded, of the permission (IINA's guard of most of its calls). */
    private function loaded(): bool
    {
        return $this->pane !== null && $this->permitted(Plugin::OVERLAY);
    }

    public function show(): void
    {
        if ($this->loaded()) { $this->shown = true; $this->player()->refresh(); }
    }

    public function hide(): void
    {
        if ($this->loaded()) { $this->shown = false; $this->player()->refresh(); }
    }

    public function setOpacity(float $opacity): void
    {
        if ($this->loaded()) { $this->opacity = $opacity; $this->pane->view->setAlpha($opacity); }
    }

    public function loadFile(string $path): void
    {
        $this->pane('loadFile')->loadFile($path);
    }

    public function simpleMode(): void
    {
        $this->pane('simpleMode')->simpleMode();
    }

    public function setStyle(string $style): void
    {
        if ($this->loaded()) { $this->pane->setSimple('style', $style, 'overlay'); }
    }

    public function setContent(string $content): void
    {
        if ($this->loaded()) { $this->pane->setSimple('content', $content, 'overlay'); }
    }

    /** Whether its page's elements of data-clickable take the pointer (none by default). */
    public function setClickable(bool $clickable): void
    {
        $this->clickable = $clickable;
        $this->instance->player?->refresh();
    }

    public function postMessage(string $name, mixed $data = null): void
    {
        if ($this->loaded()) { $this->pane->postMessage($name, $data); }
    }

    public function onMessage(string $name, Closure $callback): void
    {
        if ($this->loaded()) { $this->pane->hub->add($name, $callback); } else { $this->log('overlay.onMessage: called before overlay.loadFile or overlay.simpleMode', Logger::WARNING); }
    }
}
