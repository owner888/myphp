<?php
declare(strict_types=1);

namespace MyPHP;

use MyPHP\Native\{Drop, Metal, ObjC, View, WebView};
use MyPHP\UI\{Context, DisplayList, Input, Layout, Painter, Runtime};
use RuntimeException;

/**
 * A window showing native UI or a web page. Create it from App::whenReady().
 * With native content, each frame runs the view function through the
 * window's Runtime, lays the tree out to the window's size and paints it;
 * frames happen on demand. With a page, the window holds a WebView whose
 * bridge routes calls to Ipc and events back.
 */
final class Window
{
    private const TITLED = 1, CLOSABLE = 2, MINIATURIZABLE = 4, RESIZABLE = 8, FULL_SCREEN = 1 << 14;
    private const BACKING_BUFFERED = 2;
    /** @var array<int, Window> open windows by NSWindow address */
    private static array $open = [];
    private static bool $delegateDefined = false;

    private static int $nextId = 1;

    private $ns;
    private $view = null;
    private ?WebView $page = null;
    private string $secret = '';
    private bool $pageReady = false;
    /** @var list<\Closure> */
    private array $whenPageReady = [];
    public readonly int $id;
    public readonly ?Input $input;
    public readonly ?Runtime $runtime;
    private array $stats = ['frames' => 0, 'ms' => 0, 'since' => 0];
    /** @var array<string, list<\Closure>> listeners by event */
    private array $listeners = [];
    private string $stateKey = '';
    /** @var array<int, Channel> open channels by the page's id */
    private array $channels = [];
    public const EVENTS = ['close', 'closed', 'focus', 'blur', 'resize', 'move', 'minimize', 'restore', 'enterFullScreen', 'leaveFullScreen', 'fileDrop'];
    /** @var array<int, Window> by content view address, for drops */
    private static array $byView = [];

    public function __construct(WindowOptions $o)
    {
        if (!App::isReady()) {
            throw new RuntimeException('create windows from App::whenReady()');
        }
        if ($o->content === null && $o->html === null && $o->url === null) {
            throw new RuntimeException('WindowOptions needs content, html or url');
        }
        $this->id = self::$nextId++;
        $this->input = $o->content === null ? null : new Input();
        $this->runtime = $o->content === null ? null : new Runtime($this->input, App::text());
        if ($this->runtime !== null) {
            $this->runtime->openURL = App::openURL(...);
        }

        $this->stateKey = $o->stateKey;
        $rect = ObjC::rect($o->x, $o->y, $o->width, $o->height);
        $saved = $o->stateKey !== '' ? WindowState::get($o->stateKey) : null;
        $style = self::TITLED | self::CLOSABLE | self::MINIATURIZABLE | ($o->disableResize ? 0 : self::RESIZABLE);
        $this->ns = ObjC::msg('p:rLLb', ObjC::alloc('NSWindow'), 'initWithContentRect:styleMask:backing:defer:', $rect, $style, self::BACKING_BUFFERED, false);
        ObjC::msg('v:b', $this->ns, 'setReleasedWhenClosed:', false);
        if ($saved !== null) {
            ObjC::msg('v:rb', $this->ns, 'setFrame:display:', ObjC::rect($saved['x'], $saved['y'], $saved['w'], $saved['h']), true);
        }
        $this->setTitle($o->title ?? App::name());

        if ($o->content === null) {
            $this->secret = bin2hex(random_bytes(16));
            $this->page = WebView::create(ObjC::rect(0, 0, $o->width, $o->height),
                ['platform' => 'darwin', 'windowId' => $this->id, 'version' => App::VERSION, 'secret' => $this->secret],
                function (string $raw) {
                    if ($raw === '{"t":"dom-ready"}') {
                        $this->pageReady = true;
                        $this->dropChannels(); // a new page: the old one's channels are gone
                        $callbacks = $this->whenPageReady;
                        $this->whenPageReady = [];
                        foreach ($callbacks as $fn) {
                            $fn();
                        }
                        return;
                    }
                    $reply = Ipc::handle($raw, $this->secret, $this);
                    if ($reply !== null) {
                        $this->page->deliver($reply);
                    }
                }, $o->preload);
            $this->view = $this->page->ns;
            if ($o->html !== null) {
                $this->page->loadHTML($o->html);
            } else {
                $this->page->loadURL($o->url);
            }
        } else {
            $this->view = $this->nativeView($o);
            Metal::setClearColor($this->view, App::theme()->background);
        }
        ObjC::msg('v:p', $this->ns, 'setContentView:', $this->view);
        ObjC::msg('b:p', $this->ns, 'makeFirstResponder:', $this->view);
        ObjC::msg('v:p', $this->ns, 'setDelegate:', self::delegate());
        self::$open[ObjC::addr($this->ns)] = $this;
        self::$byView[ObjC::addr($this->view)] = $this;
        Drop::handle(function ($view, array $paths, float $x, float $y) {
            $w = self::$byView[ObjC::addr($view)] ?? null;
            if ($w === null) {
                return;
            }
            $e = new FileDropEvent($w, $paths, $x, $y);
            foreach ($w->listeners['fileDrop'] ?? [] as $fn) {
                $fn($e);
            }
        });
        if ($saved === null && $o->x === 0 && $o->y === 0) {
            $this->center();
        }
        if (!$o->hidden) {
            $this->show();
        }
    }

