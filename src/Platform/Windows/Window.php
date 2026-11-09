<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use FFI;
use FFI\CData;
use MyPHP\Native\{D3d, Mem};
use MyPHP\{App, Rectangle, WindowOptions};
use MyPHP\Platform;
use MyPHP\Platform\Unsupported;
use MyPHP\UI\{Cursor, Image};

/**
 * A top-level window of Win32 (mygo's windows window): its frame in
 * points, a pixel of its display's DPI over 96 a point (per monitor v2,
 * WM_DPICHANGED moving it to the size Windows suggests); its title bar
 * dark with the app's appearance (DWM); its events to the core: closing
 * asked (WM_CLOSE), closed, focus and blur (WM_ACTIVATE), moved, resized,
 * minimized and restored (WM_SIZE). Native UI's frames are built when
 * asked for, from the queue (requestFrame), drawn by Direct3D 11 into the
 * window's swap chain (Native\D3d) and presented at the display's
 * refresh; WM_PAINT shows the last one again. Web pages come with
 * WebView2's stage.
 */
final class Window implements Platform\Window
{
    private const WS_OVERLAPPEDWINDOW = 0x00CF0000, WS_POPUP = 0x80000000, WS_CLIPCHILDREN = 0x02000000;
    private const WS_THICKFRAME = 0x00040000, WS_MINIMIZEBOX = 0x00020000, WS_MAXIMIZEBOX = 0x00010000, WS_CAPTION = 0x00C00000, WS_SYSMENU = 0x00080000;
    private const WS_EX_TOPMOST = 0x8, WS_EX_TRANSPARENT = 0x20, WS_EX_TOOLWINDOW = 0x80, WS_EX_LAYERED = 0x80000;
    private const GWL_STYLE = -16, GWL_EXSTYLE = -20;
    private const SWP_NOSIZE = 0x1, SWP_NOMOVE = 0x2, SWP_NOZORDER = 0x4, SWP_NOACTIVATE = 0x10, SWP_FRAMECHANGED = 0x20;
    private const SW_HIDE = 0, SW_MAXIMIZE = 3, SW_SHOW = 5, SW_MINIMIZE = 6, SW_SHOWNA = 8, SW_RESTORE = 9;
    private const CURSORS = ['default' => 32512, 'text' => 32513, 'crosshair' => 32515, 'pointer' => 32649, 'move' => 32646, 'grab' => 32646, 'grabbing' => 32646,
        'ew-resize' => 32644, 'e-resize' => 32644, 'w-resize' => 32644, 'col-resize' => 32644, 'ns-resize' => 32645, 'n-resize' => 32645, 's-resize' => 32645, 'row-resize' => 32645,
        'nwse-resize' => 32642, 'nesw-resize' => 32643, 'not-allowed' => 32648, 'vertical-text' => 32513, 'copy' => 32512, 'alias' => 32512, 'context-menu' => 32512]; // IDC_ of each

    /** @var array<int, self> the windows open, by their handles */
    private static array $open = [];

    public CData $hwnd;
    private bool $dead = false;
    /** Whether the window is being made: Windows sends its sizes and moves at once, while the core has no native of it yet, to hear them. */
    private bool $making = true;
    private bool $minimized = false;
    private ?\Closure $frame = null;
    private ?\Closure $afterPresent = null;
    private bool $transparent = false;
    /** Native UI's renderer, made with its surface. */
    private ?D3d $d3d = null;
    /** Whether a frame was asked for and not drawn yet; whether every ask since the last frame only paints again; whether the frame drawing now was such; whether one is drawing; whether the frame drawing asked for another. */
    private bool $asked = false, $repaintOnly = true, $repaintNow = false, $rendering = false, $again = false;
    /** @var array{0: int, 1: int, 2: float, 3: array{0: float, 1: float, 2: float, 3: float}} the last frame's pixels, pixels a point and clear color */
    private array $drawn = [0, 0, 1.0, [0.0, 0.0, 0.0, 0.0]];
    /** @var ?array{0: float, 1: float, 2: float, 3: float} the background set, 0 to 1 */
    private ?array $background = null;
    /** @var array{0: int, 1: int} */
    private array $minSize = [0, 0], $maxSize = [0, 0];
    private float $aspect = 0.0;
    private bool $movable = true;
    private bool $topmost = false;
    private bool $shadow = true;
    private ?Cursor $cursorShown = null;
    /** @var ?array{0: int, 1: int, 2: CData} the style, the extended one and the placement before full screen */
    private ?array $beforeFullScreen = null;

