<?php
declare(strict_types=1);

namespace MyPHP\Platform\Darwin;

use MyPHP\Native\ObjC;
use MyPHP\Platform;

/**
 * A web view in native UI on macOS: the page's WKWebView in a flipped clip
 * view of the window's content view, under the native view (mygo's darwin
 * surface web views).
 */
final class WebView implements Platform\WebView
{
    /** The clip view the web view sits in, a subview of the window's content view under its native view: what of the page shows. */
    private $clip;
    private bool $shown = false, $closed = false;
    private static bool $defined = false;

    public function __construct(private readonly Window $host, private readonly Platform\Page $page, bool $scrollToContainer, bool $transparent)
    {
        if (!self::$defined) {
            self::$defined = true;
            ObjC::defineClass('MyPHPClipView', 'NSView', [
                'isFlipped' => ['B@:', 'b(pp)', fn($self, $cmd) => true],
            ]);
        }
        $web = $page->handle();
        $below = $host->contentView();
        if ($scrollToContainer) { $page->scrollTo = $below; }
        ObjC::msg('v:L', $web, 'setAutoresizingMask:', 0);
        $this->clip = ObjC::msg('p:r', ObjC::alloc('MyPHPClipView'), 'initWithFrame:', ObjC::rect(0, 0, 0, 0));
        ObjC::msg('v:b', $this->clip, 'setWantsLayer:', true);
        ObjC::msg('v:b', ObjC::msg('p:', $this->clip, 'layer'), 'setMasksToBounds:', true);
        ObjC::msg('v:L', $this->clip, 'setAutoresizingMask:', 8); // NSViewMinYMargin: it keeps its distance to the top of the unflipped content view
        ObjC::msg('v:b', $this->clip, 'setHidden:', true);
        ObjC::msg('v:p', $this->clip, 'addSubview:', $web);
        ObjC::msg('v:plp', $host->container, 'addSubview:positioned:relativeTo:', $this->clip, -1, $below); // NSWindowBelow: under the native view
        if ($transparent) {
            ObjC::msg('v:pp', $web, 'setValue:forKey:', ObjC::msg('p:b', ObjC::cls('NSNumber'), 'numberWithBool:', false), ObjC::str('drawsBackground'));
        }
    }

    public function focus(): void
    {
        ObjC::msg('b:p', $this->host->ns, 'makeFirstResponder:', $this->page->handle());
    }

    /** Through the window's key view loop (mygo's TabInto). */
    public function tabInto(bool $back): void
    {
        $content = $this->host->contentView();
        $web = $this->page->handle();
        if ($back) {
            ObjC::msg('v:p', $web, 'setNextKeyView:', $content);
            ObjC::msg('v:p', $this->host->ns, 'selectKeyViewPrecedingView:', $content);
        } else {
            ObjC::msg('v:p', $content, 'setNextKeyView:', $web);
            ObjC::msg('v:p', $this->host->ns, 'selectKeyViewFollowingView:', $content);
        }
    }

    public function holds(mixed $view): bool
    {
        for ($v = $view; $v !== null; $v = ObjC::msg('p:', $v, 'superview')) {
            if (ObjC::addr($v) === ObjC::addr($this->page->handle())) {
                return true;
            }
        }
        return false;
    }

    public function hasKeyboard(): bool
    {
        $first = ObjC::msg('p:', $this->host->ns, 'firstResponder');
        return $first !== null && ObjC::msg('b:p', $first, 'isKindOfClass:', ObjC::cls('NSView')) && $this->holds($first);
    }

    public function setBackgroundColor(array $rgba): void
    {
        [$r, $g, $b, $a] = $rgba;
        $c = ObjC::msg('p:dddd', ObjC::cls('NSColor'), 'colorWithSRGBRed:green:blue:alpha:', $r / 255, $g / 255, $b / 255, $a / 255);
        ObjC::msg('v:p', ObjC::msg('p:', $this->clip, 'layer'), 'setBackgroundColor:', ObjC::msg('p:', $c, 'CGColor'));
        $web = $this->page->handle();
        ObjC::msg('v:pp', $web, 'setValue:forKey:', ObjC::msg('p:b', ObjC::cls('NSNumber'), 'numberWithBool:', false), ObjC::str('drawsBackground'));
        if (ObjC::msg('b:p', $web, 'respondsToSelector:', ObjC::sel('setUnderPageBackgroundColor:'))) {
            ObjC::msg('v:p', $web, 'setUnderPageBackgroundColor:', $c);
        }
    }

    public function place(?array $frame, ?array $clip, float $height): void
    {
        if ($this->closed) {
            return;
        }
        if ($frame === null || $clip === null) {
            if ($this->shown) {
                $this->shown = false;
                if ($this->hasKeyboard()) { // a hidden view keeps no keyboard: it goes back to the content
                    ObjC::msg('b:p', $this->host->ns, 'makeFirstResponder:', $this->host->contentView());
                }
                ObjC::msg('v:b', $this->clip, 'setHidden:', true);
            }
            return;
        }
        [$cx, $cy, $cw, $ch] = $clip;
        [$fx, $fy, $fw, $fh] = $frame;
        ObjC::msg('v:r', $this->clip, 'setFrame:', ObjC::rect($cx, $height - $cy - $ch, $cw, $ch));
        ObjC::msg('v:r', $this->page->handle(), 'setFrame:', ObjC::rect($fx - $cx, $fy - $cy, $fw, $fh)); // the clip view is flipped
        if (!$this->shown) {
            $this->shown = true;
            ObjC::msg('v:b', $this->clip, 'setHidden:', false);
        }
    }

    public function setAlpha(float $alpha): void
    {
        ObjC::msg('v:d', $this->clip, 'setAlphaValue:', max(0.0, min(1.0, $alpha)));
    }

    public function clipView(): mixed
    {
        return $this->clip;
    }

    public function close(): void
    {
        $this->closed = true;
        ObjC::msg('v:', $this->clip, 'removeFromSuperview');
        ObjC::msg('v:', $this->clip, 'release');
    }
}
