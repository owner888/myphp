<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\{Context, Font, Route};

return function (Context $c): void {
    $c->row(0, 0, 1);
      $c->column(12, 6)->size(170, 0);
        $c->row(0, 6);
          $c->backButton($this->router);
          $c->forwardButton($this->router);
        $c->end();
        foreach (['/notes' => 'Notes', '/settings/general' => 'Settings', '/about' => 'About'] as $path => $label) {
            if ($c->clicked($c->button($label))) { $this->router->push($path); }
        }
      $c->end();
      $this->router->view($c, function (Route $r) use ($c) {
          $c->column(20, 10, 1);
          if ($r->match('/notes')) {
              $r->title('Notes');
              $c->text('Notes', 20, style: Font::BOLD);
              foreach ($this->notes as $id => $title) { $c->link($title, "/notes/$id"); }
          } elseif ($r->match('/notes/{id}')) {
              $title = $this->notes[(int)$r->param('id')] ?? 'No such note';
              $r->title($title);
              $c->text($title, 20, style: Font::BOLD);
              $c->muted('Cmd+[, or the back button of a mouse, goes back to the list.');
              $c->link('All notes', '/notes');
          } elseif ($r->match('/settings/{section...}')) {
              $r->title('Settings');
              $c->text('Settings', 20, style: Font::BOLD);
              $c->row(0, 16, 1);
                $c->column(0, 6);
                  $c->link('General', '/settings/general');
                  $c->link('Account', '/settings/account');
                $c->end();
                $r->view($c, function (Route $r) use ($c) {
                    $c->column(0, 8, 1);
                    if ($r->match('/general')) { $r->title('General'); $c->text('General settings'); $c->muted('The section changes inside the layout, which stays.'); }
                    elseif ($r->match('/account')) { $r->title('Account'); $c->text('Your name'); $c->input($this->name); }
                    else { $c->text('No such section'); }
                    $c->end();
                });
              $c->end();
          } elseif ($r->match('/about')) {
              $r->title('About');
              $c->text('About', 20, style: Font::BOLD);
              $c->link('mygo on GitHub', 'https://github.com/egoist/mygo');
          } else {
              $c->text('Not found');
          }
          $c->end();
      });
    $c->end();
};
