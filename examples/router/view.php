<?php
// The window's view of the Pages main.php makes, $this in it (Content::file): the sidebar, and the router's pages, each a file of pages/; saved, each hot reloads.
declare(strict_types=1);

use MyPHP\UI\Context;

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
      $this->router->view($c, [
          '/notes'               => __DIR__ . '/pages/notes.php',
          '/notes/{id}'          => __DIR__ . '/pages/note.php',
          '/settings/{section...}' => __DIR__ . '/pages/settings.php', // a layout: its sections change inside it
          '/about'               => __DIR__ . '/pages/about.php',
          '*'                    => fn(Context $c) => $c->text('Not found'),
      ]);
    $c->end();
};