    private function nativeView(WindowOptions $o)
    {
        $content = $o->content;
        $text = App::text();
        return View::create(App::metal(), ObjC::rect(0, 0, $o->width, $o->height), $this->input,
            function ($view, DisplayList $list) use ($content, $text) {
                $bounds = ObjC::msg('r:', $view, 'bounds');
                $t0 = hrtime(true);
                $text->beginFrame();
                $this->runtime->scrollbarWidth = App::theme()->scrollbarWidth;
                App::metal()->setDark(App::theme()->dark);
                $root = $this->runtime->frame(function () use ($content, $text, $bounds) {
                    $c = new Context($text, $this->runtime, $bounds->w, $bounds->h, App::theme(), App::scale());
                    ($content->view)($c);
                    Layout::measure($c->root);
                    Layout::arrange($c->root, 0, 0, $bounds->w, $bounds->h);
                    return $c->root;
                });
                Painter::paint($root, $list, $text, App::theme(), $this->runtime);
                Painter::tooltip($root, $list, $text, App::theme(), $this->runtime);
                if ($text->repaint) {
                    // An atlas started over during the paint: what was painted before points at slots that are gone.
                    $text->repaint = false;
                    $list->reset();
                    Painter::paint($root, $list, $text, App::theme(), $this->runtime);
                    Painter::tooltip($root, $list, $text, App::theme(), $this->runtime);
                }
                $this->showCursor($this->runtime->cursor);
                if ($this->runtime->needsFrame) {
                    Metal::requestFrame($view);
                }
                $wake = $this->runtime->nextWake();
                if ($wake !== null) {
                    View::wake($view, $wake);
                }
                if (getenv('MYPHP_STATS')) {
                    $this->stat($t0, $list);
                }
            });
    }

    /** The channel the page numbered $id, for a call just made (Ipc). */
    public function openChannel(int $id, string $secret): Channel
    {
        return $this->channels[$id] = new Channel($this, $id, $secret);
    }

    public function channel(int $id): ?Channel
    {
        return $this->channels[$id] ?? null;
    }

    public function forgetChannel(int $id): void
    {
        unset($this->channels[$id]);
    }

    /** The page went away: every channel is closed on its side. */
    private function dropChannels(): void
    {
        foreach ($this->channels as $ch) {
            $ch->closedByPage();
        }
        $this->channels = [];
    }

    public function minimize(): void
    {
        ObjC::msg('v:p', $this->ns, 'miniaturize:', null);
    }

    /** Whether the window is zoomed to fill the screen (macOS's maximize). */
    public function isMaximized(): bool
    {
        return ObjC::msg('b:', $this->ns, 'isZoomed');
    }

    public function setMaximized(bool $on): void
    {
        if ($this->isMaximized() !== $on) {
            ObjC::msg('v:p', $this->ns, 'zoom:', null);
        }
    }

    public function toggleFullScreen(): void
    {
        ObjC::msg('v:p', $this->ns, 'toggleFullScreen:', null);
    }

    /** The window's web page, or null for native UI. */
    public function page(): ?WebView
    {
        return $this->page;
    }

