<?php
// Native UI on Metal with real input: a scrolling inbox whose buttons work.
// Frames are built on demand (an event, or state the view changed), each
// one rebuilding the tree, laying it out and writing a display list.
// Run: ./run.sh native [rows]
declare(strict_types=1);

use MyPHP\Native\{CoreText, Metal, ObjC, View};
use MyPHP\UI\{Context, DisplayList, GlyphAtlas, Input, Layout, Node, Runtime, ScrollState, Text};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');
ObjC::boot(['Foundation', 'AppKit', 'QuartzCore', 'Metal', 'MetalKit', 'CoreText']);

// ---- app state ------------------------------------------------------------
$state = ['rows' => (int)($argv[1] ?? 300), 'replied' => [], 'scroll' => new ScrollState, 'last' => ''];

function view(Context $c, array &$s): void
{
    $c->column(0, 0, 1);
      $c->row(12, 8, 0, 0xeeeeee);
        $c->text('Inbox', 20);
        if ($c->clicked($c->button('Compose'))) { $s['rows']++; $s['last'] = "composed row {$s['rows']}"; }
        if ($c->clicked($c->button('Refresh'))) { $s['replied'] = []; $s['last'] = 'refreshed'; }
        $c->text($s['last'], 13, 0x888888);
      $c->end();
      $c->column(0, 1, 1)->scroll($s['scroll']);
        for ($i = 0; $i < $s['rows']; $i++) {
            $c->row(10, 12, 0, ($i & 1) ? 0xf7f7f7 : 0xffffff);
              $c->box(36, 36, ($i * 2654435761) & 0xffffff);
              $c->column(0, 2, 1);
                $c->row(0, 6); $c->text("Sender $i", 14); $c->text("主题 {$i}：Subject line number $i is here", 14, 0x555555); $c->end();
                $c->text("Preview of the message body, truncated to one line of text $i", 12, 0x888888);
              $c->end();
              $c->text(sprintf('%02d:%02d', $i % 24, ($i * 7) % 60), 12);
              $b = $c->button(isset($s['replied'][$i]) ? 'Replied' : 'Reply');
              if (isset($s['replied'][$i])) { $b->color = 0x34c759; }
              elseif ($c->hovered($b)) { $b->color = 0x5c94ff; }
              if ($c->clicked($b)) { $s['replied'][$i] = true; $s['last'] = "replied to $i"; }
            $c->end();
        }
      $c->end();
    $c->end();
}

function paint(Node $n, DisplayList $list, Text $text): void
{
    if ($list->clipped($n->x, $n->y, $n->w, $n->h)) return;
    if ($n->color !== null) {
        $list->rect($n->x, $n->y, $n->w, $n->h, $n->color, 1.0, $n->kind === Node::BUTTON ? 6.0 : ($n->kind === Node::BOX ? 18.0 : 0.0));
    }
    if ($n->text !== null) {
        $inset = $n->kind === Node::BUTTON ? 12 : 0;
        $text->paint($list, $n->text, $n->fontSize, $n->x + $inset, $n->y + ($n->kind === Node::BUTTON ? 5 : 0), $n->fg);
    }
    if ($n->scroll !== null) $list->pushClip($n->x, $n->y, $n->w, $n->h);
    foreach ($n->kids as $k) paint($k, $list, $text);
    if ($n->scroll !== null) $list->popClip();
}

// ---- window ----------------------------------------------------------------
$app = ObjC::msg('p:', ObjC::cls('NSApplication'), 'sharedApplication');
ObjC::msg('b:l', $app, 'setActivationPolicy:', 0);

$scale = ObjC::msg('d:', ObjC::msg('p:', ObjC::cls('NSScreen'), 'mainScreen'), 'backingScaleFactor');
$text = new Text(new CoreText($scale), new GlyphAtlas());
$metal = new Metal();
$metal->attachAtlas($text->atlas, $scale);
$input = new Input();
$runtime = new Runtime($input);
$stats = ['frames' => 0, 'ms' => 0, 'since' => hrtime(true)];

$rect = ObjC::rect(0, 0, 900, 640);
$view = View::create($metal, $rect, $input, function ($view, DisplayList $list) use (&$state, &$stats, $runtime, $text) {
    $bounds = ObjC::msg('r:', $view, 'bounds');
    $t0 = hrtime(true);
    $root = $runtime->frame(function () use (&$state, $runtime, $text, $bounds) {
        $c = new Context($text, $runtime);
        view($c, $state);
        Layout::measure($c->root);
        Layout::arrange($c->root, 0, 0, $bounds->w, $bounds->h);
        return $c->root;
    });
    paint($root, $list, $text);
    $t1 = hrtime(true);
    $stats['frames']++; $stats['ms'] += ($t1 - $t0) / 1e6;
    if ($t1 - $stats['since'] >= 1e9) {
        printf("%3d frames this second | %5.2f ms/frame (%d pass%s) | %4d quads | mem %.1f MB\n",
            $stats['frames'], $stats['ms'] / $stats['frames'], $runtime->passes, $runtime->passes > 1 ? 'es' : '', $list->n, memory_get_usage(true) / 1048576);
        $stats = ['frames' => 0, 'ms' => 0, 'since' => $t1];
    }
});

ObjC::defineClass('MyPHPWindowDelegate', 'NSObject', [
    'windowWillClose:' => ['v@:@', 'v(ppp)', function ($self, $cmd, $note) use ($app) {
        ObjC::msg('v:p', $app, 'terminate:', null);
    }],
]);
$win = ObjC::msg('p:rLLb', ObjC::alloc('NSWindow'), 'initWithContentRect:styleMask:backing:defer:', $rect, 15, 2, false);
ObjC::msg('v:p', $win, 'setTitle:', ObjC::str('MyPHP native — scroll, click, hover'));
ObjC::msg('v:p', $win, 'setContentView:', $view);
ObjC::msg('v:p', $win, 'setDelegate:', ObjC::msg('p:', ObjC::alloc('MyPHPWindowDelegate'), 'init'));
ObjC::msg('b:p', $win, 'makeFirstResponder:', $view);
ObjC::msg('v:', $win, 'center');
ObjC::msg('v:p', $win, 'makeKeyAndOrderFront:', null);
ObjC::msg('v:b', $app, 'activateIgnoringOtherApps:', true);
Metal::requestFrame($view);
ObjC::msg('v:', $app, 'run');
