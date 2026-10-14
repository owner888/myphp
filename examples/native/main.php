<?php
// A scrolling inbox in native UI: the view is a function of $state, rebuilt
// on every frame, and frames happen when something changes.
// Run: ./run.sh native [rows]   (MYPHP_STATS=1 prints per-second frame stats; try 1000000 rows)
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, Context, ListState};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

$state = ['rows' => (int)($argv[1] ?? 300), 'replied' => [], 'list' => new ListState, 'last' => ''];

function inbox(Context $c, array &$s): void
{
    $c->column(0, 0, 1);
      $c->row(12, 8, 0, 0xeeeeee);
        $c->text('Inbox', 20);
        if ($c->clicked($c->button('Compose'))) { $s['rows']++; $s['last'] = "composed row {$s['rows']}"; }
        if ($c->clicked($c->button('Refresh'))) { $s['replied'] = []; $s['last'] = 'refreshed'; }
        $c->text($s['last'], 13, 0x888888);
      $c->end();
      $c->list($s['list'], $s['rows'], function (int $i) use ($c, &$s) { // only the rows in view are built
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
