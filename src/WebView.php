<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * A web page inside a window's native UI, which the window's view places
 * with Context::webView (mygo's WebView): a document the web engine
 * renders beside native controls, a page per tab, a preview of HTML the
 * app makes.
 *
 *     $docs = $win->newWebView(new WebViewOptions(url: '/docs'));
 *
 *     // In the view:
 *     $c->row()->grow(1);
 *       sidebar($c);
 *       $c->webView($docs)->grow(1);
 *     $c->end();
 *
 * Its page works as a window's does: it loads the app's frontend, calls
 * bound methods (CallContext::page tells which page called,
 * CallContext::window the window it is in), hears events
 * (Event::emitPage, broadcast) and evaluates scripts. The web view shows
 * where the view places it, under what the view paints after it, while
 * the view builds it; it keeps its page while it does not show, until
 * destroy() or until its window closes.
 */
final class WebView
{
    private Page $page;
    /** The backend's web view, which the window places. */
    private Platform\WebView $native;
    private bool $destroyed = false;
    private ?Background $background = null;

    /** @internal Window::newWebView makes it */
    public function __construct(private readonly Window $host, WebViewOptions $o)
    {
        $this->page = new Page($host, $this, 0, 0, $o->page);
        $this->native = $host->native()->newWebView($this->page->web, $o->scrollToContainer, $o->transparent);
        if (!$o->transparent && $o->backgroundColor !== '') {
            $this->setBackgroundColor($o->backgroundColor);
        }
        $this->page->loadFirst($o->page->sessionState, $o->url);
    }

    /** The web view's page (mygo's Page). */
    public function page(): Page
    {
        return $this->page;
    }

    /** The window the web view is in (mygo's Window). */
    public function window(): Window
    {
        return $this->host;
    }

    /** Gives the web view the keyboard, once it shows (mygo's Focus). */
    public function focus(): void
    {
        if (!$this->destroyed) {
            $this->native->focus();
        }
    }

    /** @internal Tab onto the web view's node: its page takes the keyboard at its first element, or its last for $back (mygo's TabInto) */
    public function tabInto(bool $back): void
    {
        if (!$this->destroyed) {
            $this->native->tabInto($back);
        }
    }

    /** @internal whether the native view $view is the web view or inside it */
    public function holds($view): bool
    {
        return $this->native->holds($view);
    }

    /** Sets the color filling the web view until its page paints, as WebViewOptions::$backgroundColor says it; one of light-dark() follows the appearance (mygo's SetBackgroundColor). */
    public function setBackgroundColor(string $color): void
    {
        $this->background = Background::parse($color);
        $this->applyBackground();
    }

    /** @internal the appearance changed */
    public function applyBackground(): void
    {
        if ($this->background === null || $this->destroyed) {
            return;
        }
        $this->native->setBackgroundColor($this->background->for(App::theme()->dark));
    }

    /** A PNG of the web view's page (mygo's CapturePage). */
    public function capturePage(): string
    {
        return $this->page->capture();
    }

    /** Closes the web view and its page; its window no longer shows it where the view still places it (mygo's Destroy). */
    public function destroy(): void
    {
        if ($this->destroyed) {
            return;
        }
        $this->destroyed = true;
        $this->page->destroy();
        $this->native->close();
        $this->host->forgetWebView($this);
    }

    /** Whether the web view was destroyed, or its window closed (mygo's IsDestroyed). */
    public function isDestroyed(): bool
    {
        return $this->destroyed;
    }

    /**
     * @internal shows the web view at $frame, of which $clip shows, in the
     * points of the window's flipped native view of height $height; null
     * hides it (mygo's PlaceWebViews)
     * @param ?array{0: float, 1: float, 2: float, 3: float} $frame
     * @param ?array{0: float, 1: float, 2: float, 3: float} $clip
     */
    public function place(?array $frame, ?array $clip, float $height): void
    {
        if (!$this->destroyed) {
            $this->native->place($frame, $clip, $height);
        }
    }

    /** Shows the web view at $alpha, 0 to 1: a player's plugin's overlay's opacity. */
    public function setAlpha(float $alpha): void
    {
        $this->native->setAlpha($alpha);
    }

    /** @internal the clip view, which the window stacks in paint order */
    public function clipView()
    {
        return $this->native->clipView();
    }

    /** @internal whether the page has the keyboard */
    public function hasKeyboard(): bool
    {
        return $this->native->hasKeyboard();
    }
}
