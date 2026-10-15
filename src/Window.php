<?php
declare(strict_types=1);

namespace MyPHP;

use MyPHP\Native\{Metal, ObjC, View, WebView};
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
    private const TITLED = 1, CLOSABLE = 2, MINIATURIZABLE = 4, RESIZABLE = 8;
    private const BACKING_BUFFERED = 2;
    /** @var array<int, Window> open windows by NSWindow address */
    private static array $open = [];
    private static bool $delegateDefined = false;

    private static int $nextId = 1;

    private $ns;
    private $view = null;
    private ?WebView $page = null;
    private string $secret = '';
    public readonly int $id;
    public readonly ?Input $input;
    public readonly ?Runtime $runtime;
    private array $stats = ['frames' => 0, 'ms' => 0, 'since' => 0];

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
        $this->runtime = $o->content === null ? null : new Runtime($this->input);

        $rect = ObjC::rect($o->x, $o->y, $o->width, $o->height);
        $style = self::TITLED | self::CLOSABLE | self::MINIATURIZABLE | ($o->disableResize ? 0 : self::RESIZABLE);
        $this->ns = ObjC::msg('p:rLLb', ObjC::alloc('NSWindow'), 'initWithContentRect:styleMask:backing:defer:', $rect, $style, self::BACKING_BUFFERED, false);
        ObjC::msg('v:b', $this->ns, 'setReleasedWhenClosed:', false);
        $this->setTitle($o->title ?? App::name());

        if ($o->content === null) {
            $this->secret = bin2hex(random_bytes(16));
            $this->page = WebView::create(ObjC::rect(0, 0, $o->width, $o->height),
                ['platform' => 'darwin', 'windowId' => $this->id, 'version' => App::VERSION, 'secret' => $this->secret],
                function (string $raw) {
                    $reply = Ipc::handle($raw, $this->secret);
                    if ($reply !== null) {
                        $this->page->deliver($reply);
                    }
                });
            $this->view = $this->page->ns;
            if ($o->html !== null) {
                $this->page->loadHTML($o->html);
            } else {
                $this->page->loadURL($o->url);
            }
        } else {
            $this->view = $this->nativeView($o);
        }
        ObjC::msg('v:p', $this->ns, 'setContentView:', $this->view);
        ObjC::msg('b:p', $this->ns, 'makeFirstResponder:', $this->view);
        ObjC::msg('v:p', $this->ns, 'setDelegate:', self::delegate());
        self::$open[ObjC::addr($this->ns)] = $this;
        if ($o->x === 0 && $o->y === 0) {
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
                $root = $this->runtime->frame(function () use ($content, $text, $bounds) {
                    $c = new Context($text, $this->runtime, $bounds->w, $bounds->h);
                    ($content->view)($c);
                    Layout::measure($c->root);
                    Layout::arrange($c->root, 0, 0, $bounds->w, $bounds->h);
                    return $c->root;
                });
                Painter::paint($root, $list, $text);
                if ($this->runtime->needsFrame) {
                    Metal::requestFrame($view);
                }
                if (getenv('MYPHP_STATS')) {
                    $this->stat($t0, $list);
                }
            });
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

    public function evaluate(string $js): void
    {
        $this->page?->evaluate($js);
    }

    private static function delegate()
    {
        if (!self::$delegateDefined) {
            self::$delegateDefined = true;
            ObjC::defineClass('MyPHPWindowDelegate', 'NSObject', [
                'windowWillClose:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $note) {
                    $ns = ObjC::msg('p:', $note, 'object');
                    unset(self::$open[ObjC::addr($ns)]);
                    if (self::$open === [] && App::$quitOnLastWindowClosed) {
                        App::quit();
                    }
                }],
            ]);
        }
        return ObjC::msg('p:', ObjC::alloc('MyPHPWindowDelegate'), 'init');
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
