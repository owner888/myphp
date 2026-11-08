<?php
declare(strict_types=1);

namespace MyPHP\Platform\Android;

use FFI;
use MyPHP\{App, L10n, Logger, Rectangle, WindowOptions};
use MyPHP\Native\{Android, Gl};
use MyPHP\Platform;
use MyPHP\UI\{AccessElements, AccessTree, DisplayList, Image, Key, Node, TextInputRange, TextState};

/**
 * Android Window backed by Activity SurfaceView, rendered via EGL & GLES 3.2.
 */
final class Window implements Platform\Window
{
    private static ?self $instance = null;

    private ?\Closure $frame = null;
    private ?\Closure $afterPresent = null;
    private bool $asked = false;
    /** What TalkBack was last given of the frames, which it is given again only as it changes. */
    private ?AccessElements $access = null;
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
        // TalkBack reads the frames' elements, once a screen reader is on (onAccessOn): the activity's virtual views (MyphpAccess)
        $this->access = new AccessElements();
        $this->w->runtime->onAccess = function (AccessTree $t): void {
            if (($json = $this->access?->encode($t, L10n::in(App::locale())->get('showMenu'))) !== null) { Android::ffi()->myphp_access_update($json); }
        };

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
            Logger::verbose('EGLSurface seamlessly restored with existing Context', 'android');
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