    public function __construct(private readonly Backend $b, public readonly \MyPHP\Window $w, WindowOptions $o, bool $panel)
    {
        $u = Win32::user32();
        Win32::registerClass('MyPHPWindow');
        $flyout = $w->flyoutState() !== null;
        $style = $o->frameless || $flyout ? self::WS_POPUP : self::WS_OVERLAPPEDWINDOW;
        if ($o->disableResize) { $style &= ~(self::WS_THICKFRAME | self::WS_MAXIMIZEBOX); }
        $ex = ($panel || $flyout ? self::WS_EX_TOOLWINDOW : 0) | ($o->alwaysOnTop ? self::WS_EX_TOPMOST : 0);
        $parent = $w->parent()?->native();
        $this->hwnd = Win32::createWindow('MyPHPWindow', $o->title, $style | self::WS_CLIPCHILDREN, $ex, fn(int $m, int $wp, int $lp): ?int => $this->message($m, $wp, $lp),
            -0x80000000, -0x80000000, 100, 100, $parent instanceof self ? $parent->hwnd : null); // CW_USEDEFAULT: where Windows puts a new window
        self::$open[Mem::addr($this->hwnd)] = $this;
        $this->topmost = $o->alwaysOnTop;
        $this->sizeContent(max(1, $o->width), max(1, $o->height));
        if ($o->disableClose) { $this->setClosable(false); }
        if ($o->minWidth > 0 || $o->minHeight > 0) { $this->setMinimumSize($o->minWidth, $o->minHeight); }
        if ($o->maxWidth > 0 || $o->maxHeight > 0) { $this->setMaximumSize($o->maxWidth, $o->maxHeight); }
        if ($o->opacity > 0 && $o->opacity < 1) { $this->setOpacity($o->opacity); }
        $this->themeChanged();
        $this->making = false;
    }

    /** The open windows' natives. @return list<self> */
    public static function all(): array
    {
        return array_values(self::$open);
    }

    /** The window of the foreground one, if it is the app's. */
    public static function active(): ?self
    {
        return self::$open[Mem::addr(Win32::user32()->GetForegroundWindow())] ?? null;
    }

    /** The window's messages: what the core hears of, and the sizes it may take. */
    private function message(int $m, int $wp, int $lp): ?int
    {
        $u = Win32::user32();
        if ($this->making && in_array($m, [Win32::WM_MOVE, Win32::WM_SIZE, Win32::WM_ACTIVATE], true)) {
            return null; // the core has no native of the window yet
        }
        switch ($m) {
            case Win32::WM_CLOSE:
                if ($this->w->shouldClose()) { $u->DestroyWindow($this->hwnd); }
                return 0;
            case Win32::WM_DESTROY:
                $this->dead = true;
                $this->d3d?->release();
                $this->d3d = null;
                unset(self::$open[Mem::addr($this->hwnd)]);
                $this->w->closed();
                return 0;
            case Win32::WM_ACTIVATE:
                $this->w->nativeEvent(($wp & 0xFFFF) === 0 ? 'blur' : 'focus');
                return null;
            case Win32::WM_MOVE:
                $this->w->nativeEvent('move');
                return null;
            case Win32::WM_SIZE:
                if ($wp === 1) { // SIZE_MINIMIZED
                    if (!$this->minimized) { $this->minimized = true; $this->w->nativeEvent('minimize'); }
                    return null;
                }
                if ($this->minimized) { $this->minimized = false; $this->w->nativeEvent('restore'); }
                $this->w->nativeEvent('resize');
                $this->requestFrame();
                return null;
            case Win32::WM_DPICHANGED: // to its new display: the size Windows suggests, in the new DPI
                $r = $u->cast('RECT *', $lp);
                $u->SetWindowPos($this->hwnd, 0, $r->left, $r->top, $r->right - $r->left, $r->bottom - $r->top, self::SWP_NOZORDER | self::SWP_NOACTIVATE);
                $this->w->nativeEvent('resize');
                return 0;
            case Win32::WM_GETMINMAXINFO:
                $this->limits($u->cast('MINMAXINFO *', $lp));
                return 0;
            case Win32::WM_ERASEBKGND:
                return 1; // WM_PAINT paints it all
            case Win32::WM_PAINT:
                $this->paint();
                return 0;
            case Win32::WM_SETCURSOR:
                if (($lp & 0xFFFF) === 1 && $this->cursorShown !== null) { // HTCLIENT: the content's cursor
                    $u->SetCursor($this->cursorShown === Cursor::None ? null : $u->LoadCursorW(null, self::CURSORS[$this->cursorShown->value] ?? 32512));
                    return 1;
                }
                return null;
        }
        return null;
    }

