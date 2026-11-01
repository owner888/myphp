<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use Closure;
use MyPHP\CloseEvent;
use MyPHP\Rectangle;
use MyPHP\UI\Content;
use MyPHP\UI\Context;
use MyPHP\Window;
use MyPHP\WindowOptions;

/**
 * IINA's iina.standaloneWindow (PluginStandaloneWindow): a window of the
 * plugin's own, 600 by 400, centered, titled "Window — <plugin>", a web
 * view filling it (a file of the plugin's, or IINA's simple mode's page);
 * made as it is first asked for, shown by open(); closing it hides it,
 * its page kept; its title, whether it resizes, fills under its title
 * bar or hides the bar, and its frame set; messages to and from its page.
 * It goes with the instance.
 */
final class StandaloneWindowApi extends Api
{
    /** IINA's simple mode's page of a standalone window. */
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
    }

    @media (prefers-color-scheme: dark) {
      body {
        color: #eee;
      }
      body a {
        color: #007aff;
      }
    }
  </style>
  <style id="style"></style>
</head>

<body>
  <div id="content"></div>
</body>

</html>
HTML;

    private ?Window $window = null;
    private ?WebPane $pane = null;
    private readonly MessageHub $hub;

    public function __construct(Instance $instance)
    {
        parent::__construct($instance);
        $this->hub = new MessageHub($instance);
        $instance->onCleanUp(function (): void { $this->window?->destroy(); $this->window = null; $this->pane = null; });
    }

    /** Its window and web view, made hidden the first time. */
    private function pane(): WebPane
    {
        if ($this->pane !== null && $this->window !== null && !$this->window->isDestroyed()) { return $this->pane; }
        $pane = null;
        $w = new Window(new WindowOptions(title: "Window — {$this->instance->plugin->name}", width: 600, height: 400, hidden: true,
            content: Content::view(function (Context $c) use (&$pane): void {
                $c->column(0, 0, 1);
                  if ($pane !== null) { $c->webView($pane->view)->grow(1); }
                $c->end();
            })));
        $w->center();
        $w->on('close', function (CloseEvent $e) use ($w): void { $e->cancel(); $w->hide(); }); // hidden, not gone: its page kept (IINA's isReleasedWhenClosed false)
        $this->window = $w;
        $pane = $this->pane = new WebPane($this->instance, $this->hub, $w, self::SIMPLE);
        $w->invalidate();
        return $pane;
    }

    public function open(): void
    {
        $this->pane();
        $this->window?->show();
    }

    public function close(): void
    {
        $this->window?->hide();
    }

    public function isOpen(): bool
    {
        return $this->window !== null && !$this->window->isDestroyed() && $this->window->isVisible();
    }

    public function loadFile(string $path): void
    {
        $this->pane()->loadFile($path);
    }

    public function simpleMode(): void
    {
        $this->pane()->simpleMode();
    }

    public function setStyle(string $style): void
    {
        $this->pane?->setSimple('style', $style, 'standaloneWindow');
    }

    public function setContent(string $content): void
    {
        $this->pane?->setSimple('content', $content, 'standaloneWindow');
    }

    public function postMessage(string $name, mixed $data = null): void
    {
        $this->pane?->postMessage($name, $data);
    }

    public function onMessage(string $name, Closure $callback): void
    {
        $this->hub->add($name, $callback);
    }

    /** Sets its title (after it "— <plugin>"), whether it resizes, fills under its title bar, hides the bar. @param array<string, mixed> $properties */
    public function setProperty(array $properties): void
    {
        $w = $this->window;
        if ($w === null || $w->isDestroyed()) { return; }
        foreach ($properties as $key => $value) {
            match ($key) {
                'title' => is_string($value) ? $w->setTitle("$value — {$this->instance->plugin->name}") : null,
                'resizable' => is_bool($value) ? $w->setResizable($value) : null,
                'fullSizeContentView' => is_bool($value) ? $w->setFullSizeContentView($value) : null,
                'hideTitleBar' => $w->setTitleBarHidden($value === true),
                default => null,
            };
        }
    }

    /** Sets its frame: its size and place, each kept as it is for null (MyPHP's: its top left from the main screen's). */
    public function setFrame(?float $w = null, ?float $h = null, ?float $x = null, ?float $y = null): void
    {
        $this->pane();
        $win = $this->window;
        $b = $win->bounds();
        $win->setBounds(new Rectangle((int)($x ?? $b->x), (int)($y ?? $b->y), (int)($w ?? $b->width), (int)($h ?? $b->height)));
    }
}