    /** Sends an event to the window's page; nothing for native UI. */
    public function send(string $name, mixed $payload = null): void
    {
        $this->page?->deliver(['t' => 'event', 'n' => $name, 'p' => $payload]);
    }

    /**
     * Runs JavaScript in the page. With $then, it gets the script's value
     * as the page's description of it (a string, "42", null) and the error
     * message if it threw: $then(?string $value, ?string $error).
     */
    public function evaluate(string $js, ?\Closure $then = null): void
    {
        if ($this->page === null) {
            return;
        }
        if ($then === null) {
            $this->page->evaluate($js);
            return;
        }
        $this->page->evaluate($js, ObjC::block('pp', function ($result, $error) use ($then) {
            $then(
                $result === null ? null : ObjC::phpStr(ObjC::msg('p:', $result, 'description')),
                $error === null ? null : ObjC::phpStr(ObjC::msg('p:', $error, 'localizedDescription')),
            );
        }));
    }

    /** Calls $fn once the page's DOM is ready (at once if it already is). */
    public function whenPageReady(\Closure $fn): void
    {
        if ($this->pageReady) {
            $fn();
            return;
        }
        $this->whenPageReady[] = $fn;
    }

    private static function delegate()
    {
        if (!self::$delegateDefined) {
            self::$delegateDefined = true;
            $fire = fn(string $event) => function ($self, $cmd, $note) use ($event) {
                $w = self::$open[ObjC::addr(ObjC::msg('p:', $note, 'object'))] ?? null;
                if ($w !== null) {
                    $w->emit($event);
                    if ($event === 'resize' || $event === 'move') {
                        $w->captureState();
                    }
                }
            };
            ObjC::defineClass('MyPHPWindowDelegate', 'NSObject', [
                'windowShouldClose:' => ['B@:@', 'b(ppp)', function ($self, $cmd, $sender) {
                    $w = self::$open[ObjC::addr($sender)] ?? null;
                    if ($w === null) {
                        return true;
                    }
                    $e = new CloseEvent($w);
                    foreach ($w->listeners['close'] ?? [] as $fn) {
                        $fn($e);
                    }
                    return !$e->canceled;
                }],
                'windowWillClose:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $note) {
                    $ns = ObjC::msg('p:', $note, 'object');
                    $w = self::$open[ObjC::addr($ns)] ?? null;
                    unset(self::$open[ObjC::addr($ns)]);
                    if ($w !== null) {
                        unset(self::$byView[ObjC::addr($w->view)]);
                        $w->dropChannels();
                        $w->captureState();
                        WindowState::save();
                        $w->emit('closed');
                    }
                    if (self::$open === [] && App::$quitOnLastWindowClosed) {
                        App::quit();
                    }
                }],
                'windowDidBecomeKey:' => ['v@:@', 'v(ppp)', $fire('focus')],
                'windowDidResignKey:' => ['v@:@', 'v(ppp)', $fire('blur')],
                'windowDidResize:' => ['v@:@', 'v(ppp)', $fire('resize')],
                'windowDidMove:' => ['v@:@', 'v(ppp)', $fire('move')],
                'windowDidMiniaturize:' => ['v@:@', 'v(ppp)', $fire('minimize')],
                'windowDidDeminiaturize:' => ['v@:@', 'v(ppp)', $fire('restore')],
                'windowDidEnterFullScreen:' => ['v@:@', 'v(ppp)', $fire('enterFullScreen')],
                'windowDidExitFullScreen:' => ['v@:@', 'v(ppp)', $fire('leaveFullScreen')],
                'windowDidChangeBackingProperties:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $note) { App::rescale(); }],
            ], ['NSWindowDelegate']);
        }
        return ObjC::msg('p:', ObjC::alloc('MyPHPWindowDelegate'), 'init');
    }

    /**
     * Calls $fn on $event, one of EVENTS: close (with a CloseEvent to
     * cancel), closed, focus, blur, resize, move, minimize, restore,
     * enterFullScreen, leaveFullScreen, fileDrop (with a FileDropEvent).
     * Returns a function that removes it.
     */
    public function on(string $event, \Closure $fn): \Closure
    {
        if (!in_array($event, self::EVENTS, true)) {
            throw new RuntimeException("no window event \"$event\"");
        }
        $this->listeners[$event][] = $fn;
        return function () use ($event, $fn) {
            $this->listeners[$event] = array_values(array_filter($this->listeners[$event] ?? [], fn($f) => $f !== $fn));
        };
    }

    private function emit(string $event): void
    {
        foreach ($this->listeners[$event] ?? [] as $fn) {
            $fn($this);
        }
    }

    /** Remembers the frame under the state key, while not full screen or minimized. */
    private function captureState(): void
    {
        if ($this->stateKey === '' || ObjC::msg('b:', $this->ns, 'isMiniaturized') || (ObjC::msg('L:', $this->ns, 'styleMask') & self::FULL_SCREEN)) {
            return;
        }
        $r = ObjC::msg('r:', $this->ns, 'frame');
        WindowState::set($this->stateKey, $r->x, $r->y, $r->w, $r->h);
    }

    private string $cursorShown = 'arrow';

    /** The pointer over the content: the arrow, or the hand over links. */
    private function showCursor(string $cursor): void
    {
        if ($cursor === $this->cursorShown) {
            return;
        }
        $this->cursorShown = $cursor;
        ObjC::msg('v:', ObjC::msg('p:', ObjC::cls('NSCursor'), match ($cursor) { 'pointer' => 'pointingHandCursor', 'ibeam' => 'IBeamCursor', 'resizeEW' => 'resizeLeftRightCursor', 'resizeNS' => 'resizeUpDownCursor', default => 'arrowCursor' }), 'set');
    }

    /** The theme changed: native UI clears to its background and redraws. */
    public function themeChanged(): void
    {
        if ($this->page === null) {
            Metal::setClearColor($this->view, App::theme()->background);
            $this->update();
        }
    }

    /** Asks for a frame after state changed outside the view function (native UI). */
    public function update(): void
    {
        if ($this->page === null) {
            Metal::requestFrame($this->view);
        }
    }

    public function show(): void
    {
        ObjC::msg('v:p', $this->ns, 'makeKeyAndOrderFront:', null);
        $this->update();
    }

    public function hide(): void
    {
        ObjC::msg('v:p', $this->ns, 'orderOut:', null);
    }

    public function close(): void
    {
        ObjC::msg('v:', $this->ns, 'close');
    }

    public function center(): void
    {
        ObjC::msg('v:', $this->ns, 'center');
    }

    public function setTitle(string $title): void
    {
        ObjC::msg('v:p', $this->ns, 'setTitle:', ObjC::str($title));
    }

    /** @return array{0: int, 1: int} content size in points */
    public function size(): array
    {
        $r = ObjC::msg('r:', ObjC::msg('p:', $this->ns, 'contentView'), 'frame');
        return [(int)$r->w, (int)$r->h];
    }

    public function setSize(int $width, int $height): void
    {
        $s = ObjC::$c->new('CGSize');
        $s->w = $width; $s->h = $height;
        ObjC::msg('v:z', $this->ns, 'setContentSize:', $s);
    }

    /** @return list<Window> */
    public static function all(): array
    {
        return array_values(self::$open);
    }

    /** The window that has the keyboard, or null. */
    public static function key(): ?Window
    {
        $ns = ObjC::msg('p:', ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication'), 'keyWindow');
        return $ns === null ? null : (self::$open[ObjC::addr($ns)] ?? null);
    }

    /** The NSWindow, for native code that needs it. */
    public function native()
    {
        return $this->ns;
    }

    private function stat(int $t0, DisplayList $list): void
    {
        $t1 = hrtime(true);
        $s = &$this->stats;
        if ($s['since'] === 0) {
            $s['since'] = $t1;
        }
        $s['frames']++; $s['ms'] += ($t1 - $t0) / 1e6;
        if ($t1 - $s['since'] >= 1e9) {
            $text = App::text();
            printf("%3d frames this second | %5.2f ms/frame (%d pass%s) | %4d quads | %4d lines cached, %d atlas resets | mem %.1f MB\n",
                $s['frames'], $s['ms'] / $s['frames'], $this->runtime->passes, $this->runtime->passes > 1 ? 'es' : '',
                $list->n, $text->lineCount(), $text->resets, memory_get_usage(true) / 1048576);
            $s = ['frames' => 0, 'ms' => 0, 'since' => $t1];
        }
    }
}
