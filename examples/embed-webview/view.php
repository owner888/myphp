<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\App;
use MyPHP\UI\{Context, Font, Node};

return function (Context $c): void {
    $t = $c->theme;
    if ($this->tabs === []) { return; } // the web views come once the window shows
    $shown = $this->tabs[0];
    foreach ($this->tabs as $tb) { if ($tb->id === $this->tab) { $shown = $tb; } }
    $page = $shown->view->page();
    $c->row(0, 0, 1)->alignItems(Node::STRETCH);
      $c->sidebar($this->tab, function () use ($c) {
          foreach ($this->tabs as $tb) { $c->sidebarItem($tb->id, $tb->title); }
      }, 180);
      $c->column(0, 0, 1)->minWidth(0)->minHeight(0);
        $c->row(0, 8)->padding(8, 12)->alignItems(Node::CENTER);
          if ($c->clicked($c->button('‹')->label('Back')->disabled(!$page->canGoBack()))) { $page->goBack(); }
          if ($c->clicked($c->button('›')->label('Forward')->disabled(!$page->canGoForward()))) { $page->goForward(); }
          if ($c->clicked($c->button('Reload'))) { $page->reload(); }
          $menu = $c->button('Menu ▾');
          if ($c->clicked($menu)) { $this->menu = !$this->menu; }
          // The popover shows over the page.
          $c->popover($menu, $this->menu, function () use ($c, $t) {
              foreach (MENU as $item) {
                  $entry = $c->key($item)->row(0, 0)->padding(6, 12)->radius(5)->size(200, 0);
                  if ($c->hovered($entry)) { $entry->background($t->accent); }
                    $c->text($item, color: $c->hovered($entry) ? $t->accentText : $t->text);
                  $c->end();
                  if ($c->clicked($entry)) {
                      $this->menu = false;
                      match ($item) {
                          'Like the note' => App::later(fn() => (new Notes($this))->like()),
                          'Open the dialog' => $this->dialog = true,
                          default => $c->toast('Toasts show over the page too'),
                      };
                  }
              }
          });
          if ($c->changed($c->select(array_keys(ZOOMS), $this->zoom, 100))) {
              foreach ($this->tabs as $tb) { $tb->view->page()->setZoomFactor(array_values(ZOOMS)[$this->zoom]); }
          }
          $c->button('?')->label('Help')->tooltip('Web views show under what MyPHP paints after them.');
          $c->spacer();
          $c->muted("{$this->likes} likes");
        $c->end();
        // The page of the tab shown, rounded, with a badge over its corner; the others keep their pages.
        $c->column(0, 0, 1)->margin(0, 12, 12, 12);
          $c->webView($shown->view)->grow(1)->radius(12)->border(1, $t->border);
          $c->row(0, 0, 0, $t->accent)->absolute()->top(12)->right(16)->padding(4, 10)->radius(12)->shadow(0, 2, 8, 0, 0x000000, 60 / 255);
            $c->text("♥ {$this->likes}", 12, $t->accentText, Font::BOLD);
          $c->end();
        $c->end();
      $c->end();
    $c->end();
    $c->modal($this->dialog, function () use ($c, $t) {
        $c->text('A dialog over the page', 18, style: Font::BOLD);
        $c->muted('Its backdrop takes the pointer: the page under it does not.');
        $c->row(0, 8)->justify(Node::END);
          if ($c->clicked($c->primaryButton('Done'))) { $this->dialog = false; }
        $c->end();
    });
};