        Logger::verbose('EGL initialized successfully on Android Surface', 'android');
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
        Logger::verbose("Surface recreated on size change: {$wPx}x{$hPx}", 'android');
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
        Logger::verbose('EGL surface destroyed (Context preserved)', 'android');
    }

    public function requestFrame(int $every = 1, bool $repaint = false): void
    {
        $this->repaintOnly = $this->asked ? ($this->repaintOnly && $repaint) : $repaint;
        $this->asked = true;
        Android::ffi()->myphp_request_frame();
    }

    public function isFrameRequested(): bool
    {
        return $this->asked;
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
    private bool $touchInField = false;
    /** A finger on a node dragged along one axis moved along it: the drag's, not the scroll's, until it lifts. */
    private bool $touchAxisTaken = false;
    private bool $downFrameDrawn = false;
    private int $longPressId = 0;
    /** The touch opened a context menu: its moves and lift are the menu's (Runtime::$touchMenuFinger). */
    private bool $touchMenuHeld = false;
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
    /** How high the keyboard stands over the window's bottom, in points; 0 while hidden. */
    private float $keyboard = 0.0;

    public function onPause(): void
    {
        $this->paused = true;
        $this->flingActive = false;
        Logger::verbose('Window paused', 'android');
    }

    public function onResume(): void
    {
        $this->paused = false;
        Logger::verbose('Window resumed', 'android');
        $this->requestFrame();
    }

    public function setInsets(int $topPx, int $bottomPx, int $leftPx, int $rightPx, int $keyboardPx): void
    {
        $density = max(1.0, $this->density);
        $this->insets = [
            $topPx / $density,
            $bottomPx / $density,
            $leftPx / $density,
            $rightPx / $density,
        ];
        $this->keyboard = $keyboardPx / $density;
        Logger::verbose("Window insets updated (pt): top={$this->insets[0]}, bottom={$this->insets[1]}, left={$this->insets[2]}, right={$this->insets[3]}, keyboard={$this->keyboard}", 'android');
        $this->requestFrame();
    }

    public function safeAreaInsets(): array
    {
        return [$this->insets[0], $this->insets[3], $this->insets[1], $this->insets[2]];
    }

    public function keyboardHeight(): float
    {
        return $this->keyboard;
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

        if ($this->flingActive && ($inp = $this->w->input) !== null) {
            $nowNs = hrtime(true);
            $dt = max(0.001, min(0.05, ($nowNs - $this->flingLastTime) / 1e9));
            $this->flingLastTime = $nowNs;
            $decay = exp(-$dt / 0.325);
            $this->flingVx *= $decay;
            $this->flingVy *= $decay;
            if (hypot($this->flingVx, $this->flingVy) < 18.0) {
                $this->flingActive = false;
            } else {
                $inp->push(['scroll', $this->touchStartX, $this->touchStartY, $this->flingVx * $dt, $this->flingVy * $dt, true, false]);
                $this->requestFrame();
            }
        }

        $list = $this->gl->nextList();
        $this->gl->setDark(App::theme()->dark);

        try {
            ($this->frame)($list, (float)$widthPts, (float)$heightPts);

            $clear = [0.0, 0.0, 0.0, 1.0];
            $this->gl->draw($list, max(1, $wPx), max(1, $hPx), $density * $list->zoom, $clear);

            $egl = Android::egl();
            $ret = $egl->eglSwapBuffers($this->eglDisplay, $this->eglSurface);
            if (!$ret) {
                Logger::error("eglSwapBuffers failed: " . $egl->eglGetError(), "android");
            } else {
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
        if ($this->w->runtime !== null) {
            $this->w->runtime->touchMode = true;
        }

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
                $this->touchAxisTaken = false;
                $this->touchMenuHeld = false;
                $this->touchHistory = [[$time, $ptX, $ptY]];

                // Check if touch begins inside the focused text field box
                $this->touchInField = false;
                $rt = $this->w->runtime;
                if ($input->focus !== null && $rt !== null && $rt->focus !== null) {
                    $r = $rt->rectOf($rt->focus);
                    if ($r !== null && $ptX >= $r[0] && $ptX < $r[0] + $r[2] && $ptY >= $r[1] && $ptY < $r[1] + $r[3]) {
                        $this->touchInField = true;
                    }
                }

                // Push pointer down immediately so all controls (buttons, checkboxes, switches, sliders, steppers) enter their native pressed/dragging state
                $input->push(['down', $ptX, $ptY, 0, 1, 0, $time]);
                $hitId = $rt?->pressTarget($ptX, $ptY);
                if ($hitId !== null) {
                    $rt->flashTouch($hitId, 2.0);
                }
                $this->requestFrame();

                $pressId = ++$this->longPressId;
                \MyPHP\App::after(0.5, function () use ($pressId, $ptX, $ptY, $time) {
                    if ($this->touchActive && !$this->touchIsScrolling && !$this->touchAxisTaken && $this->longPressId === $pressId) {
                        $input = $this->w->input;
                        if ($input !== null && $this->w->runtime?->hasContextMenuAt($ptX, $ptY)) { // a node with a menu: it opens on the long press, which is no tap
                            $this->w->runtime->cancelTouchPress();
                            $this->w->runtime->haptic('medium');
                            $input->mouse($ptX, $ptY);
                            $input->push(['down', $ptX, $ptY, 1, 1, 0, $time]);
                            $input->push(['up', $ptX, $ptY, 1, 1, 0, $time]);
                            $this->touchMenuHeld = true; // the touch is the menu's now: its moves slide over the items, its lift chooses one, and they scroll, swipe and tap nothing else, as UIKit's context menu cancelling the other gestures
                            $this->w->runtime->touchMenuFinger = [$ptX, $ptY, false];
                            $this->requestFrame();
                        } elseif ($input !== null) {
                            $input->mouse($ptX, $ptY);
                            // Word select on long press
                            $input->push(['down', $ptX, $ptY, 0, 2, 0, $time]);
                            $this->requestFrame();
                        }
                    }
                });
                break;

            case Android::ACTION_MOVE:
                if (!$this->touchActive) {
                    break;
                }
                if ($this->touchMenuHeld) { // the finger that opened a context menu: the menu's (Context::touchMenu)
                    if ($this->w->runtime !== null) { $this->w->runtime->touchMenuFinger = [$ptX, $ptY, false]; }
                    $this->requestFrame();
                    break;
                }
                $rt = $this->w->runtime;
                if (hypot($ptX - $this->touchStartX, $ptY - $this->touchStartY) > 10.0) { // moved off: no long press (UILongPressGestureRecognizer's allowableMovement)
                    ++$this->longPressId;
                }
                if ($this->touchInField || ($rt !== null && !$this->touchIsScrolling && $rt->isDraggingControl())) {
                    // A node dragged along one axis alone (a row swiped sideways, a sheet pulled down) takes the finger
                    // once it moved 8 points along that axis; moved across it first, the container scrolls instead, as
                    // UIScrollView's directional lock; until either, nothing moves (the slop).
                    $axes = $this->touchInField ? 0 : $rt->pressDragAxes();
                    $undecided = ($axes === Node::HORIZONTAL || $axes === Node::VERTICAL) && !$this->touchAxisTaken;
                    if ($undecided) {
                        $along = abs($axes === Node::HORIZONTAL ? $ptX - $this->touchStartX : $ptY - $this->touchStartY);
                        $across = abs($axes === Node::HORIZONTAL ? $ptY - $this->touchStartY : $ptX - $this->touchStartX);
                        if ($along > 8.0) {
                            $this->touchAxisTaken = true; $undecided = false; ++$this->longPressId; // the swipe took the touch: no long press
                        } elseif ($across > 8.0) {
                            $rt->cancelTouchPress(); // the move is the scroll's: below, as any touch moving on a container
                        } else {
                            break;
                        }
                    }
                    if (!$undecided) {
                        // Dragging across a text field, slider, rangeSlider, splitter or scrollbar thumb
                        $input->mouse($ptX, $ptY);
                        $input->push(['drag', $ptX, $ptY, $time]);
                        $this->requestFrame();
                        break;
                    }
                }

                $now = hrtime(true);
                $this->touchHistory[] = [$now, $ptX, $ptY];
                while (count($this->touchHistory) > 1 && ($now - $this->touchHistory[0][0]) > 120_000_000) {
                    array_shift($this->touchHistory);
                }

                $dist = hypot($ptX - $this->touchStartX, $ptY - $this->touchStartY);
                if (!$this->touchIsScrolling && $dist > 4.0) {
                    $this->touchIsScrolling = true;
                    $rt?->cancelTouchPress();
                    $this->touchLastX = $ptX;
                    $this->touchLastY = $ptY;
                }

                if ($this->touchIsScrolling) {
                    $dx = $ptX - $this->touchLastX;
                    $dy = $ptY - $this->touchLastY;
                    $this->touchLastX = $ptX;
                    $this->touchLastY = $ptY;

                    $input->push(['scroll', $this->touchStartX, $this->touchStartY, $dx, $dy, true, false]);
                    $this->requestFrame();
                }
                break;

            case Android::ACTION_UP:
                if (!$this->touchActive) {
                    break;
                }
                if ($this->touchMenuHeld) { // the finger that opened a context menu: the menu's (Context::touchMenu)
                    if ($this->w->runtime !== null) { $this->w->runtime->touchMenuFinger = [$ptX, $ptY, true]; }
                    $this->touchMenuHeld = false; $this->touchActive = false;
                    $this->requestFrame();
                    break;
                }
                $this->touchActive = false;
                $rt = $this->w->runtime;
                if ($rt !== null) {
                    $rt->touchFlash = [];
                }

                if ($this->touchInField) {
                    $input->mouse($ptX, $ptY);
                    $input->push(['up', $ptX, $ptY, 0, 1, 0, $time]);
                    $this->touchInField = false;
                    $this->requestFrame();
                    break;
                }

                if ($this->touchIsScrolling) {
                    $this->touchIsScrolling = false;
                    $rt?->cancelTouchPress();
                    $input->push(['scrollEnd', $ptX, $ptY]); // what the finger pulled past a top is let go (Context::refreshable)
                    if (count($this->touchHistory) >= 2) {
                        $first = $this->touchHistory[0];
                        $last = $this->touchHistory[count($this->touchHistory) - 1];
                        $dt = ($last[0] - $first[0]) / 1e9;
                        if ($dt > 0.008 && $dt < 0.25) {
                            $vx = ($last[1] - $first[1]) / $dt;
                            $vy = ($last[2] - $first[2]) / $dt;
                            $speed = hypot($vx, $vy);
                            if ($speed > 50.0) {
                                $this->flingActive = true;
                                $this->flingVx = max(-3500.0, min(3500.0, $vx));
                                $this->flingVy = max(-3500.0, min(3500.0, $vy));
                                $this->flingLastTime = $time;
                            }
                        }
                    }
                    $this->requestFrame();
                } else {
                    // Release the press at the touch point and clear hover so all controls bounce back to their un-hovered base state
                    $input->push(['up', $this->touchStartX, $this->touchStartY, 0, 1, 0, $time]);
                    $input->mouseInside = false;
                    $input->mouseX = -1;
                    $input->mouseY = -1;
                    $this->requestFrame();
                }
                break;

            case Android::ACTION_CANCEL:
                $this->touchMenuHeld = false;
                $this->touchActive = false;
                $this->touchIsScrolling = false;
                $this->w->runtime?->cancelTouchPress();
                $this->requestFrame();
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
            $input->push(['key', '', Key::BACKSPACE, 0, false]); // a key event: its text, its code, the modifiers, a repeat
        }
        $this->requestFrame();
    }

    public function onKeyEvent(int $keyCode, int $action, int $unicodeChar): void
    {
        $input = $this->w->input;
        if ($input === null) {
            return;
        }
        if ($unicodeChar === 10) { // Enter / newline
            if ($input->focus !== null) {
                if ($input->focus->multiline) {
                    $input->focus->insert("\n");
                } else {
                    $input->push(['key', "\n", Key::ENTER, 0, false]);
                }
            } else {
                $input->push(['key', "\n", Key::ENTER, 0, false]);
            }
            $this->requestFrame();
        }
    }

    /** A screen reader is on: the frames describe themselves for it from now on. */
    public function onAccessOn(): void
    {
        $this->w->input?->push(['accessOn']);
        $this->requestFrame();
    }

    /** An action of TalkBack on the element of node $node: an AccessNode action, $index a custom action's. */
    public function onAccessAction(int $node, int $action, int $index): void
    {
        $this->w->input?->push(['access', $node, $action, '', $index, 0]);
        $this->requestFrame();
    }

    /** The system's Back (the button, the gesture): the window's chain, Window::back. */
    public function onBack(): void
    {
        $this->w->back();
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
    public function displayRate(): float { return 120.0; }
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
    public function refreshRate(): float { return 120.0; }
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
