<?php
declare(strict_types=1);

namespace MyPHP\Platform\Android;

use FFI;
use MyPHP\{App, Logger, Rectangle, WindowOptions};
use MyPHP\Native\{Android, Gl};
use MyPHP\Platform;
use MyPHP\UI\{DisplayList, Image, Key};

/**
 * Android Window backed by Activity SurfaceView, rendered via EGL & GLES 3.2.
 */
final class Window implements Platform\Window
{
    private static ?self $instance = null;

    private ?\Closure $frame = null;
    private ?\Closure $afterPresent = null;
    private bool $asked = false;
    private bool $repaintOnly = false;
    private bool $initialized = false;

    private int $surfaceWidth = 0;
    private int $surfaceHeight = 0;
    private float $density = 1.0;

    // EGL resources
    private mixed $eglDisplay = null;
    private mixed $eglConfig = null;
    private mixed $eglContext = null;
    private mixed $eglSurface = null;
    private ?Gl $gl = null;

    public function __construct(
        private readonly Backend $b,
        public readonly \MyPHP\Window $w,
        WindowOptions $o,
        bool $panel
    ) {
        if (self::$instance !== null) {
            throw new Platform\Unsupported('Android platform only supports a single full-screen window in this version');
        }
        self::$instance = $this;

        $f = Android::ffi();
        $this->density = max(1.0, $f->myphp_get_density());
        $this->surfaceWidth = $f->myphp_get_surface_width();
        $this->surfaceHeight = $f->myphp_get_surface_height();
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }

    public function handle(): mixed
    {
        return $this;
    }

    public function setContent(Platform\Page $page, WindowOptions $o): void
    {
        throw new Platform\Unsupported('WebView as main window content not supported yet on Android');
    }

    public function createSurface(WindowOptions $o, \Closure $frame, ?\Closure $afterPresent): void
    {
        $this->frame = $frame;
        $this->afterPresent = $afterPresent;

        $this->initEgl();
        $this->requestFrame();
    }

    public function initEgl(): bool
    {
        if ($this->initialized && $this->eglSurface !== null) {
            return true;
        }

        $f = Android::ffi();
        $nativeWin = $f->myphp_get_native_window();
        if ($nativeWin === null) {
            return false;
        }

        $egl = Android::egl();
        $dpy = $egl->eglGetDisplay(null);
        if ($dpy === null || \FFI::isNull($dpy)) {
            Logger::error('Failed eglGetDisplay', 'android');
            return false;
        }

        $major = $egl->new('int[1]');
        $minor = $egl->new('int[1]');
        if (!$egl->eglInitialize($dpy, $major, $minor)) {
            Logger::error('Failed eglInitialize', 'android');
            return false;
        }

        $attribs = $egl->new('int[13]');
        $attribs[0] = Android::EGL_RENDERABLE_TYPE;
        $attribs[1] = Android::EGL_OPENGL_ES3_BIT;
        $attribs[2] = Android::EGL_SURFACE_TYPE;
        $attribs[3] = Android::EGL_WINDOW_BIT;
        $attribs[4] = Android::EGL_RED_SIZE;
        $attribs[5] = 8;
        $attribs[6] = Android::EGL_GREEN_SIZE;
        $attribs[7] = 8;
        $attribs[8] = Android::EGL_BLUE_SIZE;
        $attribs[9] = 8;
        $attribs[10] = Android::EGL_ALPHA_SIZE;
        $attribs[11] = 8;
        $attribs[12] = Android::EGL_NONE;

        $configs = $egl->new('EGLConfig[1]');
        $numConfigs = $egl->new('int[1]');
        if (!$egl->eglChooseConfig($dpy, $attribs, $configs, 1, $numConfigs) || $numConfigs[0] < 1) {
            Logger::error('Failed eglChooseConfig', 'android');
            return false;
        }
        $config = $configs[0];

        $ctxAttribs = $egl->new('int[3]');
        $ctxAttribs[0] = Android::EGL_CONTEXT_CLIENT_VERSION;
        $ctxAttribs[1] = 3;
        $ctxAttribs[2] = Android::EGL_NONE;

        $ctx = $egl->eglCreateContext($dpy, $config, null, $ctxAttribs);
        if ($ctx === null || \FFI::isNull($ctx)) {
            Logger::error('Failed eglCreateContext', 'android');
            return false;
        }

        $surface = $egl->eglCreateWindowSurface($dpy, $config, $nativeWin, null);
        if ($surface === null || \FFI::isNull($surface)) {
            Logger::error('Failed eglCreateWindowSurface', 'android');
            return false;
        }

        if (!$egl->eglMakeCurrent($dpy, $surface, $surface, $ctx)) {
            Logger::error('Failed eglMakeCurrent', 'android');
            return false;
        }

        $this->eglDisplay = $dpy;
        $this->eglConfig = $config;
        $this->eglContext = $ctx;
        $this->eglSurface = $surface;
        $this->gl = new Gl();

        $text = App::text();
        $this->gl->attachAtlas($text->atlas, $text->scale());
        $this->gl->attachImages($text->images);

        $this->initialized = true;

        Logger::debug('EGL initialized successfully on Android Surface', 'android');
        return true;
    }

