<?php
// Flyouts and panels: a menu that opens under its button, takes the
// keyboard and closes on a press outside it, on its button or on Escape;
// a hover card that never takes the keyboard; a flyout of a tray icon in
// the menu bar; a panel above every app, which leaves the app the user is
// in active and closes on Escape.
// Run: ./run.sh flyout
declare(strict_types=1);

use MyPHP\{App, FlyoutOptions, Rectangle, Tray, TrayOptions, Window, WindowOptions};
use MyPHP\UI\{Content, Context, Font};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

$s = ['menu' => null, 'card' => null, 'chosen' => '—', 'placement' => FlyoutOptions::BOTTOM_START, 'panel' => null, 'trayFlyout' => null];

/** A flyout's content: a rounded card on the theme's background, as a flyout draws all of itself. */
function card(Context $c, \Closure $fn): void
{
    $c->column(12, 6, 1, $c->theme->background)->round(10)->border($c->theme->border);
      $fn($c);
    $c->end();
}

/** The box of $n as an anchor, in points from the top-left of the window's content. */
function anchorOf(Context $c, $n): Rectangle
{
    [$x, $y, $w, $h] = $c->bounds($n);
    return new Rectangle((int)round($x), (int)round($y), (int)round($w), (int)round($h));
}

/** $w is null in the frame the window's creation draws. */
function view(Context $c, array &$s, ?Window $w): void
{
    $c->column(24, 14, 1);
      $c->text('Flyouts', 22, style: Font::BOLD);
      $c->row(0, 8);
        foreach ([FlyoutOptions::BOTTOM_START, FlyoutOptions::BOTTOM, FlyoutOptions::RIGHT_START, FlyoutOptions::TOP_END] as $p) {
          if ($c->clicked($c->button($p === $s['placement'] ? "• $p" : $p))) { $s['placement'] = $p; }
        }
      $c->end();
      $menu = $c->button('Open the menu');
      if ($c->clicked($menu) && $w !== null && $s['menu'] === null) {
          $s['menu'] = Window::flyout(new FlyoutOptions(parent: $w, anchor: anchorOf($c, $menu), placement: $s['placement'], gap: 4, width: 220, height: 168, focusable: true, shadow: true,
              content: Content::view(function (Context $c) use (&$s) {
                  card($c, function (Context $c) use (&$s) {
                      foreach (['Cut', 'Copy', 'Paste', 'Select all'] as $item) {
                          if ($c->clicked($c->button($item))) { $s['chosen'] = $item; $s['menu']?->close(); }
                      }
                  });
              })));
          $s['menu']->on('closed', function () use (&$s, $w) { $s['menu'] = null; $w->invalidate(); });
      }
      $c->muted("chosen: {$s['chosen']}");
      $hover = $c->text('Hover here for a card', 15);
      if ($c->hovered($hover) && $w !== null && $s['card'] === null) {
          $s['card'] = Window::flyout(new FlyoutOptions(parent: $w, anchor: anchorOf($c, $hover), placement: FlyoutOptions::RIGHT, gap: 8, width: 200, height: 70,
              content: Content::view(function (Context $c) { card($c, function (Context $c) { $c->muted('A hover card: the keyboard stays in the window.', 12); }); })));
      } elseif (!$c->hovered($hover) && $s['card'] !== null) {
          $s['card']->destroy();
          $s['card'] = null;
      }
      if ($c->clicked($c->button('Open a panel')) && $s['panel'] === null) {
          $s['panel'] = new Window(new WindowOptions(title: 'Panel', width: 420, height: 120, panel: true, frameless: true,
              content: Content::view(function (Context $c) {
                  $c->column(16, 8, 1);
                    $c->text('A panel above every app', 16, style: Font::BOLD);
                    $c->muted('Switch apps: it stays, and the app you were in stays active. Escape closes it.', 12);
                  $c->end();
              })));
          $s['panel']->on('closed', function () use (&$s) { $s['panel'] = null; });
      }
      $c->muted('The tray icon "Fly" opens a flyout under it.', 12);
    $c->end();
}

App::setName('Flyout');
App::whenReady(function () use (&$s) {
    $w = new Window(new WindowOptions(title: 'MyPHP native — Flyouts', width: 640, height: 400,
        content: Content::view(function (Context $c) use (&$s, &$w) { view($c, $s, $w); })));
    $tray = new Tray(new TrayOptions(title: 'Fly'));
    $tray->onClick(function () use (&$s, $tray) {
        if ($s['trayFlyout'] !== null) {
            $s['trayFlyout']->close();
            return;
        }
        $s['trayFlyout'] = Window::flyout(new FlyoutOptions(tray: $tray, placement: FlyoutOptions::BOTTOM, gap: 2, width: 260, height: 120, focusable: true, shadow: true,
            content: Content::view(function (Context $c) { card($c, function (Context $c) { $c->text('A menu bar flyout', 15, style: Font::BOLD); $c->muted('Press outside or Escape to close.', 12); }); })));
        $s['trayFlyout']->on('closed', function () use (&$s) { $s['trayFlyout'] = null; });
    });
});
App::run();
