<?php
// Native UI at 60 fps: each frame PHP rebuilds the tree, lays it out and
// paints it (through CoreGraphics for now; Metal is the next step).
// Run: php -d ffi.enable=1 -d opcache.enable_cli=1 -d opcache.jit=1255 -d opcache.jit_buffer_size=64M examples/native/main.php [rows]
declare(strict_types=1);

use MyPHP\Native\ObjC;
use MyPHP\UI\{Context, Layout, Node};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');
ObjC::boot(['Foundation', 'AppKit', 'QuartzCore']);

$cg = FFI::cdef("
    typedef struct { double x, y, w, h; } CGRect;
    void CGContextSetRGBFillColor(void*, double, double, double, double);
    void CGContextFillRect(void*, CGRect);
", '/System/Library/Frameworks/CoreGraphics.framework/CoreGraphics');

function view(Context $c, int $rows): void
{
    $c->column(0, 0, 1);
      $c->row(12, 8, 0, 0xeeeeee); $c->text('Inbox', 20); $c->button('Compose'); $c->button('Refresh'); $c->end();
      $c->column(0, 1, 1);
        for ($i = 0; $i < $rows; $i++) {
            $c->row(10, 12, 0, ($i & 1) ? 0xf7f7f7 : 0xffffff);
              $c->box(36, 36, ($i * 2654435761) & 0xffffff);
              $c->column(0, 2, 1);
                $c->row(0, 6); $c->text("Sender $i", 14); $c->text("Subject line number $i is here", 14); $c->end();
                $c->text("Preview of the message body, truncated to one line of text $i", 12);
              $c->end();
              $c->text(sprintf('%02d:%02d', $i % 24, ($i * 7) % 60), 12);
              $c->button('Reply');
            $c->end();
        }
      $c->end();
    $c->end();
}

function paint(Node $n, $ctx, FFI $cg, $r, float $vh, float $scroll, int &$quads): void
{
    $y = $n->y - $scroll;
    if ($y > $vh || $y + $n->h < 0) return;
    if ($n->kind !== Node::TEXT) {
        $c = $n->color;
        $cg->CGContextSetRGBFillColor($ctx, (($c >> 16) & 255) / 255, (($c >> 8) & 255) / 255, ($c & 255) / 255, 1);
        $r->x = $n->x; $r->y = $y; $r->w = $n->w; $r->h = $n->h;
        $cg->CGContextFillRect($ctx, $r); $quads++;
    }
    if ($n->text !== null) { // grey bars stand in for glyph runs until CoreText is wired in
        $cg->CGContextSetRGBFillColor($ctx, 0.25, 0.25, 0.25, 0.6);
        $runs = intdiv(strlen($n->text) + 7, 8); $x = $n->x + ($n->kind === Node::BUTTON ? 8 : 0);
        for ($i = 0; $i < $runs; $i++) {
            $r->x = $x; $r->y = $y + $n->h * 0.3; $r->w = 36; $r->h = $n->h * 0.4;
            $cg->CGContextFillRect($ctx, $r); $quads++; $x += 40;
        }
    }
    foreach ($n->kids as $k) paint($k, $ctx, $cg, $r, $vh, $scroll, $quads);
}

$ROWS = (int)($argv[1] ?? 300);
$app = ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication');
ObjC::msg('b:l', $app, 'setActivationPolicy:', 0);

$cgRect = $cg->new('CGRect');
$stats = ['frames' => 0, 'build' => 0, 'layout' => 0, 'paint' => 0, 'since' => hrtime(true)];
$t0 = hrtime(true);
$view = null;

ObjC::defineClass('MyPHPView', 'NSView', [
    'isFlipped' => ['B@:', 'b(pp)', fn($self, $cmd) => true],
    'drawRect:' => ['v@:{CGRect={CGPoint=dd}{CGSize=dd}}', 'v(ppr)', function ($self, $cmd, $dirty) use ($cg, $cgRect, &$stats, $ROWS, $t0) {
        $bounds = ObjC::msg('r:', $self, 'bounds');
        $ctx = ObjC::msg('p:', ObjC::msg('p:', ObjC::cls('NSGraphicsContext'), 'currentContext'), 'CGContext');
        $t = (hrtime(true) - $t0) / 1e9;

        $a = hrtime(true); $c = new Context; view($c, $ROWS);
        $b = hrtime(true); Layout::measure($c->root); Layout::arrange($c->root, 0, 0, $bounds->w, max($bounds->h, $c->root->mh));
        $scroll = (1 - cos($t * 0.8)) * 0.5 * max(0, $c->root->mh - $bounds->h); // glides up and down the list
        $d = hrtime(true); $quads = 0; paint($c->root, $ctx, $cg, $cgRect, $bounds->h, $scroll, $quads);
        $e = hrtime(true);
        $stats['frames']++; $stats['build'] += $b - $a; $stats['layout'] += $d - $b; $stats['paint'] += $e - $d;
        if ($e - $stats['since'] >= 1e9) {
            $n = $stats['frames']; $wall = ($e - $stats['since']) / 1e9;
            printf("%5.1f fps | nodes %5d quads %4d | build %5.2f  layout %5.2f  paint %5.2f = %5.2f ms/frame | mem %.1f MB\n",
                $n / $wall, $c->count, $quads, $stats['build'] / $n / 1e6, $stats['layout'] / $n / 1e6,
                $stats['paint'] / $n / 1e6, ($stats['build'] + $stats['layout'] + $stats['paint']) / $n / 1e6, memory_get_usage(true) / 1048576);
            $stats = ['frames' => 0, 'build' => 0, 'layout' => 0, 'paint' => 0, 'since' => $e];
        }
    }],
]);
ObjC::defineClass('MyPHPTicker', 'NSObject', [
    'tick:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $sender) use (&$view) {
        ObjC::msg('v:b', $view, 'setNeedsDisplay:', true);
    }],
    'windowWillClose:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $note) use ($app) {
        ObjC::msg('v:p', $app, 'terminate:', null);
    }],
]);

$rect = ObjC::rect(0, 0, 900, 640);
$win = ObjC::msg('p:rLLb', ObjC::alloc('NSWindow'), 'initWithContentRect:styleMask:backing:defer:', $rect, 15, 2, false);
ObjC::msg('v:p', $win, 'setTitle:', ObjC::str("MyPHP native — $ROWS rows"));
$view = ObjC::msg('p:r', ObjC::alloc('MyPHPView'), 'initWithFrame:', $rect);
ObjC::msg('v:p', $win, 'setContentView:', $view);
$ticker = ObjC::msg('p:', ObjC::alloc('MyPHPTicker'), 'init');
ObjC::msg('v:p', $win, 'setDelegate:', $ticker);
// NSTimer for now: CADisplayLink via displayLinkWithTarget:selector: did not tick, see AGENTS.md.
ObjC::msg('p:dpppb', ObjC::cls('NSTimer'), 'scheduledTimerWithTimeInterval:target:selector:userInfo:repeats:', 1 / 60, $ticker, ObjC::sel('tick:'), null, true);

ObjC::msg('v:', $win, 'center');
ObjC::msg('v:p', $win, 'makeKeyAndOrderFront:', null);
ObjC::msg('v:b', $app, 'activateIgnoringOtherApps:', true);
ObjC::msg('v:', $app, 'run');
