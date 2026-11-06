<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\{Context, Font, Key, Span};

return function (Context $c): void {
    $t = $c->theme;
    $this->refilter();
    $c->column(0, 0, 1);
      // The find bar: Cmd+F opens it; Enter, the arrows and Cmd+G step through the shown lines holding the query, choosing each.
      if ($c->shortcut('f', Key::COMMAND)) { $this->finding = true; }
      $hits = [];
      if ($this->finding && $this->find->text !== '') {
          foreach ($this->shown as $r => $i) { if (stripos($this->lines[$i], $this->find->text) !== false) { $hits[] = $r; } }
      }
      $bar = $c->findBar($this->finding, $this->find, count($hits), $this->found);
      if ($bar !== null && $hits !== [] && ($c->changed($bar) || $this->find->seen !== $this->find->text)) {
          $this->list->choose($hits[$this->found]); $this->list->scrollTo($hits[$this->found]);
      }
      $this->find->seen = $this->find->text;
      $c->row(10, 10, 0, $t->surface);
        $c->input($this->filter, 'Filter lines (type to search)', 320);
        $c->richText($this->path === ''
            ? [new Span('Open a log with ', color: $t->textMuted), new Span('File > Open…', style: Font::BOLD, color: $t->textMuted)]
            : [new Span(number_format(count($this->lines)) . ' lines', color: $t->textMuted),
               new Span($this->filter->text === '' ? '' : ', ' . number_format(count($this->shown)) . ' match', color: $t->textMuted),
               new Span('  ERROR ' . $this->counts['ERROR'], color: $t->danger, style: Font::BOLD),
               new Span('  WARN ' . $this->counts['WARN'], color: $t->warning, style: Font::BOLD)]);
      $c->end();
      $needle = $this->filter->text;
      // A table: the number column and the line, which wraps at the column's width; the header's edge resizes it.
      $c->table($this->list, $this->columns, count($this->shown), function (int $r, int $col) use ($c, $t, $needle) {
          $i = $this->shown[$r];
          if ($col === 0) {
              $c->text((string)($i + 1), 12, $t->textMuted, Font::MONO);
              return;
          }
          $line = $this->lines[$i];
          $color = str_contains($line, 'ERROR') ? $t->danger : (str_contains($line, 'WARN') ? $t->warning : $t->text);
          $spans = []; // the matched part highlighted
          if ($needle !== '' && ($at = stripos($line, $needle)) !== false) {
              $spans[] = new Span(substr($line, 0, $at), style: Font::MONO, color: $color);
              $spans[] = new Span(substr($line, $at, strlen($needle)), style: Font::MONO, color: 0x1d1d1f, background: $t->warning);
              $spans[] = new Span(substr($line, $at + strlen($needle)), style: Font::MONO, color: $color);
          } else {
              $spans[] = new Span($line, style: Font::MONO, color: $color);
          }
          $c->richText($spans, 12); // wrapping at the width of the cell
      });
    $c->end();
};