    public function destroySurface(): void
    {
        if ($this->eglDisplay !== null && $this->eglSurface !== null) {
            $egl = Android::egl();
            $egl->eglMakeCurrent($this->eglDisplay, null, null, null);
            $egl->eglDestroySurface($this->eglDisplay, $this->eglSurface);
            $this->eglSurface = null;
        }
        $this->initialized = false;
        Logger::debug('EGL surface destroyed', 'android');
    }

    public function requestFrame(int $every = 1, bool $repaint = false): void
    {
        $this->asked = true;
        $this->repaintOnly = $repaint;
        Android::ffi()->myphp_request_frame();
    }

    public function drawFrame(): void
    {
        if (!$this->asked || $this->frame === null) {
            return;
        }
        $this->asked = false;

        if (!$this->initEgl()) {
            return;
        }

        $f = Android::ffi();
        $wPx = $f->myphp_get_surface_width();
        $hPx = $f->myphp_get_surface_height();
        if ($wPx <= 0 || $hPx <= 0) {
            return;
        }

        $density = max(1.0, $f->myphp_get_density());
        $widthPts = (int)round($wPx / $density);
        $heightPts = (int)round($hPx / $density);

        Logger::debug("drawFrame: {$wPx}x{$hPx} ({$widthPts}x{$heightPts} pt)", 'android');

        $list = $this->gl->nextList();
        $this->gl->setDark(App::theme()->dark);

        ($this->frame)($list, (float)$widthPts, (float)$heightPts);

        $clear = [0.0, 0.0, 0.0, 1.0];
        $this->gl->draw($list, max(1, $wPx), max(1, $hPx), $density * $list->zoom, $clear);

        $egl = Android::egl();
        $egl->eglSwapBuffers($this->eglDisplay, $this->eglSurface);

        $this->afterPresent?->__invoke();
        $this->w->runtime?->framePresented();
    }

    public function onTouch(int $action, float $x, float $y, int $timeMs): void
    {
        $input = $this->w->input;
        if ($input === null) {
            return;
        }

        $density = max(1.0, Android::ffi()->myphp_get_density());
        $ptX = $x / $density;
        $ptY = $y / $density;
        $time = hrtime(true);

        $input->mouse($ptX, $ptY);
        $input->mods = 0;

        Logger::debug("onTouch: action={$action}, raw=({$x}, {$y}), pt=({$ptX}, {$ptY})", 'touch');

        switch ($action) {
            case Android::ACTION_DOWN:
                $input->push(['down', $ptX, $ptY, 0, 1, 0, $time]);
                break;
            case Android::ACTION_MOVE:
                $input->push(['drag', $ptX, $ptY, $time]);
                break;
            case Android::ACTION_UP:
                $input->push(['up', $ptX, $ptY, 0, 1, 0, $time]);
                break;
        }

        $this->requestFrame();
    }

