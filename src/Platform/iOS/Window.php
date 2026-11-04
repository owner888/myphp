<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use Closure;
use MyPHP\Native\{iOS, ObjC};
use MyPHP\Platform;
use MyPHP\{App, Logger, Rectangle, WindowOptions};
use MyPHP\UI\DisplayList;

final class Window implements Platform\Window
{
    private static ?self $instance = null;
    private ?Closure $frame = null;
    private ?Closure $afterPresent = null;
    private bool $asked = false;
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

        try {
            $f = iOS::ffi();
            $this->width = (float)$f->myphp_ios_get_screen_width();
            $this->height = (float)$f->myphp_ios_get_screen_height();
            $this->scale = max(1.0, $f->myphp_ios_get_screen_scale());
        } catch (\Throwable $e) {
            $mainScreen = ObjC::cls('UIScreen')?->msg('mainScreen');
            if ($mainScreen !== null) {
                $this->scale = max(1.0, (float)$mainScreen->msg('scale'));
                $bounds = $mainScreen->msg('bounds');
                if (is_object($bounds) && isset($bounds->w, $bounds->h)) {
                    $this->width = (float)$bounds->w;
                    $this->height = (float)$bounds->h;
                }
            }
        }
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
        $this->requestFrame();
    }

    public function requestFrame(bool $repaintOnly = false): void
    {
        if ($this->asked) {
            return;
        }
        $this->asked = true;
        try {
            iOS::ffi()->myphp_ios_request_render();
        } catch (\Throwable $e) {}
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
                    $str = \FFI::string(\FFI::cast('char*', \FFI::addr($iev->text)));
                    $this->onImeCommit($str);
                } elseif ((int)$iev->type === 1) {
                    $this->onBackspace();
                }
            }
        } catch (\Throwable $e) {
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
                        $input->push(['down', $ptX, $ptY, 0, 1, 0, $time]);
                    }
                }

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
                if ($this->touchInField) {
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
                    $this->touchLastX = $ptX;
                    $this->touchLastY = $ptY;
                }

                if ($this->touchIsScrolling) {
                    $dx = $ptX - $this->touchLastX;
                    $dy = $ptY - $this->touchLastY;
                    $this->touchLastX = $ptX;
                    $this->touchLastY = $ptY;

                    $input->push(['scroll', $ptX, $ptY, $dx, $dy, true, false]);
                    $this->requestFrame();
                }
                break;

            case 2: // touchesEnded
                if (!$this->touchActive) {
                    break;
                }
                $this->touchActive = false;

                if ($this->touchInField) {
                    $input->mouse($ptX, $ptY);
                    $input->push(['up', $ptX, $ptY, 0, 1, 0, $time]);
                    $this->touchInField = false;
                    $this->requestFrame();
                    break;
                }

                if ($this->touchIsScrolling) {
                    $this->touchIsScrolling = false;
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
                } else {
                    $input->mouse($this->touchStartX, $this->touchStartY);
                    $input->push(['down', $this->touchStartX, $this->touchStartY, 0, 1, 0, $this->touchStartTime]);
                    $input->push(['up', $this->touchStartX, $this->touchStartY, 0, 1, 0, $time]);
                    $this->requestFrame();
                }
                break;

            case 3: // touchesCancelled
                $this->touchActive = false;
                $this->touchIsScrolling = false;
                $this->touchInField = false;
                break;
        }
    }

    public function onFrame(): void
    {
        if (!$this->asked || $this->frame === null) {
            return;
        }
        $this->asked = false;

        $dl = new DisplayList();
        ($this->frame)($dl, $this->width, $this->height, $this->scale);
        if ($this->afterPresent !== null) {
            ($this->afterPresent)();
        }

        // Manage soft keyboard visibility
        $hasInputFocus = ($this->w->input?->focus !== null || $this->w->input?->client !== null);
        if ($hasInputFocus && !$this->keyboardVisible) {
            $this->keyboardVisible = true;
            try {
                iOS::ffi()->myphp_ios_show_keyboard();
            } catch (\Throwable $e) {}
        } elseif (!$hasInputFocus && $this->keyboardVisible) {
            $this->keyboardVisible = false;
            try {
                iOS::ffi()->myphp_ios_hide_keyboard();
            } catch (\Throwable $e) {}
        }
    }

    public function bounds(): Rectangle
    {
        return new Rectangle(0.0, 0.0, $this->width, $this->height);
    }

    public function contentBounds(): Rectangle
    {
        try {
            $f = iOS::ffi();
            $top = $f->new('float');
            $bottom = $f->new('float');
            $left = $f->new('float');
            $right = $f->new('float');
            $f->myphp_ios_get_safe_insets(\FFI::addr($top), \FFI::addr($bottom), \FFI::addr($left), \FFI::addr($right));
            $t = (float)$top->cdata;
            $b = (float)$bottom->cdata;
            $l = (float)$left->cdata;
            $r = (float)$right->cdata;
            return new Rectangle($l, $t, max(0.0, $this->width - $l - $r), max(0.0, $this->height - $t - $b));
        } catch (\Throwable $e) {
            return new Rectangle(0.0, 0.0, $this->width, $this->height);
        }
    }

    public function setBounds(Rectangle $r): void {}
    public function setContentBounds(Rectangle $r): void {}
    public function setMinSize(int $w, int $h): void {}
    public function setMaxSize(int $w, int $h): void {}
    public function setResizable(bool $res): void {}
    public function setTitle(string $title): void {}
    public function show(): void {}
    public function hide(): void {}
    public function isVisible(): bool { return true; }
    public function close(): void
    {
        self::$instance = null;
        $this->b->quit();
    }

    public function isClosed(): bool
    {
        return self::$instance === null;
    }

    public function focus(): void {}
    public function isFocused(): bool { return true; }
    public function minimize(): void {}
    public function isMinimized(): bool { return false; }
    public function unminimize(): void {}
    public function maximize(): void {}
    public function isMaximized(): bool { return true; }
    public function unmaximize(): void {}
    public function setFullscreen(bool $fs): void {}
    public function isFullscreen(): bool { return true; }
    public function setAlwaysOnTop(bool $top): void {}
    public function isAlwaysOnTop(): bool { return false; }
    public function newWebView(Platform\Page $page, bool $scrollToContainer, bool $transparent): Platform\WebView
    {
        return new WebView($this, $page, $scrollToContainer, $transparent);
    }
}
