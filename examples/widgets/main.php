<?php
// The smaller widgets in one window: switch, segmented control, tabs with
// their content, a collapsible section, a stepper, badges, a spinner,
// dividers and spacers. Each writes into $s and reports with changed().
// Run: ./run.sh widgets
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, Context, Font, Node, TextState};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

$s = ['wifi' => true, 'view' => 0, 'tab' => 0, 'more' => false, 'count' => 3, 'asking' => false, 'options' => false, 'bold' => false, 'sort' => 'Name', 'query' => new TextState, 'open' => [], 'chosen' => '', 'scale' => 1.0, 'italic' => false, 'last' => 'nothing yet'];

function view(Context $c, array &$s): void
{
    $t = $c->theme;
    $c->column(20, 14, 1);
      $c->row(0, 10);
        $c->text('Widgets', 20, style: Font::BOLD);
        if ($c->changed($c->searchField($s['query'], 180))) { $s['last'] = 'search ' . json_encode($s['query']->text); }
        $c->spacer(); $c->spinner(); $c->muted('working…');
      $c->end();
      $c->divider();
      // A toolbar: toggles and buttons without a face until hovered.
      $c->toolbar(function () use ($c, &$s) {
          if ($c->changed($c->toggle($s['bold'], 'Bold'))) { $s['last'] = 'bold ' . ($s['bold'] ? 'on' : 'off'); }
          $c->toggle($s['italic'], 'Italic');
          $c->divider(true);
          if ($c->clicked($c->button('Share'))) { $c->toast('Shared'); }
          $c->menuButton('View', ['Icons' => fn() => $s['last'] = 'icons', 'List' => fn() => $s['last'] = 'list']);
      });
      $c->row(0, 16);
        if ($c->changed($c->switch('Wi-Fi', $s['wifi']))) { $s['last'] = 'Wi-Fi ' . ($s['wifi'] ? 'on' : 'off'); }
        $c->badge($s['wifi'] ? 'connected' : 'off');
        $c->spacer();
        if ($c->changed($c->segmented(['List', 'Grid', 'Map'], $s['view']))) { $s['last'] = 'view ' . $s['view']; }
      $c->end();
      $c->divider();
      if ($c->changed($c->tabs(['General', 'Advanced', 'About'], $s['tab']))) { $s['last'] = 'tab ' . $s['tab']; }
      $c->column(12, 10, 0, $t->surface)->round($t->radius);
        if ($s['tab'] === 0) {
            $c->row(0, 10); $c->text('Copies'); $c->text((string)$s['count']); if ($c->changed($c->stepper($s['count'], 1, 10))) { $s['last'] = "count {$s['count']}"; } $c->end();
            $c->row(0, 10); $c->text('Scale'); if ($c->changed($c->numberInput($s['scale'], 0.5, 4, 0.25))) { $s['last'] = "scale {$s['scale']}"; } $c->end();
            $c->row(0, 10); $c->text('Disk'); $c->meter($s['count'] * 10, 0, 100, 80, 95); $c->muted("{$s['count']}0 % used"); $c->end();
            if ($c->collapsible('More options', $s['more'])) {
                $c->column(12, 6);
                  $c->muted('Shown while the section is open; the header toggles it.');
                  $c->row(0, 8); $c->badge('new'); $c->badge('3'); $c->badge('beta'); $c->end();
                $c->end();
            }
        } elseif ($s['tab'] === 1) {
            // A tree: the arrows open and close, Up/Down/Left/Right move the focus, a click chooses.
            $c->tree(function () use ($c, &$s) {
                $item = function (string $path, ?Closure $kids = null) use ($c, &$s, &$item) {
                    $name = basename($path);
                    if ($kids === null) { $leaf = null; $n = $c->treeItem($name, $leaf, null, $s['chosen'] === $path); }
                    else { $s['open'][$path] ??= $path === 'src'; $n = $c->treeItem($name, $s['open'][$path], $kids, $s['chosen'] === $path); }
                    if ($c->clicked($n)) { $s['chosen'] = $path; $s['last'] = "chose $path"; }
                };
                $item('src', function () use ($item) { $item('src/App.php'); $item('src/UI', function () use ($item) { $item('src/UI/Context.php'); $item('src/UI/Node.php'); }); });
                $item('examples', function () use ($item) { $item('examples/widgets'); });
                $item('README.md');
            });
        } else {
            $c->rich([new \MyPHP\UI\Span('MyPHP ', style: Font::BOLD), new \MyPHP\UI\Span(App::VERSION, color: $t->textMuted)]);
        }
      $c->end();
      $c->row(0, 8);
        if ($c->clicked($c->primaryButton('Save'))) { $c->toast('Saved'); $s['last'] = 'saved'; }
        if ($c->clicked($c->button('Delete…'))) { $s['asking'] = true; }
        $c->menuButton('Sort by', ['Name' => function () use (&$s) { $s['sort'] = 'Name'; $s['last'] = 'sort by name'; }, 'Date' => function () use (&$s) { $s['sort'] = 'Date'; $s['last'] = 'sort by date'; }]);
        $anchor = $c->button('Options');
        if ($c->clicked($anchor)) { $s['options'] = !$s['options']; }
      $c->end();
      // A popover below the Options button: a press elsewhere closes it.
      $c->popover($anchor, $s['options'], function () use ($c, &$s) {
          $c->column(8, 8);
            $c->checkbox('Bold titles', $s['bold']);
            $c->muted('Sorted by ' . $s['sort'], 11);
          $c->end();
      });
      $c->spacer();
      $c->divider();
      $c->muted('last change: ' . $s['last'], 12);
    $c->end();
    // A modal over it all: what is behind is inert until it closes.
    $modal = $c->modal($s['asking'], function () use ($c, &$s) {
        $c->text('Delete the draft?', 15, style: Font::BOLD);
        $c->muted("You can't undo this.");
        $c->row(0, 8)->justify(Node::END);
          if ($c->clicked($c->button('Cancel'))) { $s['asking'] = false; }
          if ($c->clicked($c->primaryButton('Delete'))) { $s['asking'] = false; $c->toast('Draft deleted'); $s['last'] = 'deleted'; }
        $c->end();
    });
    if ($modal !== null && $c->changed($modal)) { $s['last'] = 'dismissed'; }
}

App::setName('Widgets');
App::whenReady(function () use (&$s) {
    new Window(new WindowOptions(title: 'MyPHP native — widgets', width: 560, height: 420,
        content: Content::view(function (Context $c) use (&$s) { view($c, $s); })));
});
App::run();
