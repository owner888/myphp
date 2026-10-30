<?php
declare(strict_types=1);

namespace MyPHP;

use MyPHP\Native\ObjC;

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
    /** The clip view the web view sits in, a subview of the window's content view under its native view: what of the page shows. */
    private $clip;
    private bool $destroyed = false, $shown = false;
    private ?Background $background = null;
    private static bool $defined = false;

    /** @internal Window::newWebView makes it */
    public function __construct(private readonly Window $host, $container, $below, WebViewOptions $o)
    {
        if (!self::$defined) {
            self::$defined = true;
            ObjC::defineClass('MyPHPClipView', 'NSView', [
                'isFlipped' => ['B@:', 'b(pp)', fn($self, $cmd) => true],
            ]);
        }
        $this->page = new Page($host, $this, ObjC::rect(0, 0, 0, 0), $o->page);
        $web = $this->page->web->ns;
        ObjC::msg('v:L', $web, 'setAutoresizingMask:', 0);
        $this->clip = ObjC::msg('p:r', ObjC::alloc('MyPHPClipView'), 'initWithFrame:', ObjC::rect(0, 0, 0, 0));
        ObjC::msg('v:b', $this->clip, 'setWantsLayer:', true);
        ObjC::msg('v:b', ObjC::msg('p:', $this->clip, 'layer'), 'setMasksToBounds:', true);
        ObjC::msg('v:L', $this->clip, 'setAutoresizingMask:', 8); // NSViewMinYMargin: it keeps its distance to the top of the unflipped content view
        ObjC::msg('v:b', $this->clip, 'setHidden:', true);
        ObjC::msg('v:p', $this->clip, 'addSubview:', $web);
        ObjC::msg('v:plp', $container, 'addSubview:positioned:relativeTo:', $this->clip, -1, $below); // NSWindowBelow: under the native view
        if ($o->transparent) {
            ObjC::msg('v:pp', $web, 'setValue:forKey:', ObjC::msg('p:b', ObjC::cls('NSNumber'), 'numberWithBool:', false), ObjC::str('drawsBackground'));
        } elseif ($o->backgroundColor !== '') {
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
            ObjC::msg('b:p', $this->host->nativeHandle(), 'makeFirstResponder:', $this->page->web->ns);
        }
    }

    /** @internal Tab onto the web view's node: its page takes the keyboard at its first element, or its last for $back, through the window's key view loop (mygo's TabInto) */
    public function tabInto(bool $back): void
    {
        if ($this->destroyed) {
            return;
        }
        $content = $this->host->contentView();
        $web = $this->page->web->ns;
        if ($back) {
            ObjC::msg('v:p', $web, 'setNextKeyView:', $content);
            ObjC::msg('v:p', $this->host->nativeHandle(), 'selectKeyViewPrecedingView:', $content);
        } else {
            ObjC::msg('v:p', $content, 'setNextKeyView:', $web);
            ObjC::msg('v:p', $this->host->nativeHandle(), 'selectKeyViewFollowingView:', $content);
        }
    }

    /** @internal whether $view is the web view or inside it */
    public function holds($view): bool
    {
        for ($v = $view; $v !== null; $v = ObjC::msg('p:', $v, 'superview')) {
            if (ObjC::addr($v) === ObjC::addr($this->page->web->ns)) {
                return true;
            }
        }
        return false;
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
        [$r, $g, $b, $a] = $this->background->for(App::theme()->dark);
        $c = ObjC::msg('p:dddd', ObjC::cls('NSColor'), 'colorWithSRGBRed:green:blue:alpha:', $r / 255, $g / 255, $b / 255, $a / 255);
        ObjC::msg('v:p', ObjC::msg('p:', $this->clip, 'layer'), 'setBackgroundColor:', ObjC::msg('p:', $c, 'CGColor'));
        $web = $this->page->web->ns;
        ObjC::msg('v:pp', $web, 'setValue:forKey:', ObjC::msg('p:b', ObjC::cls('NSNumber'), 'numberWithBool:', false), ObjC::str('drawsBackground'));
        if (ObjC::msg('b:p', $web, 'respondsToSelector:', ObjC::sel('setUnderPageBackgroundColor:'))) {
            ObjC::msg('v:p', $web, 'setUnderPageBackgroundColor:', $c);
        }
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
        ObjC::msg('v:', $this->clip, 'removeFromSuperview');
        ObjC::msg('v:', $this->clip, 'release');
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
        if ($this->destroyed) {
            return;
        }
        if ($frame === null || $clip === null) {
            if ($this->shown) {
                $this->shown = false;
                if ($this->hasKeyboard()) { // a hidden view keeps no keyboard: it goes back to the content
                    ObjC::msg('b:p', $this->host->nativeHandle(), 'makeFirstResponder:', $this->host->contentView());
                }
                ObjC::msg('v:b', $this->clip, 'setHidden:', true);
            }
            return;
        }
        [$cx, $cy, $cw, $ch] = $clip;
        [$fx, $fy, $fw, $fh] = $frame;
        ObjC::msg('v:r', $this->clip, 'setFrame:', ObjC::rect($cx, $height - $cy - $ch, $cw, $ch));
        ObjC::msg('v:r', $this->page->web->ns, 'setFrame:', ObjC::rect($fx - $cx, $fy - $cy, $fw, $fh)); // the clip view is flipped
        if (!$this->shown) {
            $this->shown = true;
            ObjC::msg('v:b', $this->clip, 'setHidden:', false);
        }
    }

    /** @internal the clip view, which the window stacks in paint order */
    public function clipView()
    {
        return $this->clip;
    }

    /** @internal whether the page has the keyboard */
    public function hasKeyboard(): bool
    {
        $first = ObjC::msg('p:', $this->host->nativeHandle(), 'firstResponder');
        return $first !== null && ObjC::msg('b:p', $first, 'isKindOfClass:', ObjC::cls('NSView')) && $this->holds($first);
    }
}
