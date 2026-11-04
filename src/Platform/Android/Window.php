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
        if ($this->eglSurface !== null) {
            return true;
        }

        $f = Android::ffi();
        $nativeWin = $f->myphp_get_native_window();
        if ($nativeWin === null) {
            return false;
        }

        $egl = Android::egl();

        // Reuse existing context on resume
        if ($this->eglContext !== null && $this->eglDisplay !== null && $this->eglConfig !== null) {
            $surface = $egl->eglCreateWindowSurface($this->eglDisplay, $this->eglConfig, $nativeWin, null);
            if ($surface === null || \FFI::isNull($surface)) {
                Logger::error('Failed eglCreateWindowSurface on resume', 'android');
                return false;
            }
            if (!$egl->eglMakeCurrent($this->eglDisplay, $surface, $surface, $this->eglContext)) {
                Logger::error('Failed eglMakeCurrent on resume', 'android');
                return false;
            }
            $this->eglSurface = $surface;
            $this->surfaceWidth = $f->myphp_get_surface_width();
            $this->surfaceHeight = $f->myphp_get_surface_height();
            $this->initialized = true;
            Logger::debug('EGLSurface seamlessly restored with existing Context', 'android');
            return true;
        }

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
        $this->surfaceWidth = $f->myphp_get_surface_width();
        $this->surfaceHeight = $f->myphp_get_surface_height();
        $this->gl = new Gl();

        $text = App::text();
        $this->gl->attachAtlas($text->atlas, $text->scale());
        $this->gl->attachImages($text->images);

        $this->initialized = true;

        Logger::debug('EGL initialized successfully on Android Surface', 'android');
        return true;
    }

    public function onSurfaceChanged(): void
    {
        if ($this->eglDisplay === null || $this->eglContext === null) {
            $this->initEgl();
            $this->requestFrame();
            return;
        }

        $f = Android::ffi();
        $nativeWin = $f->myphp_get_native_window();
        if ($nativeWin === null) {
            return;
        }

        $wPx = $f->myphp_get_surface_width();
        $hPx = $f->myphp_get_surface_height();
        if ($wPx === $this->surfaceWidth && $hPx === $this->surfaceHeight && $this->eglSurface !== null) {
            $this->requestFrame();
            return;
        }

        $egl = Android::egl();
        if ($this->eglSurface !== null) {
            $egl->eglMakeCurrent($this->eglDisplay, null, null, null);
            $egl->eglDestroySurface($this->eglDisplay, $this->eglSurface);
            $this->eglSurface = null;
        }

        $surface = $egl->eglCreateWindowSurface($this->eglDisplay, $this->eglConfig, $nativeWin, null);
        if ($surface === null || \FFI::isNull($surface)) {
            Logger::error('Failed to recreate eglCreateWindowSurface in onSurfaceChanged', 'android');
            return;
        }

        if (!$egl->eglMakeCurrent($this->eglDisplay, $surface, $surface, $this->eglContext)) {
            Logger::error('Failed eglMakeCurrent in onSurfaceChanged', 'android');
            return;
        }

        $this->eglSurface = $surface;
        $this->surfaceWidth = $wPx;
        $this->surfaceHeight = $hPx;
        Logger::debug("Surface recreated on size change: {$wPx}x{$hPx}", 'android');
        $this->requestFrame();
    }

    public function destroySurface(): void
    {
        if ($this->eglDisplay !== null && $this->eglSurface !== null) {
            $egl = Android::egl();
            $egl->eglMakeCurrent($this->eglDisplay, null, null, null);
            $egl->eglDestroySurface($this->eglDisplay, $this->eglSurface);
            $this->eglSurface = null;
        }
        Logger::debug('EGL surface destroyed (Context preserved)', 'android');
    }

    public function requestFrame(int $every = 1, bool $repaint = false): void
    {
        $this->asked = true;
        $this->repaintOnly = $repaint;
        Android::ffi()->myphp_request_frame();
    }

    // Touch gesture state
    private bool $touchActive = false;
    private float $touchStartX = 0.0;
    private float $touchStartY = 0.0;
    private float $touchLastX = 0.0;
    private float $touchLastY = 0.0;
    private int $touchStartTime = 0;
    private int $touchLastTime = 0;
    private bool $touchIsScrolling = false;
    private bool $touchLongPressed = false;
    private bool $touchInField = false;
    private bool $downFrameDrawn = false;
    private int $longPressId = 0;
    private int $releaseSeq = 0;
    /** @var list<array{0: int, 1: float, 2: float}> [timeNs, x, y] */
    private array $touchHistory = [];

    // Fling inertial scrolling state
    private bool $flingActive = false;
    private float $flingVx = 0.0;
    private float $flingVy = 0.0;
    private int $flingLastTime = 0;

    // Keyboard state
    private bool $keyboardVisible = false;

    // App lifecycle & insets state
    private bool $paused = false;
    /** @var array{0: float, 1: float, 2: float, 3: float} top, bottom, left, right in points */
    private array $insets = [0.0, 0.0, 0.0, 0.0];

    public function onPause(): void
    {
        $this->paused = true;
        $this->flingActive = false;
        Logger::debug('Window paused', 'android');
    }

    public function onResume(): void
    {
        $this->paused = false;
        Logger::debug('Window resumed', 'android');
        $this->requestFrame();
    }

    public function setInsets(int $topPx, int $bottomPx, int $leftPx, int $rightPx): void
    {
        $density = max(1.0, $this->density);
        $this->insets = [
            $topPx / $density,
            $bottomPx / $density,
            $leftPx / $density,
            $rightPx / $density,
        ];
        Logger::debug("Window insets updated (pt): top={$this->insets[0]}, bottom={$this->insets[1]}, left={$this->insets[2]}, right={$this->insets[3]}", 'android');
        $this->requestFrame();
    }

    public function onFrame(): void
    {
        $this->drawFrame();
    }

    public function drawFrame(): void
    {
        if ($this->paused || $this->frame === null) {
            return;
        }
        $this->asked = false;
        $repaint = $this->repaintOnly;
        $this->repaintOnly = false;

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

        try {
            ($this->frame)($list, (float)$widthPts, (float)$heightPts);

            Logger::debug("drawFrame: list->n=" . $list->n, "android");

            $clear = [0.0, 0.0, 0.0, 1.0];
            $this->gl->draw($list, max(1, $wPx), max(1, $hPx), $density * $list->zoom, $clear);

            $egl = Android::egl();
            $ret = $egl->eglSwapBuffers($this->eglDisplay, $this->eglSurface);
            if (!$ret) {
                Logger::error("eglSwapBuffers failed: " . $egl->eglGetError(), "android");
            } else {
                Logger::debug("eglSwapBuffers OK", "android");
                if ($this->touchActive && ($this->w->input?->mouseDown ?? false)) {
                    $this->downFrameDrawn = true;
                }
            }

            $this->afterPresent?->__invoke();
            $this->w->runtime?->framePresented();
        } catch (\Throwable $e) {
            Logger::error("drawFrame exception: " . $e->getMessage() . "\n" . $e->getTraceAsString(), "android");
            throw $e;
        }

        // Check if soft keyboard should be shown or hidden based on text focus
        $hasInputFocus = ($this->w->input?->focus !== null || $this->w->input?->client !== null);
        if ($hasInputFocus && !$this->keyboardVisible) {
            $this->keyboardVisible = true;
            Android::ffi()->myphp_show_keyboard();
        } elseif (!$hasInputFocus && $this->keyboardVisible) {
            $this->keyboardVisible = false;
            Android::ffi()->myphp_hide_keyboard();
        }
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

        Logger::debug("onTouch: action={$action}, pt=({$ptX}, {$ptY})", 'touch');

        switch ($action) {
            case Android::ACTION_DOWN:
                $this->flingActive = false;
                $this->touchActive = true;
                $this->downFrameDrawn = false;
                $this->releaseSeq++;
                $this->touchStartX = $ptX;
                $this->touchStartY = $ptY;
                $this->touchLastX = $ptX;
                $this->touchLastY = $ptY;
                $this->touchStartTime = $time;
                $this->touchLastTime = $time;
                $this->touchIsScrolling = false;
                $this->touchLongPressed = false;
                $this->touchHistory = [[$time, $ptX, $ptY]];

                // Check if touch begins inside the focused text field box
                $this->touchInField = false;
                $rt = $this->w->runtime;
                if ($input->focus !== null && $rt !== null && $rt->focus !== null) {
                    $r = $rt->rectOf($rt->focus);
                    if ($r !== null && $ptX >= $r[0] && $ptX < $r[0] + $r[2] && $ptY >= $r[1] && $ptY < $r[1] + $r[3]) {
                        $this->touchInField = true;
                        $input->push(['down', $ptX, $ptY, 0, 1, 0, $time]);
                    }
                }

                // Visual pressed highlight & native haptic feedback when touching an interactive control
                $hitId = $rt?->pressTarget($ptX, $ptY);
                if ($hitId !== null) {
                    $input->mouseDown = true;
                    $rt->flashTouch($hitId, 0.30);
                    Android::ffi()->myphp_perform_haptic();
                    $this->drawFrame();
                }

                $pressId = ++$this->longPressId;
                \MyPHP\App::after(0.5, function () use ($pressId, $ptX, $ptY, $time) {
                    if ($this->touchActive && !$this->touchIsScrolling && $this->longPressId === $pressId) {
                        $this->touchLongPressed = true;
                        $input = $this->w->input;
                        if ($input !== null) {
                            $input->mouse($ptX, $ptY);
                            // Word select on long press
                            $input->push(['down', $ptX, $ptY, 0, 2, 0, $time]);
                            $this->requestFrame();
                        }
                        Logger::debug("longPress triggered at ($ptX, $ptY)", "touch");
                    }
                });
                break;

            case Android::ACTION_MOVE:
                if (!$this->touchActive) {
                    break;
                }
                if ($this->touchInField) {
                    // Dragging across text field to move cursor
                    $input->mouse($ptX, $ptY);
                    $input->push(['drag', $ptX, $ptY, $time]);
                    Logger::debug("onTouch MOVE inField: pt=($ptX, $ptY)", "touch");
                    $this->requestFrame();
                    break;
                }

                $now = hrtime(true);
                $this->touchHistory[] = [$now, $ptX, $ptY];
                while (count($this->touchHistory) > 1 && ($now - $this->touchHistory[0][0]) > 120_000_000) {
                    array_shift($this->touchHistory);
                }

                $dist = hypot($ptX - $this->touchStartX, $ptY - $this->touchStartY);
                if (!$this->touchIsScrolling && $dist > 8.0) {
                    $this->touchIsScrolling = true;
                    $input->mouseDown = false;
                    if ($this->w->runtime !== null) {
                        $this->w->runtime->touchFlash = [];
                    }
                    $this->touchLastX = $ptX;
                    $this->touchLastY = $ptY;
                }

                if ($this->touchIsScrolling) {
                    $dx = $ptX - $this->touchLastX;
                    $dy = $ptY - $this->touchLastY;
                    $this->touchLastX = $ptX;
                    $this->touchLastY = $ptY;

                    $input->push(['scroll', $ptX, $ptY, $dx, $dy, true, false]);
                    Logger::debug("onTouch MOVE scroll: pt=($ptX, $ptY), delta=($dx, $dy)", "touch");
                    $this->requestFrame();
                }
                break;

            case Android::ACTION_UP:
                if (!$this->touchActive) {
                    break;
                }
                $this->touchActive = false;

                if ($this->touchInField) {
                    $input->mouse($ptX, $ptY);
                    $input->push(['up', $ptX, $ptY, 0, 1, 0, $time]);
                    Logger::debug("onTouch UP inField: pt=($ptX, $ptY)", "touch");
                    $this->touchInField = false;
                    $this->requestFrame();
                    break;
                }

                if ($this->touchIsScrolling) {
                    $this->touchIsScrolling = false;
                    $input->mouseDown = false;
                    $input->mouseInside = false;
                    if (count($this->touchHistory) >= 2) {
                        $first = $this->touchHistory[0];
                        $last = $this->touchHistory[count($this->touchHistory) - 1];
                        $dt = ($last[0] - $first[0]) / 1e9;
                        if ($dt > 0.01 && $dt < 0.2) {
                            $vx = ($last[1] - $first[1]) / $dt;
                            $vy = ($last[2] - $first[2]) / $dt;
                            $speed = hypot($vx, $vy);
                            if ($speed > 60.0) {
                                // Hand over fling momentum to MyPHP internal MotionChannel smoother (precise=false)
                                $flingDistanceX = max(-400.0, min(400.0, $vx * 0.22));
                                $flingDistanceY = max(-400.0, min(400.0, $vy * 0.22));
                                $input->push(['scroll', $this->touchLastX, $this->touchLastY, $flingDistanceX, $flingDistanceY, false, true]);
                                $this->requestFrame();
                            }
                        }
                    }
                } else {
                    $rt = $this->w->runtime;
                    $hitId = $rt?->pressTarget($this->touchStartX, $this->touchStartY);
                    // Ensure the pressed visual state is presented and visible for at least 90ms before bouncing back
                    if ($hitId !== null && !$this->downFrameDrawn) {
                        $rt->flashTouch($hitId, 1.0);
                        $this->drawFrame();
                    }
                    $elapsedNs = hrtime(true) - $this->touchStartTime;
                    if ($hitId !== null && $elapsedNs < 90_000_000) {
                        usleep((int)intdiv(90_000_000 - $elapsedNs, 1000));
                    }
                    if ($rt !== null) {
                        $rt->touchFlash = [];
                    }
                    // Tap: short click at the exact touch start location, then finger leaves screen (mouseInside = false)
                    $input->push(['down', $this->touchStartX, $this->touchStartY, 0, 1, 0, $this->touchStartTime]);
                    $input->push(['up', $this->touchStartX, $this->touchStartY, 0, 1, 0, $time]);
                    $input->mouseDown = false;
                    $input->mouseInside = false;
                    $input->mouseX = -1;
                    $input->mouseY = -1;
                    $this->drawFrame();
                }
                break;

            case Android::ACTION_CANCEL:
                $this->touchActive = false;
                $this->touchIsScrolling = false;
                if ($this->w->runtime !== null) {
                    $this->w->runtime->touchFlash = [];
                }
                $input->mouseDown = false;
                $input->mouseInside = false;
                $this->drawFrame();
                break;
        }
    }

    public function onImeCommit(string $text): void
    {
        $input = $this->w->input;
        if ($input === null || $text === '') {
            return;
        }
        if ($input->client !== null) {
            $input->client->replaceText(null, $text);
        } elseif ($input->focus !== null) {
            $input->focus->insert($text);
        } else {
            $input->push(['insert', $text]);
        }
        $this->requestFrame();
    }

    public function onImeCompose(string $text): void
    {
        $input = $this->w->input;
        if ($input === null) {
            return;
        }
        if ($input->client !== null) {
            $u = TextState::utf16Index($text, strlen($text));
            $input->client->setMarkedText(null, $text, new TextInputRange($u, $u));
        } elseif ($input->focus !== null) {
            if ($text === '') {
                $input->focus->setMarked('', 0);
            } else {
                $input->focus->setMarked($text, strlen($text));
            }
        }
        $this->requestFrame();
    }

    public function onImeDelete(): void
    {
        $input = $this->w->input;
        if ($input === null) {
            return;
        }
        if ($input->focus !== null) {
            $input->focus->deleteBack();
        } elseif ($input->client !== null) {
            $input->push(['key', Key::BACKSPACE, '', 0, hrtime(true)]);
        }
        $this->requestFrame();
    }

    public function onKeyEvent(int $keyCode, int $action, int $unicodeChar): void
    {
        $input = $this->w->input;
        if ($input === null) {
            return;
        }
        $time = hrtime(true);
        if ($unicodeChar === 10) { // Enter / newline
            if ($input->focus !== null) {
                if ($input->focus->multiline) {
                    $input->focus->insert("\n");
                } else {
                    $input->push(['key', Key::ENTER, "\n", 0, $time]);
                }
            } else {
                $input->push(['key', Key::ENTER, "\n", 0, $time]);
            }
            $this->requestFrame();
        }
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
        if (!$page instanceof Page) {
            throw new \InvalidArgumentException('Expected Android Page');
        }
        return new WebView($this, $page, $scrollToContainer, $transparent);
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
    private int $wakeId = 0;

    public function wake(float $seconds, bool $repaint): void
    {
        $id = ++$this->wakeId;
        \MyPHP\App::after($seconds, function () use ($id, $repaint): void {
            if ($this->wakeId === $id) {
                $this->requestFrame(repaint: $repaint);
            }
        });
    }
    public function repaintAsked(): bool { return $this->repaintOnly; }
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