    /**
     * WM_PAINT: the last frame shown again, at the size now (as Linux's
     * render signal); before native UI's first, or for a window of no
     * native UI, the background painted.
     */
    private function paint(): void
    {
        $u = Win32::user32();
        $ps = $u->new('PAINTSTRUCT');
        $dc = $u->BeginPaint($this->hwnd, FFI::addr($ps));
        if ($this->d3d !== null && $this->drawn[0] > 0 && !$this->rendering) {
            $u->EndPaint($this->hwnd, FFI::addr($ps));
            [$pw, $ph] = $this->clientPixels();
            $this->d3d->draw($this->d3d->lastList(), $pw, $ph, $this->drawn[2], $this->clearColor());
            $this->d3d->present();
            return;
        }
        [$r, $g, $b] = $this->clearColor();
        $gdi = Win32::gdi32();
        $brush = $gdi->CreateSolidBrush((int)round($r * 255) | ((int)round($g * 255) << 8) | ((int)round($b * 255) << 16)); // COLORREF: 0x00BBGGRR
        $u->FillRect($dc, FFI::addr($ps->rcPaint), $brush);
        $gdi->DeleteObject($brush);
        $u->EndPaint($this->hwnd, FFI::addr($ps));
    }

    /** The client area's size in pixels, one at least each way. @return array{0: int, 1: int} */
    private function clientPixels(): array
    {
        $r = Win32::user32()->new('RECT');
        Win32::user32()->GetClientRect($this->hwnd, FFI::addr($r));
        return [max(1, $r->right - $r->left), max(1, $r->bottom - $r->top)];
    }

    /**
     * A frame built and drawn, from the queue: the list built at the client
     * area's size in points, drawn at its pixels, presented; another asked
     * for meanwhile (an animation's) follows at the queue's next turn,
     * Present having waited for the display's refresh.
     */
    private function frameNow(): void
    {
        $this->asked = false;
        $this->repaintNow = $this->repaintOnly;
        $this->repaintOnly = true;
        if ($this->d3d === null || $this->dead || $this->minimized) {
            return;
        }
        $this->rendering = true;
        try {
            [$pw, $ph] = $this->clientPixels();
            $scale = $this->scale();
            $text = App::text();
            $this->d3d->setAtlasScale($text->scale());
            $this->d3d->setDark(App::theme()->dark);
            $list = $this->d3d->nextList();
            ($this->frame)($list, $pw / $scale, $ph / $scale);
            if ($this->dead) {
                return;
            }
            $clear = $this->clearColor();
            $this->d3d->draw($list, $pw, $ph, $scale * $list->zoom, $clear);
            $this->d3d->present();
            $this->drawn = [$pw, $ph, $scale * $list->zoom, $clear];
            $this->afterPresent?->__invoke();
            $this->w->runtime?->framePresented();
        } finally {
            $this->rendering = false;
        }
        if ($this->again) {
            $this->again = false;
            $this->requestFrame(1, $this->repaintOnly);
        }
    }

    /** What native UI clears to: transparent, the background set, or the theme's. @return array{0: float, 1: float, 2: float, 3: float} */
    private function clearColor(): array
    {
        if ($this->transparent) {
            return [0.0, 0.0, 0.0, 0.0];
        }
        if ($this->background !== null) {
            return $this->background;
        }
        $rgb = App::theme()->background;
        return [(($rgb >> 16) & 255) / 255, (($rgb >> 8) & 255) / 255, ($rgb & 255) / 255, 1.0];
    }

    /** The smallest and largest frames the window may take, of its content's limits in points (WM_GETMINMAXINFO). */
    private function limits(CData $mmi): void
    {
        [$fw, $fh] = $this->frameExtra();
        $s = $this->scale();
        if ($this->minSize[0] > 0) { $mmi->ptMinTrackSize->x = (int)round($this->minSize[0] * $s) + $fw; }
        if ($this->minSize[1] > 0) { $mmi->ptMinTrackSize->y = (int)round($this->minSize[1] * $s) + $fh; }
        if ($this->maxSize[0] > 0) { $mmi->ptMaxTrackSize->x = (int)round($this->maxSize[0] * $s) + $fw; }
        if ($this->maxSize[1] > 0) { $mmi->ptMaxTrackSize->y = (int)round($this->maxSize[1] * $s) + $fh; }
    }

    /** Pixels a point: the window's DPI over 96. */
    private function scale(): float
    {
        $dpi = Win32::user32()->GetDpiForWindow($this->hwnd);
        return $dpi > 0 ? $dpi / 96 : 1.0;
    }

