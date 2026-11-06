<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use Closure;
use MyPHP\Native\{iOS, Metal, ObjC};
use MyPHP\Platform;
use MyPHP\{App, Logger, Rectangle, WindowOptions};
use MyPHP\UI\{DisplayList, Image, TextInputRange, TextState};

final class Window implements Platform\Window
{
    private static ?self $instance = null;
    private ?Closure $frame = null;
    private ?Closure $afterPresent = null;
    private bool $asked = false;
    private bool $repaintOnly = false;
    private float $scale = 3.0;
    private float $width = 393.0;
    private float $height = 852.0;

    // Touch gesture tracking
    private bool $touchActive = false;
    private float $touchStartX = 0.0;
    private float $touchStartY = 0.0;
    private float $touchLastX = 0.0;
    private float $touchLastY = 0.0;
    private int $touchStartTime = 0;
    private bool $touchIsScrolling = false;
    private bool $touchInField = false;
    private int $longPressId = 0;
    /** @var list<array{0: int, 1: float, 2: float}> */
    private array $touchHistory = [];
    private bool $keyboardVisible = false;
    private $view = null;
    private ?Metal $metal = null;
    private int $wakeId = 0;

    public function __construct(
        private readonly Backend $b,
        public readonly \MyPHP\Window $w,
        WindowOptions $o,
        bool $panel
    ) {
        if (self::$instance !== null) {
            throw new Platform\Unsupported('iOS platform only supports a single full-screen window');
        }
        self::$instance = $this;

        $f = iOS::ffi();
        $this->width = (float)$f->myphp_ios_get_screen_width();
        $this->height = (float)$f->myphp_ios_get_screen_height();
        $this->scale = max(1.0, $f->myphp_ios_get_screen_scale());
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
        throw new Platform\Unsupported('WebView as main window content not supported yet on iOS');
    }

    public function createSurface(WindowOptions $o, Closure $frame, ?Closure $afterPresent): void
    {
        $this->frame = $frame;
        $this->afterPresent = $afterPresent;
        // the launcher's MTKView, drawn by the one Metal renderer from PHP's thread (Metal::render), each frame the run loop asks for
        $this->view = ObjC::import(iOS::ffi()->myphp_ios_get_view());
        $this->metal = Backend::metal();
        $this->metal->adopt($this->view);
        Metal::setClearColor($this->view, App::theme()->background);
        $this->requestFrame();
    }

    public function requestFrame(int $every = 1, bool $repaint = false): void
    {
        if ($this->asked) {
            return;
        }
        $this->asked = true;
        $this->repaintOnly = $repaint;
        iOS::ffi()->myphp_ios_request_render();
        $this->b->signal();
    }

    public function pollEvents(): void
    {
        try {
            $f = iOS::ffi();
            $ev = $f->new('MyphpTouchEvent');
            while ($f->myphp_ios_poll_touch_event(\FFI::addr($ev)) > 0) {
                $this->onTouch((int)$ev->type, (float)$ev->x, (float)$ev->y);
            }

            $iev = $f->new('MyphpInputEvent');
            while ($f->myphp_ios_poll_input_event(\FFI::addr($iev)) > 0) {
                if ((int)$iev->type === 0) {
                    $str = \FFI::string($iev->text);
                    $this->onImeCommit($str);
                } elseif ((int)$iev->type === 1) {
                    $this->onBackspace();
                } elseif ((int)$iev->type === 2) {
                    $this->onImeCompose(\FFI::string($iev->text));
                }
            }

            if ($f->myphp_ios_poll_theme_changed() > 0) {
                $this->b->h->themeChanged(); // App::systemChanged: the theme follows the appearance, the text size and Reduce Motion anew
                $this->requestFrame();
            }

            if ($f->myphp_ios_take_needs_render() > 0) {
                $this->requestFrame();
            }
        } catch (\Throwable $e) {
            Logger::error('pollEvents: ' . $e->getMessage(), 'ios');
            throw $e;
        }
    }

