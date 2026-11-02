<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use MyPHP\Platform;

/**
 * A web view in a window of native UI on Linux (mygo's linux webview.go):
 * the page's WebKitWebView is a child of a GtkLayout under the window's
 * GtkGLArea, in a GtkOverlay (Window::webLayer), hidden until the content
 * places it. The area's frames are transparent in the web views' holes,
 * and its input shape lets the pointer through to them there.
 */
final class SurfaceWebView implements Platform\WebView
{
    private bool $closed = false;
    /** @var ?array{0: float, 1: float, 2: float, 3: float} where it shows, in the content's points, null while hidden */
    public ?array $clip = null;

    public function __construct(private readonly Window $host, private readonly WebView $page, bool $scrollToContainer, bool $transparent)
    {
        $f = Gtk::ffi();
        $web = $page->web;
        // GTK moves the keyboard with Tab through focus signals, which WebKit's Tab past the page's ends starts: the content takes it
        // back, and GTK moving it into a page by itself, as a window shows, leaves it with the content (mygo's cbWebViewFocus).
        Gtk::connect($web, 'focus', 'focus', function ($widget, int $direction, $data): int {
            $f = Gtk::ffi();
            if ($this->closed) { return 0; }
            if ($f->gtk_widget_has_focus($this->page->web)) {
                if ($direction === 0 || $direction === 1) { // GTK_DIR_TAB_FORWARD, GTK_DIR_TAB_BACKWARD
                    $this->host->tabOut($direction === 1);
                    return 1;
                }
                return 0;
            }
            if ($f->gtk_container_get_focus_child($this->page->web) !== null) {
                return 0; // a dialog of WebKit's in the view has the keyboard
            }
            $this->host->focusContent();
            return 1;
        });
        if ($scrollToContainer) {
            $page->scrollTo = fn($event): bool => $this->host->scrollFrom($this->page->web, $event); // the wheel scrolls the content around it (mygo's cbWebViewScroll)
        }
        if ($transparent) {
            WebKit::ffi()->webkit_web_view_set_background_color($web, WebKit::ffi()->new('double[4]')); // GdkRGBA: all zero
        }
        $f->gtk_widget_set_no_show_all($web, 1); // hidden until placed
        $f->gtk_layout_put($host->webLayer(), $web, 0, 0);
    }

    public function focus(): void
    {
        Gtk::ffi()->gtk_widget_grab_focus($this->page->web);
    }

    /**
     * Gives the page the keyboard as Tab does (mygo's TabInto): WebKitGTK
     * focuses no element of the page as the view takes it: the page lets go
     * of the one it had, and the Tab that got here goes on into the page,
     * to its first element, or its last going back.
     */
    public function tabInto(bool $back): void
    {
        if ($this->closed) { return; }
        $this->page->evaluate('document.activeElement && document.activeElement.blur()');
        Gtk::ffi()->gtk_widget_grab_focus($this->page->web);
        $this->host->passTab($this->page->web);
    }

    public function holds(mixed $view): bool
    {
        return $view instanceof \FFI\CData && Gtk::addr($view) === Gtk::addr($this->page->web);
    }

    public function hasKeyboard(): bool
    {
        return !$this->closed && (bool)Gtk::ffi()->gtk_widget_has_focus($this->page->web);
    }

    /** Paints $rgba under the page (webkit_web_view_set_background_color, a GdkRGBA). */
    public function setBackgroundColor(array $rgba): void
    {
        $c = WebKit::ffi()->new('double[4]'); // GdkRGBA
        foreach ($rgba as $i => $v) { $c[$i] = $v / 255; }
        WebKit::ffi()->webkit_web_view_set_background_color($this->page->web, $c);
    }

    /** Shows the view at $frame in the layout, which is where the area is (mygo's PlaceWebViews); the area's shape follows (Window::shapeWebLayer). */
    public function place(?array $frame, ?array $clip, float $height): void
    {
        if ($this->closed) { return; }
        $f = Gtk::ffi();
        $web = $this->page->web;
        if ($frame === null || $clip === null) {
            $this->clip = null;
            if ($f->gtk_widget_get_visible($web)) {
                if ($f->gtk_widget_has_focus($web)) { $this->host->focusContent(); } // a hidden view keeps no keyboard
                $f->gtk_widget_hide($web);
            }
            return;
        }
        $this->clip = $clip;
        [$fx, $fy, $fw, $fh] = $frame;
        [$x, $y] = [(int)round($fx), (int)round($fy)];
        $f->gtk_layout_move($this->host->webLayer(), $web, $x, $y);
        $f->gtk_widget_set_size_request($web, (int)round($fx + $fw) - $x, (int)round($fy + $fh) - $y);
        if (!$f->gtk_widget_get_visible($web)) { $f->gtk_widget_show($web); }
    }

    public function setAlpha(float $alpha): void
    {
        Gtk::ffi()->gtk_widget_set_opacity($this->page->web, max(0.0, min(1.0, $alpha)));
    }

    /** The web view itself: GTK stacks the layout's children in the order they were put, which the paint order does not change. */
    public function clipView(): mixed
    {
        return $this->page->web;
    }

    public function close(): void
    {
        if ($this->closed) { return; }
        $this->closed = true;
        $this->clip = null;
        $f = Gtk::ffi();
        if ($f->gtk_widget_has_focus($this->page->web)) { $this->host->focusContent(); }
        $f->gtk_container_remove($this->host->webLayer(), $this->page->web); // the page holds its own reference
        $this->host->forget($this);
    }
}