    /** The frame's width and height beyond the content's, in pixels, as the window's style makes them at its DPI. @return array{0: int, 1: int} */
    private function frameExtra(): array
    {
        $u = Win32::user32();
        $r = $u->new('RECT');
        $u->AdjustWindowRectExForDpi(FFI::addr($r), $this->style(), 0, $this->exStyle(), $u->GetDpiForWindow($this->hwnd));
        return [$r->right - $r->left, $r->bottom - $r->top];
    }

    private function style(): int
    {
        return Win32::user32()->GetWindowLongPtrW($this->hwnd, self::GWL_STYLE) & 0xFFFFFFFF;
    }

    private function exStyle(): int
    {
        return Win32::user32()->GetWindowLongPtrW($this->hwnd, self::GWL_EXSTYLE) & 0xFFFFFFFF;
    }

    private function setStyleBits(int $index, int $bits, bool $on): void
    {
        $u = Win32::user32();
        $now = $u->GetWindowLongPtrW($this->hwnd, $index);
        $u->SetWindowLongPtrW($this->hwnd, $index, $on ? $now | $bits : $now & ~$bits);
        $u->SetWindowPos($this->hwnd, 0, 0, 0, 0, 0, self::SWP_NOMOVE | self::SWP_NOSIZE | self::SWP_NOZORDER | self::SWP_NOACTIVATE | self::SWP_FRAMECHANGED);
    }

    /** The window sized so that its content is $w by $h points. */
    private function sizeContent(int $w, int $h): void
    {
        [$fw, $fh] = $this->frameExtra();
        $s = $this->scale();
        Win32::user32()->SetWindowPos($this->hwnd, 0, 0, 0, (int)round($w * $s) + $fw, (int)round($h * $s) + $fh, self::SWP_NOMOVE | self::SWP_NOZORDER | self::SWP_NOACTIVATE);
    }

    /** $px pixels in points, of the window's scale. */
    private function pt(int $px): int
    {
        return (int)round($px / $this->scale());
    }

    private function px(int $pt): int
    {
        return (int)round($pt * $this->scale());
    }

    public function handle(): mixed
    {
        return $this->hwnd;
    }

    public function setContent(Platform\Page $page, WindowOptions $o): void
    {
        throw new Unsupported('myphp: web pages are not on Windows yet');
    }

    /** Native UI's surface: a renderer of Direct3D 11 drawing into the window, the text system's atlases its, the first frame asked for. */
    public function createSurface(WindowOptions $o, \Closure $frame, ?\Closure $afterPresent): void
    {
        $this->frame = $frame;
        $this->afterPresent = $afterPresent;
        $this->transparent = $o->transparent;
        $text = App::text();
        $this->d3d = new D3d();
        $this->d3d->attachAtlas($text->atlas, $text->scale());
        $this->d3d->attachImages($text->images);
        [$pw, $ph] = $this->clientPixels();
        $this->d3d->attach($this->hwnd, $pw, $ph);
        $this->requestFrame();
    }

    public function restoreFrame(Rectangle $r, bool $onDisplay): void
    {
        if ($onDisplay) {
            $this->setBounds($r);
            return;
        }
        $this->sizeContent(max(1, $r->width), max(1, $r->height));
        $this->center();
    }

    public function startFullScreen(): void
    {
        $this->setFullScreen(true);
    }

    public function startMaximized(): void
    {
        $this->maximize();
    }

    public function isLegacyFullScreen(): bool
    {
        return false;
    }

    public function hasPopover(): bool
    {
        return false;
    }

    public function placeWebViews(): void {}

    public function placeVideos(): void {}

    public function newWebView(Platform\Page $page, bool $scrollToContainer, bool $transparent): Platform\WebView
    {
        throw new Unsupported('myphp: web views are not on Windows yet');
    }

    public function contentView(): mixed
    {
        return $this->hwnd;
    }

    public function release(): void
    {
        $this->d3d?->release();
        $this->d3d = null;
    }

    public function show(): void
    {
        $u = Win32::user32();
        $u->ShowWindow($this->hwnd, $this->minimized ? self::SW_RESTORE : self::SW_SHOW);
        $u->SetForegroundWindow($this->hwnd);
    }

    public function showInactive(): void
    {
        Win32::user32()->ShowWindow($this->hwnd, self::SW_SHOWNA);
    }

    public function hide(): void
    {
        Win32::user32()->ShowWindow($this->hwnd, self::SW_HIDE);
    }

    /** Closes the window: gone, as the core's close asks after it decided (its shouldClose is not asked again). */
    public function close(): void
    {
        if (!$this->dead) {
            Win32::user32()->DestroyWindow($this->hwnd);
        }
    }

