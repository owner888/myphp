<?php
// Native UI on Metal: each frame PHP rebuilds the tree, lays it out and
// writes a display list into a GPU buffer; one instanced draw call shows it.
// Run: ./run.sh native [rows]
declare(strict_types=1);

use MyPHP\Native\{CoreText, Metal, ObjC};
use MyPHP\UI\{Context, DisplayList, GlyphAtlas, Layout, Node, Text};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');
ObjC::boot(['Foundation', 'AppKit', 'QuartzCore', 'Metal', 'MetalKit', 'CoreText']);

function view(Context $c, int $rows): void
{
    $c->column(0, 0, 1);
      $c->row(12, 8, 0, 0xeeeeee); $c->text('Inbox', 20); $c->button('Compose'); $c->button('Refresh'); $c->end();
      $c->column(0, 1, 1);
        for ($i = 0; $i < $rows; $i++) {
            $c->row(10, 12, 0, ($i & 1) ? 0xf7f7f7 : 0xffffff);
              $c->box(36, 36, ($i * 2654435761) & 0xffffff);
              $c->column(0, 2, 1);
                $c->row(0, 6); $c->text("Sender $i", 14); $c->text("主题 {$i}：Subject line number $i is here", 14, 0x555555); $c->end();
                $c->text("Preview of the message body, truncated to one line of text $i", 12, 0x888888);
              $c->end();
              $c->text(sprintf('%02d:%02d', $i % 24, ($i * 7) % 60), 12);
              $c->button('Reply');
            $c->end();
        }
      $c->end();
    $c->end();
}

function paint(Node $n, DisplayList $list, Text $text, float $vh, float $scroll): void
{
    $y = $n->y - $scroll;
    if ($y > $vh || $y + $n->h < 0) return;
    if ($n->color !== null) {
        $list->rect($n->x, $y, $n->w, $n->h, $n->color, 1.0, $n->kind === Node::BUTTON ? 6.0 : ($n->kind === Node::BOX ? 18.0 : 0.0));
    }
    if ($n->text !== null) {
        $inset = $n->kind === Node::BUTTON ? 12 : 0; // button label centered by its padding
        $text->paint($list, $n->text, $n->fontSize, $n->x + $inset, $y + ($n->kind === Node::BUTTON ? 5 : 0), $n->fg);
    }
    foreach ($n->kids as $k) paint($k, $list, $text, $vh, $scroll);
}

$ROWS = (int)($argv[1] ?? 300);
$app = ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication');
ObjC::msg('b:l', $app, 'setActivationPolicy:', 0);

$scale = ObjC::msg('d:', ObjC::msg('p:', ObjC::cls('NSScreen'), 'mainScreen'), 'backingScaleFactor');
$text = new Text(new CoreText($scale), new GlyphAtlas());
$metal = new Metal();
$metal->attachAtlas($text->atlas, $scale);
$stats = ['frames' => 0, 'build' => 0, 'layout' => 0, 'paint' => 0, 'since' => hrtime(true)];
$t0 = hrtime(true);

$rect = ObjC::rect(0, 0, 900, 640);
$view = $metal->createView($rect, function ($view, DisplayList $list) use (&$stats, $ROWS, $t0, $text) {
    $bounds = ObjC::msg('r:', $view, 'bounds');
    $t = (hrtime(true) - $t0) / 1e9;

    $a = hrtime(true); $c = new Context($text); view($c, $ROWS);
    $b = hrtime(true); Layout::measure($c->root); Layout::arrange($c->root, 0, 0, $bounds->w, max($bounds->h, $c->root->mh));
    $scroll = (1 - cos($t * 0.8)) * 0.5 * max(0, $c->root->mh - $bounds->h); // glides up and down the list
    $d = hrtime(true); paint($c->root, $list, $text, $bounds->h, $scroll);
    $e = hrtime(true);
    $stats['frames']++; $stats['build'] += $b - $a; $stats['layout'] += $d - $b; $stats['paint'] += $e - $d;
    if ($e - $stats['since'] >= 1e9) {
        $n = $stats['frames']; $wall = ($e - $stats['since']) / 1e9;
        printf("%5.1f fps | nodes %5d rects %4d | build %5.2f  layout %5.2f  paint %5.2f = %5.2f ms/frame | mem %.1f MB\n",
            $n / $wall, $c->count, $list->n, $stats['build'] / $n / 1e6, $stats['layout'] / $n / 1e6,
            $stats['paint'] / $n / 1e6, ($stats['build'] + $stats['layout'] + $stats['paint']) / $n / 1e6, memory_get_usage(true) / 1048576);
        $stats = ['frames' => 0, 'build' => 0, 'layout' => 0, 'paint' => 0, 'since' => $e];
    }
});

ObjC::defineClass('MyPHPWindowDelegate', 'NSObject', [
    'windowWillClose:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $note) use ($app) {
        ObjC::msg('v:p', $app, 'terminate:', null);
    }],
]);
$win = ObjC::msg('p:rLLb', ObjC::alloc('NSWindow'), 'initWithContentRect:styleMask:backing:defer:', $rect, 15, 2, false);
ObjC::msg('v:p', $win, 'setTitle:', ObjC::str("MyPHP native (Metal) — $ROWS rows"));
ObjC::msg('v:p', $win, 'setContentView:', $view);
ObjC::msg('v:p', $win, 'setDelegate:', ObjC::msg('p:', ObjC::alloc('MyPHPWindowDelegate'), 'init'));
ObjC::msg('v:', $win, 'center');
ObjC::msg('v:p', $win, 'makeKeyAndOrderFront:', null);
ObjC::msg('v:b', $app, 'activateIgnoringOtherApps:', true);
ObjC::msg('v:', $app, 'run');
