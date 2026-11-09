<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use FFI;
use FFI\CData;
use MyPHP\Native\Mem;
use MyPHP\Platform;

/**
 * A web view in a window of native UI: its page's controller in a child
 * window of the window's, placed and clipped where the content puts it,
 * above what Direct3D draws (the window clips its children). mygo's are
 * composition controllers in DirectComposition's visual tree, under
 * native UI's holes; a child window shows above the content that would
 * cover it (upstream-deviations.md).
 */
final class EmbeddedWebView implements Platform\WebView
{
    private const WS_CHILD = 0x40000000, WS_CLIPSIBLINGS = 0x04000000, WS_CLIPCHILDREN = 0x02000000;

    public CData $hwnd;
    private bool $shown = false, $closed = false;
    /** @var ?array{0: int, 1: int, 2: int, 3: int} where it was placed last, in pixels, which places it again only as it moves */
    private ?array $at = null;

    public function __construct(private readonly Window $w, private readonly WebView $page)
    {
        Win32::registerClass('MyPHPWebHost', 0);
        $this->hwnd = Win32::createWindow('MyPHPWebHost', '', self::WS_CHILD | self::WS_CLIPSIBLINGS | self::WS_CLIPCHILDREN, 0, function (int $m, int $wp, int $lp): ?int {
            if ($m === 0x0005) { // WM_SIZE: the page fills it
                $this->page->fit();
            }
            return null;
        }, 0, 0, 1, 1, $w->hwnd);
        $page->attach($w, $this->hwnd);
    }

    public function focus(): void
    {
        $this->page->focus();
    }

    public function tabInto(bool $back): void
    {
        $this->page->focus($back ? 2 : 1); // COREWEBVIEW2_MOVE_FOCUS_REASON_PREVIOUS, NEXT
    }

    /** Whether $view is the child window or inside it (WebView2's own windows are). */
    public function holds(mixed $view): bool
    {
        if (!$view instanceof CData) {
            return false;
        }
        return Mem::addr($view) === Mem::addr($this->hwnd) || Win32::user32()->IsChild($this->hwnd, $view) !== 0;
    }

    public function hasKeyboard(): bool
    {
        $f = Win32::user32()->GetFocus();
        return $f !== null && $this->holds($f);
    }

    public function setBackgroundColor(array $rgba): void
    {
        $this->page->background($rgba);
    }

    /** Shows it at $frame, of which $clip shows, in the window's points from its top left; null hides it. */
    public function place(?array $frame, ?array $clip, float $height): void
    {
        $u = Win32::user32();
        if ($frame === null || $this->closed) {
            if ($this->shown) {
                $u->ShowWindow($this->hwnd, 0); // SW_HIDE
                $this->shown = false;
            }
            return;
        }
        $s = $this->w->backingScale();
        $px = fn(float $v): int => (int)round($v * $s);
        $at = [$px($frame[0]), $px($frame[1]), max(1, $px($frame[2])), max(1, $px($frame[3]))];
        if ($at !== $this->at) {
            $this->at = $at;
            $u->SetWindowPos($this->hwnd, 0, $at[0], $at[1], $at[2], $at[3], 0x4 | 0x10); // SWP_NOZORDER, SWP_NOACTIVATE
            $this->page->moved();
        }
        // What of it shows: its clip, in its own pixels; all of it, no region.
        $g = FFI::cdef('void *CreateRectRgn(int32_t l, int32_t t, int32_t r, int32_t b);', 'gdi32.dll');
        $whole = $clip === null || ($clip[0] <= $frame[0] && $clip[1] <= $frame[1] && $clip[0] + $clip[2] >= $frame[0] + $frame[2] && $clip[1] + $clip[3] >= $frame[1] + $frame[3]);
        $u->SetWindowRgn($this->hwnd, $whole ? null : $g->CreateRectRgn($px($clip[0] - $frame[0]), $px($clip[1] - $frame[1]), $px($clip[0] + $clip[2] - $frame[0]), $px($clip[1] + $clip[3] - $frame[1])), 1); // the system owns the region
        if (!$this->shown) {
            $u->ShowWindow($this->hwnd, 8); // SW_SHOWNA
            $this->shown = true;
        }
    }

    /** A child window's opacity is not WebView2's to blend: it shows or it does not. */
    public function setAlpha(float $alpha): void
    {
        if ($alpha <= 0 && $this->shown) {
            Win32::user32()->ShowWindow($this->hwnd, 0);
            $this->shown = false;
        }
    }

    public function clipView(): mixed
    {
        return $this->hwnd;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->page->close();
        Win32::user32()->DestroyWindow($this->hwnd);
    }
}