    /** The text the input method is composing (UITextInput's marked text), '' once it commits or gives up: shown in the field, underlined, as on the desktop. */
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
            $input->focus->setMarked($text, strlen($text));
        }
        $this->requestFrame();
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

    public function onBackspace(): void
    {
        $input = $this->w->input;
        if ($input === null) {
            return;
        }
        if ($input->focus !== null) {
            $input->focus->deleteBack();
        } elseif ($input->client !== null) {
            $input->push(['key', \MyPHP\UI\Key::BACKSPACE, '', 0, hrtime(true)]);
        }
        $this->requestFrame();
    }

    public function onTouch(int $type, float $x, float $y): void
    {
        $input = $this->w->input;
        if ($input === null) {
            return;
        }

        $ptX = $x;
        $ptY = $y;
        $time = hrtime(true);

        $input->mouse($ptX, $ptY);
        $input->mods = 0;
        if ($this->w->runtime !== null) {
            $this->w->runtime->touchMode = true;
        }

        switch ($type) {
            case 0: // touchesBegan
                $this->touchActive = true;
                $this->touchStartX = $ptX;
                $this->touchStartY = $ptY;
                $this->touchLastX = $ptX;
                $this->touchLastY = $ptY;
                $this->touchStartTime = $time;
                $this->touchIsScrolling = false;
                $this->touchHistory = [[$time, $ptX, $ptY]];

                // Check if touch starts inside focused text field
                $this->touchInField = false;
                $rt = $this->w->runtime;
                if ($input->focus !== null && $rt !== null && $rt->focus !== null) {
                    $r = $rt->rectOf($rt->focus);
                    if ($r !== null && $ptX >= $r[0] && $ptX < $r[0] + $r[2] && $ptY >= $r[1] && $ptY < $r[1] + $r[3]) {
                        $this->touchInField = true;
                    }
                }

                // Push pointer down immediately so all controls enter their native pressed/dragging state
                $input->push(['down', $ptX, $ptY, 0, 1, 0, $time]);
                $hitId = $rt?->pressTarget($ptX, $ptY);
                if ($hitId !== null) {
                    $rt->flashTouch($hitId, 2.0);
                    iOS::ffi()->myphp_ios_haptic_feedback(0);
                }
                $this->requestFrame();

                $pressId = ++$this->longPressId;
                App::after(0.5, function () use ($pressId, $ptX, $ptY, $time) {
                    if ($this->touchActive && !$this->touchIsScrolling && $this->longPressId === $pressId) {
                        $input = $this->w->input;
                        if ($input !== null) {
                            $input->mouse($ptX, $ptY);
                            $input->push(['down', $ptX, $ptY, 0, 2, 0, $time]);
                            $this->requestFrame();
                        }
                    }
                });
                break;

            case 1: // touchesMoved
                if (!$this->touchActive) {
                    break;
                }
                $rt = $this->w->runtime;
                if ($this->touchInField || ($rt !== null && !$this->touchIsScrolling && $rt->isDraggingControl())) {
                    $input->mouse($ptX, $ptY);
                    $input->push(['drag', $ptX, $ptY, $time]);
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

            case 2: // touchesEnded
                if (!$this->touchActive) {
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
                    if (count($this->touchHistory) >= 2) {
                        $first = $this->touchHistory[0];
                        $last = $this->touchHistory[count($this->touchHistory) - 1];
                        $dt = ($last[0] - $first[0]) / 1e9;
                        if ($dt > 0.01 && $dt < 0.2) {
                            $vx = ($last[1] - $first[1]) / $dt;
                            $vy = ($last[2] - $first[2]) / $dt;
                            $speed = hypot($vx, $vy);
                            if ($speed > 60.0) {
                                $flingDistX = max(-400.0, min(400.0, $vx * 0.22));
                                $flingDistY = max(-400.0, min(400.0, $vy * 0.22));
                                $input->push(['scroll', $this->touchLastX, $this->touchLastY, $flingDistX, $flingDistY, false, true]);
                                $this->requestFrame();
                            }
                        }
                    }
                    $this->requestFrame();
                } else {
                    $input->push(['up', $this->touchStartX, $this->touchStartY, 0, 1, 0, $time]);
                    $input->mouseInside = false;
                    $input->mouseX = -1;
                    $input->mouseY = -1;
                    $this->requestFrame();
                }
                break;

            case 3: // touchesCancelled
                $this->touchActive = false;
                $this->touchIsScrolling = false;
                $this->touchInField = false;
                $this->w->runtime?->cancelTouchPress();
                $this->requestFrame();
                break;
        }
    }

    public function onFrame(): void
    {
        if (!$this->asked || $this->frame === null) {
            return;
        }
        $this->asked = false;

        $this->metal->setDark(App::theme()->dark);
        Metal::setClearColor($this->view, App::theme()->background);
        $this->metal->render($this->view, function (DisplayList $list): void {
            $bounds = ObjC::msg('r:', $this->view, 'bounds');
            ($this->frame)($list, (float)$bounds->w, (float)$bounds->h);
        });
        if ($this->afterPresent !== null) {
            ($this->afterPresent)();
        }
        $this->w->runtime?->framePresented();
        if (iOS::ffi()->myphp_ios_take_needs_render() > 0) {
            $this->requestFrame();
        }

        // Manage soft keyboard visibility
        $hasInputFocus = ($this->w->input?->focus !== null || $this->w->input?->client !== null);
        if ($hasInputFocus && !$this->keyboardVisible) {
            $this->keyboardVisible = true;
            iOS::ffi()->myphp_ios_show_keyboard();
        } elseif (!$hasInputFocus && $this->keyboardVisible) {
            $this->keyboardVisible = false;
            iOS::ffi()->myphp_ios_hide_keyboard();
        }
    }

    public function bounds(): Rectangle
    {
        return new Rectangle(0, 0, (int)round($this->width), (int)round($this->height));
    }

    public function contentBounds(): Rectangle
    {
        [$t, $r, $b, $l] = $this->safeAreaInsets();
        return new Rectangle(
            (int)round($l),
            (int)round($t),
            max(0, (int)round($this->width - $l - $r)),
            max(0, (int)round($this->height - $t - $b))
        );
    }

    public function safeAreaInsets(): array
    {
        $f = iOS::ffi();
        $top = $f->new('float');
        $bottom = $f->new('float');
        $left = $f->new('float');
        $right = $f->new('float');
        $f->myphp_ios_get_safe_insets(\FFI::addr($top), \FFI::addr($bottom), \FFI::addr($left), \FFI::addr($right));
        return [(float)$top->cdata, (float)$right->cdata, (float)$bottom->cdata, (float)$left->cdata];
    }

    public function keyboardHeight(): float
    {
        return (float)iOS::ffi()->myphp_ios_get_keyboard_height();
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
        return new WebView($this, $page, $scrollToContainer, $transparent);
    }
    public function contentView(): mixed { return null; }
    public function release(): void {}
    public function show(): void {}
    public function showInactive(): void {}
    public function hide(): void {}
    public function close(): void
    {
        self::$instance = null;
        $this->b->quit();
    }
    public function isClosed(): bool
    {
        return self::$instance === null;
    }
    public function setTitle(string $title): void {}
    public function setRepresentedFile(string $path): void {}
    public function setDocumentEdited(bool $edited): void {}
    public function setBackgroundColor(array $rgba): void {}
    public function themeChanged(): void {}
    public function hasVibrancy(): bool { return false; }
    public function hasSystemMaterials(): bool { return false; }

    public function wake(float $seconds, bool $repaint): void
    {
        $id = ++$this->wakeId;
        App::after($seconds, function () use ($id, $repaint): void {
            if ($this->wakeId === $id) {
                $this->requestFrame(repaint: $repaint);
            }
        });
    }

    public function repaintAsked(): bool { return $this->repaintOnly; }
    public function displayRate(): float { return 120.0; }
    public function setProgressBar(string $state, float $value): void {}
    public function lastDraw(): string { return ''; }
    public function backingScale(): float { return $this->scale; }
    public function isKey(): bool { return true; }
    public function setAcceptsFirstMouse(bool $on): void {}
    public function setDropFormats(array $formats): void {}
    public function command(string $cmd): void {}
    public function passThrough(Closure $at): void {}
    public function lastFrame(): array { return []; }
    public function capture(): string { return ''; }
    public function underFrame(): ?array { return null; }
    public function pageDrag(): void {}
    public function hasHiddenTitleBar(): bool { return true; }
    public function sizeLimits(): array { return [0, 0, 0, 0]; }
    public function frameSize(): array { return [(int)$this->width, (int)$this->height]; }
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
    public function setContentBounds(Rectangle $r): void {}
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
        throw new Platform\Unsupported('snapshot not implemented on iOS yet');
    }
    public function framePending(): bool { return $this->asked; }
    public function automatedKey(bool $down, int $code, string $chars, string $plain, int $mods): void {}
    public function automatedText(string $text): void {}
    public function chooseMenuItem(array $path): void {}
}
