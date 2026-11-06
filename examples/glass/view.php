<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\Plugins\Glass\{Blur, Glass, ScrollEdge, Style};
use MyPHP\UI\{Context, Font, LinearGradient, Span};

return function (Context $c): void {
    $t = $c->theme;
    $c->column(0, 0, 1);
      // What is under the glass: the photo filling the width, then a strip of colors, all scrolling.
      $c->column(0, 12, 1)->scroll($this->scroll);
        $c->image($this->photo, $c->width, 0);
        $c->row(16, 10);
          for ($i = 0; $i < 40; $i++) { $c->box(48, 48, ($i * 2654435761) & 0xffffff)->radius(18); }
        $c->end();
        $c->column(16, 8);
          for ($i = 0; $i < 12; $i++) { $c->text("Line $i under the glass — 玻璃下面的文字", 16); }
        $c->end();
      $c->end();
      // The toolbar: a glass row floating over the content.
      $glass = function () use ($t) { return new Glass($this->clear ? Style::Clear : Style::Regular, $this->tinted ? $t->accent : null, 0.5); }; // made when drawn: the checkboxes below change the state in this frame
      $c->row(10, 14)->at(16, 16)->radius(22)->material($glass());
        $c->text('Liquid Glass', 15, style: Font::BOLD);
        $c->checkbox('Clear', $this->clear);
        $c->checkbox('Tinted', $this->tinted);
        $c->slider($this->x, 0, $c->width - 260, 160)->tooltip('moves the pill');
        $press = $c->row(6, 0)->radius(14)->material(new Glass(interactive: true))->focusable();
          $c->text('Press me', 13);
        $c->end();
        if ($c->clicked($press)) { $this->presses++; }
        $c->muted("pressed {$this->presses}", 12);
      $c->end();
      // A pill the slider moves across the photo: the lens bends what shows near its edge.
      $c->row(14, 8)->at($this->x, 120)->radius(28)->material($glass());
        $c->richText([new Span('glass ', style: Font::BOLD), new Span('over the photo')], 14);
      $c->end();
      // A blur fading from full at its top to nothing at its bottom (a progressive blur, in levels), across the window.
      $c->column(12, 0)->at(0, 300)->size($c->width, 90)->material(new Blur(10, new LinearGradient(0x000000, 1, 0x000000, 0, 180)));
        $c->text('Blur, fading downward', 13, style: Font::BOLD);
      $c->end();
      // The scroll edge at the bottom: a bar of controls stands out from what scrolls under it.
      $c->row(16, 12)->at(0, $c->height - 64)->size($c->width, 64)->material(new ScrollEdge(hard: $this->hard, bottom: true));
        $c->checkbox('Hard scroll edge', $this->hard);
        $c->muted('scroll the content: it blurs and fades under this bar', 12);
      $c->end();
    $c->end();
};
