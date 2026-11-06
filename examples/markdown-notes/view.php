<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\{Context, Font, Node};
use MyPHP\UI\Markdown\Markdown;

return function (Context $c): void {
    $t = $c->theme;
    $this->tick();
    $this->filter();
    // Three panes the dividers resize: notes | editor | preview (while previewing).
    $c->split($this->listW, function () use ($c, $t) {
        $c->column(0, 0, 1, $t->surface);
          $c->column(8, 6);
            $c->searchField($this->query, 0); // as wide as the pane
            $c->toolbar(function () use ($c) {
                if ($c->clicked($c->button('New'))) { $this->create(); }
                if ($c->clicked($c->button('Delete…')) && $this->open !== null) { $this->asking = true; }
                $c->spacer();
                $c->toggle($this->previewing, 'Preview');
            });
          $c->end();
          $c->divider();
          $c->list($this->list, count($this->shown), function (int $row) use ($c, $t) {
              $it = $this->items[$this->shown[$row]];
              $c->column(10, 2); $c->text($it['title'], 13, style: $it['path'] === $this->open ? Font::BOLD : 0)->singleLine();
                $c->muted(date('M j, H:i', $it['mtime']), 11); $c->end();
          });
          if ($this->shown === [] && $this->query->text !== '') { $c->column(10, 0); $c->muted('No note matches.', 12); $c->end(); }
          if ($this->list->changed() && $this->list->selected >= 0 && $this->items[$this->shown[$this->list->selected]]['path'] !== $this->open) {
              $this->choose($this->items[$this->shown[$this->list->selected]]['path']);
          }
        $c->end();
    }, function () use ($c, $t) {
        $editor = function () use ($c, $t) {
            $c->column(0, 0, 1);
              if ($this->open === null) {
                  $c->column(20, 8); $c->muted('Choose a note, or make one with New (Cmd+N).'); $c->end();
              } else {
                  $c->textarea($this->editor, 'Write Markdown…', 0, 0, 14); // 0, 0: fills the pane
                  $c->row(8, 8)->alignItems(Node::CENTER);
                    $c->muted(sprintf('%d words · edited %s', $this->words(), date('M j, H:i', $this->edited())), 12); $c->spacer();
                    $c->badge($this->editor->text === $this->savedText() ? 'saved' : 'editing');
                  $c->end();
              }
            $c->end();
        };
        if (!$this->previewing) { $editor(); return; }
        $c->split($this->editorW, $editor, function () use ($c, $t) {
            // The preview follows the editor: scrolled the same share of the way through.
            if ($this->editor->scrollFraction !== $this->followed) {
                $this->followed = $this->editor->scrollFraction;
                $this->preview->scrollTo($this->followed * $this->preview->maxY, false);
            }
            $c->column(16, 10, 1, $t->background)->scroll($this->preview);
              Markdown::view($c, $this->editor->text, $this->md);
            $c->end();
        });
    });
    // Deleting asks first.
    $c->modal($this->asking, function () use ($c) {
        $c->text('Delete this note?', 15, style: Font::BOLD);
        $c->muted(basename((string)$this->open) . " goes to nowhere: there is no undo.");
        $c->row(0, 8)->justify(Node::END);
          if ($c->clicked($c->button('Cancel'))) { $this->asking = false; }
          if ($c->clicked($c->primaryButton('Delete'))) { $this->asking = false; $this->delete(); $c->toast('Note deleted'); }
        $c->end();
    });
};