    /** @internal whether the window is gone */
    public function isDead(): bool
    {
        return $this->dead;
    }

    public function setTitle(string $title): void
    {
        Win32::user32()->SetWindowTextW($this->hwnd, Win32::wide($title));
    }

    public function setRepresentedFile(string $path): void {}

    public function setDocumentEdited(bool $edited): void {}

    public function setBackgroundColor(array $rgba): void
    {
        [$r, $g, $b, $a] = $rgba;
        $this->background = [$r / 255, $g / 255, $b / 255, $a / 255];
        $this->requestFrame();
    }

    /** The title bar dark or light as the app's appearance (DWMWA_USE_IMMERSIVE_DARK_MODE), the content painted again. */
    public function themeChanged(): void
    {
        $dark = Win32::user32()->new('int32_t');
        $dark->cdata = App::backend()->theme()->isDark() ? 1 : 0;
        Win32::dwmapi()->DwmSetWindowAttribute($this->hwnd, 20, FFI::addr($dark), 4);
        $this->requestFrame();
    }

    public function hasVibrancy(): bool
    {
        return false;
    }

    public function hasSystemMaterials(): bool
    {
        return false;
    }

    public function safeAreaInsets(): array
    {
        return [0.0, 0.0, 0.0, 0.0];
    }

    public function keyboardHeight(): float
    {
        return 0.0;
    }

    /** Asks for a frame at the queue's next turn (a window of no native UI is painted again); $repaint: one that only paints the last frame's nodes again, while every ask says so. */
    public function requestFrame(int $every = 1, bool $repaint = false): void
    {
        if ($this->dead) {
            return;
        }
        if ($this->d3d === null) {
            Win32::user32()->InvalidateRect($this->hwnd, null, 0);
            return;
        }
        $this->repaintOnly = $this->repaintOnly && $repaint;
        if ($this->rendering) {
            $this->again = true;
            return;
        }
        if ($this->asked) {
            return;
        }
        $this->asked = true;
        $this->b->timer(0, fn() => $this->frameNow());
    }

    public function wake(float $seconds, bool $repaint): void
    {
        App::after($seconds, fn() => $this->requestFrame(1, $repaint));
    }

    public function repaintAsked(): bool
    {
        return $this->repaintNow;
    }

    public function displayRate(): float
    {
        return $this->refreshRate();
    }

    public function setProgressBar(string $state, float $value): void {}

    public function lastDraw(): string
    {
        return $this->d3d === null ? 'GDI, the background alone' : 'Direct3D 11' . (D3d::$warp ? ' (WARP)' : '');
    }

    public function backingScale(): float
    {
        return $this->scale();
    }

    public function isKey(): bool
    {
        return Mem::addr(Win32::user32()->GetForegroundWindow()) === Mem::addr($this->hwnd);
    }

    public function setAcceptsFirstMouse(bool $on): void {}

    public function setDropFormats(array $formats): void {}

    public function command(string $cmd): void {}

    public function passThrough(\Closure $at): void {}

    /** The last frame drawn again off screen from its list: BGRA, premultiplied, rows top down, its width and height. */
    public function lastFrame(): array
    {
        if ($this->d3d === null || $this->drawn[0] === 0) {
            return ['', 0, 0];
        }
        [$pw, $ph, $scale, $clear] = $this->drawn;
        return [$this->d3d->readBack($this->d3d->lastList(), $pw, $ph, $scale, $clear), $pw, $ph];
    }

    public function capture(): string
    {
        throw new \RuntimeException('myphp: capturePage: nothing drawn yet');
    }

    public function underFrame(): ?array
    {
        return null;
    }

    public function pageDrag(): void {}

    public function hasHiddenTitleBar(): bool
    {
        return false;
    }

    public function sizeLimits(): array
    {
        return [$this->minSize, $this->maxSize];
    }

    /** The content's size in points. @return array{0: float, 1: float} */
    public function frameSize(): array
    {
        $u = Win32::user32();
        $r = $u->new('RECT');
        $u->GetClientRect($this->hwnd, FFI::addr($r));
        $s = $this->scale();
        return [($r->right - $r->left) / $s, ($r->bottom - $r->top) / $s];
    }

    public function startDrag(array $o): void
    {
        throw new Unsupported('myphp: dragging out of a window is not on Windows yet');
    }

    public function cancelDrag(): void {}

    public function titleBar(): array
    {
        return ['height' => 0.0, 'left' => 0.0, 'right' => 0.0];
    }

