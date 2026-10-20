<?php
// A scrolling inbox in native UI: the view is a function of $state, rebuilt
// on every frame, and frames happen when something changes. Hover the list
// for its scroll bar (drag the thumb); click a row to choose it, Cmd-click
// or Shift-click several.
// Run: ./run.sh native [rows]   (MYPHP_STATS=1 prints per-second frame stats; try 1000000 rows)
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Node, Content, Context, Font, Image, ListState, Selection, Span};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

$state = ['rows' => (int)($argv[1] ?? 300), 'replied' => [], 'list' => new ListState, 'last' => '', 'mailbox' => 'inbox', 'favorites' => true, 'local' => true];
$state['list']->selected = -1; // rows can be chosen: click, Up/Down, Home/End; double click or Enter opens
$state['list']->selection = new Selection; // several: Cmd-click adds or takes out, Shift-click and Shift+arrows extend, Cmd+A chooses all
$state['list']->followEnd = true; // at the end, Compose (a new last row) keeps the end in view, as a chat does
// Avatars: a few system app icons (ICNS, decoded by ImageIO at 64 px), made once and kept. Apps whose
// icon lives in Assets.car ship a tiny, fully transparent icns as a placeholder: those are skipped by size.
$icons = array_filter(glob('/System/Applications/*.app/Contents/Resources/AppIcon.icns'), fn($p) => filesize($p) > 20000);
$state['avatars'] = array_map(fn($p) => Image::fromFile($p, 64), array_slice(array_values($icons), 0, 12));
$state['avatars'] !== [] || throw new RuntimeException('no app icons under /System/Applications');

function inbox(Context $c, array &$s): void
{
    $t = $c->theme; // light or dark after the system, accent from its settings
    $c->row(0, 0, 1)->align(Node::STRETCH);
    // Mailboxes: a sidebar, as Mail's; the arrows choose while it has the focus.
    $side = $c->sidebar($s['mailbox'], function () use ($c, &$s) {
        $c->sidebarSection('Favorites', $s['favorites'], function () use ($c, &$s) {
            $c->sidebarItem('inbox', 'Inbox', '✉', fn() => $c->badge(number_format($s['rows'])));
            $c->sidebarItem('flagged', 'Flagged', '⚑');
            $c->sidebarItem('drafts', 'Drafts', '✎');
        });
        $c->sidebarSection('On My Mac', $s['local'], function () use ($c) {
            $c->sidebarItem('archive', 'Archive', '▤');
            $c->sidebarItem('trash', 'Trash', '✕');
        });
    }, 180);
    if ($c->changed($side)) { $s['last'] = "mailbox {$s['mailbox']}"; }
    $c->column(0, 0, 1);
      $c->row(12, 8, 0, $t->surface);
        $c->richText([new Span('Inbox ', 20, Font::BOLD), new Span(number_format($s['rows']) . ' messages', color: $t->textMuted),
            new Span('  every third unread', style: Font::ITALIC, color: $t->textMuted)]);
        if ($c->clicked($c->primaryButton('Compose'))) { $s['rows']++; $s['last'] = "composed row {$s['rows']}"; }
        if ($c->clicked($c->button('Refresh'))) { $s['replied'] = []; $s['last'] = 'refreshed'; }
        if ($c->clicked($c->button('Top'))) { $s['list']->scrollTo(0); }           // glides there
        if ($c->clicked($c->button('End'))) { $s['list']->scrollTo($s['rows'] - 1); }
        $c->muted($s['last']);
      $c->end();
      if ($s['list']->changed()) { $n = $s['list']->selection->count(); $s['last'] = $n === 1 ? "chose row {$s['list']->selected}" : "chose $n rows"; }
      if ($s['list']->submitted()) { $s['last'] = "opened row {$s['list']->selected}"; }
      $c->list($s['list'], $s['rows'], function (int $i) use ($c, $t, &$s) { // only the rows in view are built; each row's nodes are keyed by $i
            $c->row(10, 12, 0, ($i & 1) ? $t->surface : $t->background);
              $c->image($s['avatars'][$i % count($s['avatars'])], 36, 36, 8); // an image quad from the atlas
              $c->column(0, 2, 1);
                $unread = $i % 3 === 0 ? Font::BOLD : 0; // bold, as unread mail is
                $c->row(0, 6); $c->text("Sender $i", 14, style: $unread); $c->text("主题 {$i}：Subject line number $i is here", 14, style: $unread); $c->end();
                $c->muted("Preview of the message body, truncated to one line of text $i", 12);
              $c->end();
              $c->text(sprintf('%02d:%02d', $i % 24, ($i * 7) % 60), 12, $t->textMuted, Font::MONO);
              $b = $c->button(isset($s['replied'][$i]) ? 'Replied' : 'Reply');
              if (isset($s['replied'][$i])) { $b->color = $t->success; $b->fg = $t->accentText; $b->bordered = false; }
              if ($c->clicked($b)) { $s['replied'][$i] = true; $s['last'] = "replied to $i"; }
            $c->end();
      });
    $c->end();
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
