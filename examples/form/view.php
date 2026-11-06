<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\App;
use MyPHP\UI\{Context, Font, Key, Span, TextState, Theme};

return function (Context $c): void {
    $c->column(24, 14, 1);
      $c->text('Profile', 20);
      foreach (['name' => 'Name', 'city' => 'City（试试中文输入法）'] as $key => $label) {
          $c->row(0, 12);
            $c->column(0, 0); $c->muted($label); $c->end();
            $field = $c->input($this->{$key}, $label, 320);
            if ($c->submitted($field)) { $this->submits++; $this->greeting = "Hello, {$this->name->text} from {$this->city->text}"; }
          $c->end();
      }
      $c->row(0, 12);
        $c->column(0, 0); $c->muted('Note'); $c->end();
        $c->textarea($this->note, 'A few lines: Enter breaks, Up/Down move, Cmd+Z undoes', 320, 96); // wraps at the width
      $c->end();
      // A message field: one line that grows with what is typed, up to four, Enter sends it (the key taken before the field sees it), Shift+Enter breaks a line; the log below is read-only, selectable and copyable.
      $c->row(0, 12);
        $c->column(0, 0); $c->muted('Message'); $c->end();
        $this->draft->onTextKey = function (int $code, string $chars, int $mods, TextState $d): bool {
            if ($code !== Key::RETURN || $mods & Key::SHIFT) { return false; }
            if (trim($d->text) !== '') { $this->log->text .= ($this->log->text === '' ? '' : "\n") . trim($d->text); $this->log->setCursor(strlen($this->log->text)); }
            $d->selectAll(); $d->insert('');
            return true;
        };
        $c->textarea($this->draft, 'Enter sends, Shift+Enter breaks a line', 320, 0, null, 1, 4);
      $c->end();
      $c->row(0, 12);
        $c->column(0, 0); $c->muted('Sent'); $c->end();
        $c->textarea($this->log, 'Nothing sent yet', 320, 64);
      $c->end();
      // A strip wider than the window: scrolls sideways with the trackpad or its thumb.
      $c->row(8, 8, 0, $t = $c->theme->surface)->scroll($this->strip, horizontal: true, vertical: false);
        for ($i = 0; $i < 30; $i++) {
            $c->column(6, 2, 0, $c->theme->background); $c->text("Tag $i"); $c->muted(sprintf('#%06x', ($i * 2654435761) & 0xffffff), 11); $c->end();
        }
      $c->end();
      // Controls: a checkbox, radios, a slider and a select, each writing straight into $s.
      $c->row(0, 16);
        $c->checkbox('Subscribe', $this->subscribe);
        // The theme: fixed light or dark, or following the system (with its accent).
        $c->radio('Light', $this->mode, 'light'); $c->radio('Dark', $this->mode, 'dark'); $c->radio('System', $this->mode, 'system');
        if ($this->mode !== $this->applied) { // a radio changed it: the theme follows on the next frame
            $this->applied = $this->mode;
            App::setTheme(match ($this->mode) { 'light' => Theme::light(), 'dark' => Theme::dark(), default => null });
        }
        $c->slider($this->volume, 0, 100, 140);
        $c->column(0, 0)->size(40); $c->muted(sprintf('%.0f%%', $this->volume)); $c->end(); // a fixed width, so the number does not push the select about
        $c->select(['English', '中文', 'Español'], $this->lang);
      $c->end();
      // A focusable card: Tab reaches it, Enter or a click activates it, hover tints it; the text wraps at the card's width.
      $card = $c->column(10, 4, 0, $c->theme->surface)->focusable();
        if ($c->hovered($card)) { $card->color = $c->theme->surfaceHover; }
        if ($c->clicked($card)) { $this->submits++; $this->greeting = 'card activated'; }
        $c->richText([new Span('A card. ', style: Font::BOLD), new Span('Tab to it and press Enter, or click it. This sentence wraps at the width the window gives the card, '
            . 'so make the window narrower and watch it reflow — 中文也会在合适的地方换行。')]);
      $c->end();
      // A link, a determinate bar following the slider, an indeterminate one; the Submit button has a tip.
      $c->row(0, 12);
        $c->link('mygo on GitHub', 'https://github.com/egoist/mygo');
        $c->progress($this->volume / 100, 140)->tooltip('follows the slider');
        $c->progress(-1, 100)->tooltip('indeterminate: animates');
      $c->end();
      $c->row(0, 8);
        if ($c->clicked($c->primaryButton('Submit')->tooltip('Sends the form; Enter in a field does too'))) { $this->submits++; $this->greeting = "Hello, {$this->name->text} from {$this->city->text}"; }
        if ($c->clicked($c->button('Clear'))) { foreach (['name', 'city', 'note'] as $k) { $this->{$k}->text = ''; $this->{$k}->setCursor(0); } $this->greeting = ''; }
      $c->end();
      $c->text($this->greeting === '' ? 'Submit, or press Enter in a field.' : $this->greeting . " (#{$this->submits})", 15);
      $n = $this->name;
      $c->muted(sprintf('name: %d bytes, cursor %d, anchor %d%s', strlen($n->text), $n->cursor, $n->anchor, $n->marked !== null ? ", composing \"{$n->marked}\"" : ''), 12);
    $c->end();
};