    /** Moves the window with the pointer, as its title bar does; a double click maximizes or restores it. */
    public function dragWindow(int $clicks): void
    {
        $u = Win32::user32();
        if ($clicks === 2) {
            $this->isMaximized() ? $this->unmaximize() : $this->maximize();
            return;
        }
        if (!$this->movable) {
            return;
        }
        $u->ReleaseCapture();
        $u->SendMessageW($this->hwnd, 0x00A1, 2, 0); // WM_NCLBUTTONDOWN on HTCAPTION
    }

    /** The refresh rate of the window's display (EnumDisplaySettings of its device). */
    public function refreshRate(): float
    {
        $u = Win32::user32();
        $info = $u->new('MONITORINFOEXW');
        $info->cbSize = FFI::sizeof($info);
        if (!$u->GetMonitorInfoW($u->MonitorFromWindow($this->hwnd, 2), FFI::addr($info))) {
            return 60.0;
        }
        $mode = $u->new('uint8_t[220]'); // DEVMODEW: dmSize at 68, dmDisplayFrequency at 184
        $mode[68] = 220;
        if (!$u->EnumDisplaySettingsW(FFI::addr($info->szDevice[0]), 0xFFFFFFFF /* ENUM_CURRENT_SETTINGS */, $mode)) {
            return 60.0;
        }
        $hz = $mode[184] | ($mode[185] << 8);
        return $hz > 1 ? (float)$hz : 60.0;
    }

    public function showCursor(Cursor $cursor): void
    {
        if ($cursor === $this->cursorShown) {
            return;
        }
        $this->cursorShown = $cursor;
        $u = Win32::user32();
        $u->SetCursor($cursor === Cursor::None ? null : $u->LoadCursorW(null, self::CURSORS[$cursor->value] ?? 32512));
    }

    public function setVibrancy(string $material): void {}

    public function showing(): bool
    {
        return $this->isVisible() && !$this->isMinimized();
    }

    public function isVisible(): bool
    {
        return !$this->dead && Win32::user32()->IsWindowVisible($this->hwnd) !== 0;
    }

    public function focus(): void
    {
        $u = Win32::user32();
        if ($this->minimized) { $u->ShowWindow($this->hwnd, self::SW_RESTORE); }
        $u->SetForegroundWindow($this->hwnd);
    }

    public function blur(): void {}

    public function isFocused(): bool
    {
        return $this->isKey();
    }

    public function minimize(): void
    {
        Win32::user32()->ShowWindow($this->hwnd, self::SW_MINIMIZE);
    }

    public function isMinimized(): bool
    {
        return Win32::user32()->IsIconic($this->hwnd) !== 0;
    }

    public function maximize(): void
    {
        Win32::user32()->ShowWindow($this->hwnd, self::SW_MAXIMIZE);
    }

    public function unmaximize(): void
    {
        if ($this->isMaximized()) { Win32::user32()->ShowWindow($this->hwnd, self::SW_RESTORE); }
    }

    public function isMaximized(): bool
    {
        return Win32::user32()->IsZoomed($this->hwnd) !== 0;
    }

    public function restore(): void
    {
        Win32::user32()->ShowWindow($this->hwnd, self::SW_RESTORE);
    }

    /** Full screen as Windows's apps take it: a frameless window over its display's whole area, its frame and placement kept for after. */
    public function setFullScreen(bool $on): void
    {
        if ($on === ($this->beforeFullScreen !== null)) {
            return;
        }
        $u = Win32::user32();
        if ($on) {
            $place = $u->new('WINDOWPLACEMENT');
            $place->length = FFI::sizeof($place);
            $u->GetWindowPlacement($this->hwnd, FFI::addr($place));
            $this->beforeFullScreen = [$this->style(), $this->exStyle(), $place];
            $info = $u->new('MONITORINFOEXW');
            $info->cbSize = FFI::sizeof($info);
            $u->GetMonitorInfoW($u->MonitorFromWindow($this->hwnd, 2), FFI::addr($info));
            $u->SetWindowLongPtrW($this->hwnd, self::GWL_STYLE, ($this->style() & ~self::WS_OVERLAPPEDWINDOW) | self::WS_POPUP);
            $m = $info->rcMonitor;
            $u->SetWindowPos($this->hwnd, 0, $m->left, $m->top, $m->right - $m->left, $m->bottom - $m->top, self::SWP_NOZORDER | self::SWP_FRAMECHANGED);
            $this->w->nativeEvent('enterFullScreen');
            return;
        }
        [$style, $ex, $place] = $this->beforeFullScreen;
        $this->beforeFullScreen = null;
        $u->SetWindowLongPtrW($this->hwnd, self::GWL_STYLE, $style);
        $u->SetWindowLongPtrW($this->hwnd, self::GWL_EXSTYLE, $ex);
        $u->SetWindowPlacement($this->hwnd, FFI::addr($place));
        $u->SetWindowPos($this->hwnd, 0, 0, 0, 0, 0, self::SWP_NOMOVE | self::SWP_NOSIZE | self::SWP_NOZORDER | self::SWP_FRAMECHANGED);
        $this->w->nativeEvent('leaveFullScreen');
    }

