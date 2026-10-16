<?php
// The cost of a frame, headless: build (the view), layout, record (the
// runtime's hit-test rects) and paint (quads into an FFI buffer) for the
// examples' views and a few dense ones. What it leaves out is the Mac's
// half: CoreText shaping, the atlas, Metal. php tests/bench.php [frames]
// Compare with the numbers in docs/architecture.md after a change to src/UI.
declare(strict_types=1);

namespace MyPHP\Tests;

use Closure;
use MyPHP\UI\{Context, Input, Layout, ListState, Runtime, ScrollState, TableColumn, TextState};
use MyPHP\UI\Svg\Doc;

require __DIR__ . '/Headless.php';

$frames = (int)($argv[1] ?? 300);
$phases = function (string $name, Closure $view, float $w, float $h) use ($frames): void {
    $hl = new Headless($view, $w, $h);
    $tb = $tl = $tr = $tp = 0.0; $quads = 0; $t0 = 0;
    for ($f = 0; $f < $frames + 2; $f++) {
        $t0 = hrtime(true);
        $root = $hl->rt->frame(function () use ($view, $hl, &$tb, &$tl, &$t0) {
            $c = new Context($hl->metrics, $hl->rt, $hl->w, $hl->h, $hl->theme); $view($c); $t1 = hrtime(true); $tb += $t1 - $t0;
            Layout::measure($c->root); Layout::arrange($c->root, 0, 0, $hl->w, $hl->h); $t0 = hrtime(true); $tl += $t0 - $t1; return $c->root;
        });
        $hl->root = $root;
        $t2 = hrtime(true); $tr += $t2 - $t0;
        $quads = $hl->paint()->n; $tp += hrtime(true) - $t2;
        if ($f === 1) { $tb = $tl = $tr = $tp = 0.0; } // the first two frames warm up
    }
    $k = 1e6 * $frames;
    printf("%-30s build %5.2f  layout %5.2f  record %5.2f  paint %5.2f  = %5.2f ms/frame  %5d quads\n", $name, $tb / $k, $tl / $k, $tr / $k, $tp / $k, ($tb + $tl + $tr + $tp) / $k, $quads);
};

$w = Headless::widgets(); $view = $w[0]; $s = &$w[1];
foreach ([0 => 'General', 1 => 'Advanced', 2 => 'About'] as $tab => $name) { $s['tab'] = $tab; $s['more'] = true; $phases("widgets $name", $view, 900, 700); }

$dir = sys_get_temp_dir() . '/myphp-bench-' . getmypid(); mkdir($dir);
for ($i = 0; $i < 2000; $i++) { file_put_contents("$dir/file-$i.txt", ''); }
[$view, $files] = Headless::files($dir);
$phases('files, 2000 entries, icons', $view, 960, 640);
$files->view = 1; $phases('files, 2000 entries, table', $view, 960, 640);
array_map('unlink', glob("$dir/*")); rmdir($dir);

$ls = new ListState; $ls->selected = -1;
$rows = function (Context $c) use ($ls) { $c->column(0, 0, 1); $c->list($ls, 1000000, function (int $i) use ($c) { $c->row(8, 8)->size(0, 32); $c->text("Row $i"); $c->muted('a subtitle'); $c->end(); }); $c->end(); };
$phases('list, a million rows', $rows, 900, 700);
$phases('list, a million rows, 1440p', $rows, 2560, 1440);
$cols = array_map(fn($i) => new TableColumn("Column $i", 120), range(0, 4)); $ts = new ListState;
$phases('table, a million rows × 5', function (Context $c) use ($ts, $cols) { $c->column(0, 0, 1); $c->table($ts, $cols, 1000000, function (int $r, int $col) use ($c) { $c->text("r$r c$col"); }); $c->end(); }, 900, 700);
$sc = new ScrollState;
$phases('2000 texts, not virtualized', function (Context $c) use ($sc) { $c->column(0, 0, 1)->scroll($sc); for ($i = 0; $i < 2000; $i++) { $c->text("Line $i of a wall of text that is fairly long"); } $c->end(); }, 900, 700);
$ta = new TextState(implode("\n", array_map(fn($i) => "Line $i: " . str_repeat('lorem ipsum ', 6), range(1, 2000)))); $ta->multiline = true;
$phases('textarea, 2000 lines', function (Context $c) use ($ta) { $c->column(0, 0, 1); $c->textarea($ta, '', 0, 0); $c->end(); }, 900, 700);

// Typing into the text area, a character a frame, at its end and at its start.
$hl = new Headless(function (Context $c) use ($ta) { $c->column(0, 0, 1); $c->textarea($ta, '', 0, 0); $c->end(); }, 900, 700);
$root = $hl->frame(); $hl->rt->focusOn($root->kids[0]->kids[0]->id); $hl->frame();
foreach (['the end' => strlen($ta->text), 'the start' => 0] as $where => $at) {
    $ta->setCursor($at); $t0 = hrtime(true);
    for ($i = 0; $i < $frames; $i++) { $hl->input->push(['key', 'x', 0, 0]); $ta->insert('x'); $hl->frame(1); $hl->paint(); }
    printf("%-30s %5.2f ms/frame typing at %s\n", 'textarea, 2000 lines', (hrtime(true) - $t0) / 1e6 / $frames, $where);
}

// The SVG renderer, pure PHP: an icon's mask at the sizes icons show at, a picture with a gradient, clip and stroke at an image's size (drawn once, then cached).
$folder = new Doc(file_get_contents(__DIR__ . '/../examples/files/icons/folder.svg'));
$art = new Doc('<svg viewBox="0 0 100 100"><defs><linearGradient id="g"><stop offset="0" stop-color="#f00"/><stop offset="1" stop-color="#00f"/></linearGradient><clipPath id="c"><circle cx="50" cy="50" r="45"/></clipPath></defs><rect width="100" height="100" fill="url(#g)" clip-path="url(#c)"/><path d="M20 50 C 30 10 70 10 80 50 S 70 90 50 80" fill="none" stroke="#fff" stroke-width="6" stroke-dasharray="8 4"/></svg>');
foreach ([['folder icon', $folder, 32], ['folder icon', $folder, 96], ['gradient, clip, dashes', $art, 256]] as [$name, $doc, $size]) {
    $n = max(1, intdiv(20, max(1, intdiv($size, 32)))); $t0 = hrtime(true);
    for ($i = 0; $i < $n; $i++) { $doc->pixels($size, $size); }
    printf("%-30s %6.2f ms to draw at %d px\n", "svg: $name", (hrtime(true) - $t0) / 1e6 / $n, $size);
}
