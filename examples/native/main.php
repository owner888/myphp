<?php
// A scrolling inbox in native UI: the view is a function of $state, rebuilt
// on every frame, and frames happen when something changes. Hover the list
// for its scroll bar (drag the thumb); click a row to choose it.
// Run: ./run.sh native [rows]   (MYPHP_STATS=1 prints per-second frame stats; try 1000000 rows)
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, Context, Image, ListState};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

$state = ['rows' => (int)($argv[1] ?? 300), 'replied' => [], 'list' => new ListState, 'last' => ''];
$state['list']->selected = -1; // rows can be chosen: click, Up/Down, Home/End; double click or Enter opens
// Avatars: a few system app icons (ICNS, decoded by ImageIO at 64 px), made once and kept.
$state['avatars'] = array_map(fn($p) => Image::fromFile($p, 64), array_slice(glob('/System/Applications/*.app/Contents/Resources/AppIcon.icns'), 0, 12));
$state['avatars'] !== [] || throw new RuntimeException('no app icons under /System/Applications');

function inbox(Context $c, array &$s): void
{
    $t = $c->theme; // light or dark after the system, accent from its settings
    $c->column(0, 0, 1);
      $c->row(12, 8, 0, $t->surface);
        $c->text('Inbox', 20);
        if ($c->clicked($c->button('Compose'))) { $s['rows']++; $s['last'] = "composed row {$s['rows']}"; }
        if ($c->clicked($c->button('Refresh'))) { $s['replied'] = []; $s['last'] = 'refreshed'; }
        if ($c->clicked($c->button('Top'))) { $s['list']->scrollTo(0); }           // glides there
        if ($c->clicked($c->button('End'))) { $s['list']->scrollTo($s['rows'] - 1); }
        $c->muted($s['last']);
      $c->end();
      if ($s['list']->changed()) { $s['last'] = "chose row {$s['list']->selected}"; }
      if ($s['list']->submitted()) { $s['last'] = "opened row {$s['list']->selected}"; }
      $c->list($s['list'], $s['rows'], function (int $i) use ($c, $t, &$s) { // only the rows in view are built; each row's nodes are keyed by $i
            $c->row(10, 12, 0, ($i & 1) ? $t->surface : $t->background);
              $c->image($s['avatars'][$i % count($s['avatars'])], 36, 36, 8); // an image quad from the atlas
              $c->column(0, 2, 1);
                $c->row(0, 6); $c->text("Sender $i", 14); $c->text("主题 {$i}：Subject line number $i is here", 14); $c->end();
                $c->muted("Preview of the message body, truncated to one line of text $i", 12);
              $c->end();
              $c->muted(sprintf('%02d:%02d', $i % 24, ($i * 7) % 60), 12);
              $b = $c->button(isset($s['replied'][$i]) ? 'Replied' : 'Reply');
              if (isset($s['replied'][$i])) { $b->color = $t->success; }
              if ($c->clicked($b)) { $s['replied'][$i] = true; $s['last'] = "replied to $i"; }
            $c->end();
      });
    $c->end();
}

App::setName('Inbox');
App::whenReady(function () use (&$state) {
    new Window(new WindowOptions(
        title: 'MyPHP native — scroll, click, hover',
        width: 900,
        height: 640,
        content: Content::view(function (Context $c) use (&$state) { inbox($c, $state); }),
    ));
});
App::run();
