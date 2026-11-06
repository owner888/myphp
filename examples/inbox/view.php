<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\{Node, Context, Font, Span};

return function (Context $c): void {
    $t = $c->theme; // light or dark after the system, accent from its settings
    $c->row(0, 0, 1)->alignItems(Node::STRETCH);
    // Mailboxes: a sidebar, as Mail's; the arrows choose while it has the focus.
    $side = $c->sidebar($this->mailbox, function () use ($c) {
        $c->sidebarSection('Favorites', $this->favorites, function () use ($c) {
            $c->sidebarItem('inbox', 'Inbox', '✉', fn() => $c->badge(number_format($this->rows)));
            $c->sidebarItem('flagged', 'Flagged', '⚑');
            $c->sidebarItem('drafts', 'Drafts', '✎');
        });
        $c->sidebarSection('On My Mac', $this->local, function () use ($c) {
            $c->sidebarItem('archive', 'Archive', '▤');
            $c->sidebarItem('trash', 'Trash', '✕');
        });
    }, 180);
    if ($c->changed($side)) { $this->last = "mailbox {$this->mailbox}"; }
    $c->column(0, 0, 1);
      $c->row(12, 8, 0, $t->surface);
        $c->richText([new Span('Inbox ', 20, Font::BOLD), new Span(number_format($this->rows) . ' messages', color: $t->textMuted),
            new Span('  every third unread', style: Font::ITALIC, color: $t->textMuted)]);
        if ($c->clicked($c->primaryButton('Compose'))) { $this->rows++; $this->last = "composed row {$this->rows}"; }
        if ($c->clicked($c->button('Refresh'))) { $this->replied = []; $this->last = 'refreshed'; }
        if ($c->clicked($c->button('Top'))) { $this->list->scrollTo(0); }           // glides there
        if ($c->clicked($c->button('End'))) { $this->list->scrollTo($this->rows - 1); }
        $c->muted($this->last);
      $c->end();
      if ($this->list->changed()) { $n = $this->list->selection->count(); $this->last = $n === 1 ? "chose row {$this->list->selected}" : "chose $n rows"; }
      if ($this->list->submitted()) { $this->last = "opened row {$this->list->selected}"; }
      $c->list($this->list, $this->rows, function (int $i) use ($c, $t) { // only the rows in view are built; each row's nodes are keyed by $i
            $c->row(10, 12, 0, ($i & 1) ? $t->surface : $t->background);
              $c->image($this->avatars[$i % count($this->avatars)], 36, 36, 8); // an image quad from the atlas
              $c->column(0, 2, 1);
                $unread = $i % 3 === 0 ? Font::BOLD : 0; // bold, as unread mail is
                $c->row(0, 6); $c->text("Sender $i", 14, style: $unread); $c->text("主题 {$i}：Subject line number $i is here", 14, style: $unread); $c->end();
                $c->muted("Preview of the message body, truncated to one line of text $i", 12);
              $c->end();
              $c->text(sprintf('%02d:%02d', $i % 24, ($i * 7) % 60), 12, $t->textMuted, Font::MONO);
              $b = $c->button(isset($this->replied[$i]) ? 'Replied' : 'Reply');
              if (isset($this->replied[$i])) { $b->color = $t->success; $b->fg = $t->accentText; $b->bordered = false; }
              if ($c->clicked($b)) { $this->replied[$i] = true; $this->last = "replied to $i"; }
            $c->end();
      });
    $c->end();
    $c->end();
};
