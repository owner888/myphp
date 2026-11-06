<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\App;
use MyPHP\UI\{Context, Key, Node};

/** A shortcut spelled as macOS does: ⌥⇧Space. */
$keys = function (string $shortcut): string {
    return strtr($shortcut, ['CmdOrCtrl+' => '⌘', 'Cmd+' => '⌘', 'Ctrl+' => '⌃', 'Alt+' => '⌥', 'Shift+' => '⇧']);
};

/** An app, with its icon, its name, and where it is. */
$row = function (Context $c, Launcher $l, int $i): void {
    $app = $l->results[$i];
    $t = $c->theme;
    $chosen = $i === $l->chosen();
    $r = $c->row(0, 12)->padding(6, 10)->alignItems(Node::CENTER)->radius(8);
    if ($chosen) { $r->background($t->accent, 0.85); }
      if (($icon = $l->icon($app)) !== null) { $c->image($icon, ICON_SIZE, ICON_SIZE); } else { $c->avatar($app->name)->size(ICON_SIZE, ICON_SIZE); } // its initial
      $c->column(0, 1, 1);
        $c->text($app->name, color: $chosen ? $t->accentText : $t->text)->singleLine();
        $c->text(where($app), 11, $chosen ? $t->accentText : $t->textMuted)->singleLine();
      $c->end();
    $c->end();
};

return function (Context $c) use (&$row, &$keys): void {
    $t = $c->theme;
    $c->root->color = $c->vibrancy() ? null : $t->background; // the material shows through
    // An Escape left to the panel closes it, which hides it: with a query, Escape clears it first.
    if ($this->query->text !== '' && $c->shortcut(Key::ESCAPE)) { $this->query->text = ''; $this->filter(); }
    if ($c->shortcut(Key::DOWN)) { $this->choose($this->chosen() + 1); }
    if ($c->shortcut(Key::UP)) { $this->choose($this->chosen() - 1); }
    if ($c->shortcut(Key::RETURN)) { $this->open(); }
    $c->onShortcut(Key::Q, Key::COMMAND, App::quit(...));
    $c->column(0, 0, 1);
      $c->row(0, 12)->padding(16, 18)->alignItems(Node::CENTER);
        $c->icon($this->searchIcon, 20, $t->textMuted);
        if ($c->changed($c->textInputBase($this->query)->bind($this->input)->grow(1)->fontSize(22)->label('Search apps'))) { $this->filter(); }
      $c->end();
      $c->divider();
      if ($this->results === []) {
          $c->column(16, 0, 1);
            $c->muted($this->loading ? 'Finding apps…' : 'No apps found');
          $c->end();
      } else {
          $this->list->key = fn(int $i) => $this->results[$i]->path;
          $list = $c->list($this->list, count($this->results), fn(int $i) => $row($c, $this, $i))->grow(1)->padding(6);
          if ($this->list->submitted()) { $this->open(); }
      }
      $c->divider();
      $c->row(0, 16)->padding(8, 16);
        $c->text($keys($this->shortcut) . ' to show or hide', 11, $t->textMuted)->grow(1);
        $c->text('↑↓ Choose   ↵ Open   esc Close', 11, $t->textMuted);
      $c->end();
    $c->end();
};