    public function isFullScreen(): bool
    {
        return $this->beforeFullScreen !== null;
    }

    public function setLegacyFullScreen(bool $on): void
    {
        $this->setFullScreen($on);
    }

    /** In the middle of its display's work area. */
    public function center(): void
    {
        $u = Win32::user32();
        $info = $u->new('MONITORINFOEXW');
        $info->cbSize = FFI::sizeof($info);
        $u->GetMonitorInfoW($u->MonitorFromWindow($this->hwnd, 2), FFI::addr($info));
        $r = $u->new('RECT');
        $u->GetWindowRect($this->hwnd, FFI::addr($r));
        $wa = $info->rcWork;
        $w = $r->right - $r->left; $h = $r->bottom - $r->top;
        $u->SetWindowPos($this->hwnd, 0, $wa->left + intdiv($wa->right - $wa->left - $w, 2), $wa->top + intdiv($wa->bottom - $wa->top - $h, 2), 0, 0, self::SWP_NOSIZE | self::SWP_NOZORDER | self::SWP_NOACTIVATE);
    }

    /** The frame on the screen, in points. */
    public function setBounds(Rectangle $r): void
    {
        Win32::user32()->SetWindowPos($this->hwnd, 0, $this->px($r->x), $this->px($r->y), $this->px(max(1, $r->width)), $this->px(max(1, $r->height)), self::SWP_NOZORDER | self::SWP_NOACTIVATE);
    }

    public function bounds(): Rectangle
    {
        $u = Win32::user32();
        $r = $u->new('RECT');
        $u->GetWindowRect($this->hwnd, FFI::addr($r));
        return new Rectangle($this->pt($r->left), $this->pt($r->top), $this->pt($r->right - $r->left), $this->pt($r->bottom - $r->top));
    }

    /** The content's frame on the screen, in points: the window's around it. */
    public function setContentBounds(Rectangle $r): void
    {
        $u = Win32::user32();
        $rect = $u->new('RECT');
        $rect->left = $this->px($r->x); $rect->top = $this->px($r->y);
        $rect->right = $rect->left + $this->px(max(1, $r->width)); $rect->bottom = $rect->top + $this->px(max(1, $r->height));
        $u->AdjustWindowRectExForDpi(FFI::addr($rect), $this->style(), 0, $this->exStyle(), $u->GetDpiForWindow($this->hwnd));
        $u->SetWindowPos($this->hwnd, 0, $rect->left, $rect->top, $rect->right - $rect->left, $rect->bottom - $rect->top, self::SWP_NOZORDER | self::SWP_NOACTIVATE);
    }

    public function contentBounds(): Rectangle
    {
        $u = Win32::user32();
        $r = $u->new('RECT');
        $u->GetClientRect($this->hwnd, FFI::addr($r));
        $p = $u->new('POINT');
        $u->ClientToScreen($this->hwnd, FFI::addr($p));
        return new Rectangle($this->pt($p->x), $this->pt($p->y), $this->pt($r->right - $r->left), $this->pt($r->bottom - $r->top));
    }

    public function magnify(float $by): void {}

    public function fitVideo(array $o): void {}

    public function setMinimumSize(int $width, int $height): void
    {
        $this->minSize = [$width, $height];
    }

    public function setMaximumSize(int $width, int $height): void
    {
        $this->maxSize = [$width, $height];
    }

    public function setAspectRatio(float $ratio): void
    {
        $this->aspect = $ratio;
    }

    public function setResizable(bool $on): void
    {
        $this->setStyleBits(self::GWL_STYLE, self::WS_THICKFRAME | self::WS_MAXIMIZEBOX, $on);
    }

    public function isResizable(): bool
    {
        return ($this->style() & self::WS_THICKFRAME) !== 0;
    }

    public function setMovable(bool $on): void
    {
        $this->movable = $on;
    }

    public function isMovable(): bool
    {
        return $this->movable;
    }

    public function setFullSizeContentView(bool $on): void {}

    public function setTitleBarHidden(bool $on): void
    {
        $this->setStyleBits(self::GWL_STYLE, self::WS_CAPTION, !$on);
    }

    public function setMinimizable(bool $on): void
    {
        $this->setStyleBits(self::GWL_STYLE, self::WS_MINIMIZEBOX, $on);
    }

