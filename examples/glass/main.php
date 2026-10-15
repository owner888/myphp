<?php
// Liquid Glass in native UI: a photo and a strip of colors scroll under a
// glass toolbar and a glass pill that a slider moves, so the blur, the
// bend near the edge and the lit rim show; Regular or Clear, tinted or not,
// and an interactive glass button that grows while pressed. A hard scroll
// edge at the bottom (macOS's, under a bar of controls) and a fading blur
// band in the middle use the same backdrop.
// Run: ./run.sh glass
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Blur, Content, Context, Font, Glass, Image, ScrollEdge, ScrollState, Span};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

$pictures = array_values(array_filter(glob('/System/Library/Desktop Pictures/*.heic') ?: [], fn($p) => filesize($p) < 30_000_000));
$pictures !== [] || throw new RuntimeException('no pictures under /System/Library/Desktop Pictures');
$s = ['photo' => Image::fromFile($pictures[0], 1600), 'x' => 40.0, 'clear' => false, 'tinted' => false, 'scroll' => new ScrollState, 'presses' => 0, 'hard' => true];

function view(Context $c, array &$s): void
{
    $t = $c->theme;
    $c->column(0, 0, 1);
      // What is under the glass: the photo filling the width, then a strip of colors, all scrolling.
      $c->column(0, 12, 1)->scroll($s['scroll']);
        $c->image($s['photo'], $c->width, 0);
        $c->row(16, 10);
          for ($i = 0; $i < 40; $i++) { $c->box(48, 48, ($i * 2654435761) & 0xffffff); }
        $c->end();
        $c->column(16, 8);
          for ($i = 0; $i < 12; $i++) { $c->text("Line $i under the glass — 玻璃下面的文字", 16); }
        $c->end();
      $c->end();
      // The toolbar: a glass row floating over the content.
      $glass = fn() => new Glass($s['clear'] ? Glass::CLEAR : Glass::REGULAR, $s['tinted'] ? $t->accent : null, 0.5);
      $c->row(10, 14)->at(16, 16)->round(22)->material($glass());
        $c->text('Liquid Glass', 15, style: Font::BOLD);
        $c->checkbox('Clear', $s['clear']);
        $c->checkbox('Tinted', $s['tinted']);
        $c->slider($s['x'], 0, $c->width - 260, 160)->tooltip('moves the pill');
        $press = $c->row(6, 0)->round(14)->material(new Glass(interactive: true))->focusable();
          $c->text('Press me', 13);
        $c->end();
        if ($c->clicked($press)) { $s['presses']++; }
        $c->muted("pressed {$s['presses']}", 12);
      $c->end();
      // A pill the slider moves across the photo: the lens bends what shows near its edge.
      $c->row(14, 8)->at($s['x'], 120)->round(28)->material($glass());
        $c->rich([new Span('glass ', style: Font::BOLD), new Span('over the photo')], 14);
      $c->end();
      // A blur fading from full at its top to nothing at its bottom (a progressive blur), across the window.
      $c->column(12, 0)->at(0, 300)->size($c->width, 90)->material(new Blur(10, fade: true));
        $c->text('Blur, fading downward', 13, style: Font::BOLD);
      $c->end();
      // The scroll edge at the bottom: a bar of controls stands out from what scrolls under it.
      $c->row(16, 12)->at(0, $c->height - 64)->size($c->width, 64)->material(new ScrollEdge(hard: $s['hard'], bottom: true));
        $c->checkbox('Hard scroll edge', $s['hard']);
        $c->muted('scroll the content: it blurs and fades under this bar', 12);
      $c->end();
    $c->end();
}

App::setName('Glass');
App::whenReady(function () use (&$s) {
    new Window(new WindowOptions(title: 'MyPHP native — Liquid Glass', width: 900, height: 620,
        content: Content::view(function (Context $c) use (&$s) { view($c, $s); })));
});
App::run();