    public function restoreFrame(Rectangle $r, bool $onDisplay): void {}
    public function startFullScreen(): void {}
    public function startMaximized(): void {}
    public function isLegacyFullScreen(): bool { return false; }
    public function hasPopover(): bool { return false; }
    public function placeWebViews(): void {}
    public function placeVideos(): void {}
    public function newWebView(Platform\Page $page, bool $scrollToContainer, bool $transparent): Platform\WebView
    {
        throw new Platform\Unsupported('WebView not implemented on Android yet');
    }
    public function contentView(): mixed { return null; }
    public function release(): void {}
    public function show(): void {}
    public function showInactive(): void {}
    public function hide(): void {}
    public function close(): void {}
    public function setTitle(string $title): void {}
    public function setRepresentedFile(string $path): void {}
    public function setDocumentEdited(bool $edited): void {}
    public function setBackgroundColor(array $rgba): void {}
    public function themeChanged(): void {}
    public function hasVibrancy(): bool { return false; }
    public function hasSystemMaterials(): bool { return false; }
    public function wake(float $seconds, bool $repaint): void
    {
        $this->requestFrame(repaint: $repaint);
    }
    public function repaintAsked(): bool { return $this->asked; }
    public function displayRate(): float { return 60.0; }
    public function setProgressBar(string $state, float $value): void {}
    public function lastDraw(): string { return ''; }
    public function backingScale(): float { return $this->density; }
    public function isKey(): bool { return true; }
    public function setAcceptsFirstMouse(bool $on): void {}
    public function setDropFormats(array $formats): void {}
    public function command(string $cmd): void {}
    public function passThrough(\Closure $at): void {}
    public function lastFrame(): array { return []; }
    public function capture(): string { return ''; }
    public function underFrame(): ?array { return null; }
    public function pageDrag(): void {}
    public function hasHiddenTitleBar(): bool { return true; }
    public function sizeLimits(): array { return [0, 0, 0, 0]; }
    public function frameSize(): array { return [$this->surfaceWidth, $this->surfaceHeight]; }
    public function startDrag(array $o): void {}
    public function cancelDrag(): void {}
    public function titleBar(): array { return [0, 0, 0, 0]; }
    public function dragWindow(int $clicks): void {}
    public function refreshRate(): float { return 60.0; }
    public function showCursor(\MyPHP\UI\Cursor $cursor): void {}
    public function setVibrancy(string $material): void {}
    public function showing(): bool { return true; }
    public function isVisible(): bool { return true; }
    public function focus(): void {}
    public function blur(): void {}
    public function isFocused(): bool { return true; }
    public function minimize(): void {}
    public function isMinimized(): bool { return false; }
    public function maximize(): void {}
    public function unmaximize(): void {}
    public function isMaximized(): bool { return true; }
    public function restore(): void {}
    public function setFullScreen(bool $on): void {}
    public function isFullScreen(): bool { return true; }
    public function setLegacyFullScreen(bool $on): void {}
    public function center(): void {}
    public function setBounds(Rectangle $r): void {}
    public function bounds(): Rectangle
    {
        return new Rectangle(0.0, 0.0, (float)$this->surfaceWidth, (float)$this->surfaceHeight);
    }
    public function setContentBounds(Rectangle $r): void {}
    public function contentBounds(): Rectangle
    {
        return $this->bounds();
    }
    public function magnify(float $by): void {}
    public function fitVideo(array $o): void {}
    public function setMinimumSize(int $width, int $height): void {}
    public function setMaximumSize(int $width, int $height): void {}
    public function setAspectRatio(float $ratio): void {}
    public function setResizable(bool $on): void {}
    public function isResizable(): bool { return false; }
    public function setMovable(bool $on): void {}
    public function isMovable(): bool { return false; }
    public function setFullSizeContentView(bool $on): void {}
    public function setTitleBarHidden(bool $on): void {}
    public function setMinimizable(bool $on): void {}
    public function isMinimizable(): bool { return false; }
    public function setMaximizable(bool $on): void {}
    public function isMaximizable(): bool { return false; }
    public function setClosable(bool $on): void {}
    public function isClosable(): bool { return false; }
    public function setAlwaysOnTop(bool $on): void {}
    public function isAlwaysOnTop(): bool { return false; }
    public function setOpacity(float $v): void {}
    public function opacity(): float { return 1.0; }
    public function setHasShadow(bool $on): void {}
    public function hasShadow(): bool { return false; }
    public function setWindowButtonVisibility(bool $visible): void {}
    public function setIgnoreMouseEvents(bool $on): void {}
    public function setContentProtection(bool $on): void {}
    public function flashFrame(bool $flash): void {}
    public function setVisibleOnAllWorkspaces(bool $on): void {}
    public function placePopover(): void {}
    public function snapshot(float $x, float $y, int $w, int $h): Image
    {
        throw new Platform\Unsupported('snapshot not implemented on Android yet');
    }
    public function framePending(): bool { return $this->asked; }
    public function automatedKey(bool $down, int $code, string $chars, string $plain, int $mods): void {}
    public function automatedText(string $text): void {}
    public function chooseMenuItem(array $path): void {}
}