    public function isMinimizable(): bool
    {
        return ($this->style() & self::WS_MINIMIZEBOX) !== 0;
    }

    public function setMaximizable(bool $on): void
    {
        $this->setStyleBits(self::GWL_STYLE, self::WS_MAXIMIZEBOX, $on);
    }

    public function isMaximizable(): bool
    {
        return ($this->style() & self::WS_MAXIMIZEBOX) !== 0;
    }

    /** The close button and Alt+F4 on or off: the window menu's Close (SC_CLOSE), which they are. */
    public function setClosable(bool $on): void
    {
        $u = Win32::user32();
        $menu = $u->GetSystemMenu($this->hwnd, 0);
        if ($menu !== null) { $u->EnableMenuItem($menu, 0xF060, $on ? 0 : 1); } // MF_ENABLED, MF_GRAYED
        $this->closable = $on;
    }

    private bool $closable = true;

    public function isClosable(): bool
    {
        return $this->closable;
    }

    public function setAlwaysOnTop(bool $on): void
    {
        $this->topmost = $on;
        Win32::user32()->SetWindowPos($this->hwnd, $on ? -1 : -2, 0, 0, 0, 0, self::SWP_NOMOVE | self::SWP_NOSIZE | self::SWP_NOACTIVATE);
    }

    public function isAlwaysOnTop(): bool
    {
        return $this->topmost;
    }

    /** The whole window's opacity, a layered window's (LWA_ALPHA). */
    public function setOpacity(float $v): void
    {
        if (($this->exStyle() & self::WS_EX_LAYERED) === 0) { $this->setStyleBits(self::GWL_EXSTYLE, self::WS_EX_LAYERED, true); }
        Win32::user32()->SetLayeredWindowAttributes($this->hwnd, 0, (int)round(max(0.0, min(1.0, $v)) * 255), 0x2);
    }

    public function opacity(): float
    {
        if (($this->exStyle() & self::WS_EX_LAYERED) === 0) {
            return 1.0;
        }
        $u = Win32::user32();
        $alpha = $u->new('uint8_t'); $flags = $u->new('uint32_t');
        return $u->GetLayeredWindowAttributes($this->hwnd, null, FFI::addr($alpha), FFI::addr($flags)) && ($flags->cdata & 0x2) !== 0 ? $alpha->cdata / 255 : 1.0;
    }

    public function setHasShadow(bool $on): void
    {
        $this->shadow = $on;
    }

    public function hasShadow(): bool
    {
        return $this->shadow;
    }

    public function setWindowButtonVisibility(bool $visible): void
    {
        $this->setStyleBits(self::GWL_STYLE, self::WS_SYSMENU, $visible);
    }

    /** Clicks through the window to what is below it: a layered, transparent one. */
    public function setIgnoreMouseEvents(bool $on): void
    {
        if ($on && ($this->exStyle() & self::WS_EX_LAYERED) === 0) { $this->setOpacity(1.0); }
        $this->setStyleBits(self::GWL_EXSTYLE, self::WS_EX_TRANSPARENT, $on);
    }

    /** Kept out of screenshots and recordings (WDA_EXCLUDEFROMCAPTURE). */
    public function setContentProtection(bool $on): void
    {
        Win32::user32()->SetWindowDisplayAffinity($this->hwnd, $on ? 0x11 : 0);
    }

    /** Flashes the taskbar button and the title bar till the window comes to the front (FLASHW_ALL | FLASHW_TIMERNOFG), or stops. */
    public function flashFrame(bool $flash): void
    {
        $u = Win32::user32();
        $info = $u->new('FLASHWINFO');
        $info->cbSize = FFI::sizeof($info);
        $info->hwnd = $this->hwnd;
        $info->dwFlags = $flash ? 0x3 | 0xC : 0;
        $u->FlashWindowEx(FFI::addr($info));
    }

    public function setVisibleOnAllWorkspaces(bool $on): void {}

    public function placePopover(): void {}

    public function snapshot(float $x, float $y, int $w, int $h): Image
    {
        throw new Unsupported('myphp: a window\'s snapshot comes with Windows\'s renderer');
    }

    public function framePending(): bool
    {
        return $this->asked || $this->rendering;
    }

    public function automatedKey(bool $down, int $code, string $chars, string $plain, int $mods): void
    {
        throw new Unsupported('myphp: automated keys come with Windows\'s input');
    }

    public function automatedText(string $text): void
    {
        throw new Unsupported('myphp: automated text comes with Windows\'s input');
    }

    public function chooseMenuItem(array $path): void
    {
        throw new Unsupported('myphp: the menu bar comes with Windows\'s menus');
    }
}
