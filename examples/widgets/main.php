<?php
// The smaller widgets in one window: switch, segmented control, tabs with
// their content, a collapsible section, a stepper, badges, a spinner,
// dividers and spacers. Each writes into $s and reports with changed().
// Run: ./run.sh widgets
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, Context, Font};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

$s = ['wifi' => true, 'view' => 0, 'tab' => 0, 'more' => false, 'count' => 3, 'last' => 'nothing yet'];

function view(Context $c, array &$s): void
{
    $t = $c->theme;
    $c->column(20, 14, 1);
      $c->row(0, 10); $c->text('Widgets', 20, style: Font::BOLD); $c->spacer(); $c->spinner(); $c->muted('working…'); $c->end();
      $c->divider();
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
            if ($c->collapsible('More options', $s['more'])) {
                $c->column(12, 6);
                  $c->muted('Shown while the section is open; the header toggles it.');
                  $c->row(0, 8); $c->badge('new'); $c->badge('3'); $c->badge('beta'); $c->end();
                $c->end();
            }
        } elseif ($s['tab'] === 1) {
            $c->text('Advanced settings would go here.');
        } else {
            $c->rich([new \MyPHP\UI\Span('MyPHP ', style: Font::BOLD), new \MyPHP\UI\Span(App::VERSION, color: $t->textMuted)]);
        }
      $c->end();
      $c->spacer();
      $c->divider();
      $c->muted('last change: ' . $s['last'], 12);
    $c->end();
}

App::setName('Widgets');
App::whenReady(function () use (&$s) {
    new Window(new WindowOptions(title: 'MyPHP native — widgets', width: 560, height: 420,
        content: Content::view(function (Context $c) use (&$s) { view($c, $s); })));
});
App::run();
