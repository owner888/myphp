<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use Closure;
use MyPHP\Native\ObjC;
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
        $this->b->signal();
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
    }

    public function bounds(): Rectangle
    {
        return new Rectangle(0.0, 0.0, $this->width, $this->height);
    }

    public function contentBounds(): Rectangle
    {
        return new Rectangle(0.0, 0.0, $this->width, $this->height);
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
