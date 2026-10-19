<?php
// A router: a sidebar choosing the pages, which keeps the focus; Back and
// Forward buttons (a right click lists the pages), Cmd+[ and Cmd+], and a
// mouse's back and forward buttons; links to paths; notes sliding in
// deeper and back; a settings layout whose sections change inside it.
// Run: ./run.sh router
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, Context, Font, Route, Router, TextState};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

$router = new Router('/notes');
$notes = [1 => 'Groceries', 2 => 'Ideas', 3 => 'Travel'];
$name = new TextState('');

function view(Context $c, Router $router, array $notes, TextState $name): void
{
    $c->row(0, 0, 1);
      $c->column(12, 6)->size(170, 0);
        $c->row(0, 6);
          $c->backButton($router);
          $c->forwardButton($router);
        $c->end();
        foreach (['/notes' => 'Notes', '/settings/general' => 'Settings', '/about' => 'About'] as $path => $label) {
            if ($c->clicked($c->button($label))) { $router->push($path); }
        }
      $c->end();
      $router->view($c, function (Route $r) use ($c, $notes, $name) {
          $c->column(20, 10, 1);
          if ($r->match('/notes')) {
              $r->title('Notes');
              $c->text('Notes', 20, style: Font::BOLD);
              foreach ($notes as $id => $title) { $c->link($title, "/notes/$id"); }
          } elseif ($r->match('/notes/{id}')) {
              $title = $notes[(int)$r->param('id')] ?? 'No such note';
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
                $r->view($c, function (Route $r) use ($c, $name) {
                    $c->column(0, 8, 1);
                    if ($r->match('/general')) { $r->title('General'); $c->text('General settings'); $c->muted('The section changes inside the layout, which stays.'); }
                    elseif ($r->match('/account')) { $r->title('Account'); $c->text('Your name'); $c->input($name); }
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
}

App::setName('Router');
App::whenReady(function () use ($router, $notes, $name) {
    new Window(new WindowOptions(title: 'MyPHP native — Router', width: 760, height: 460,
        content: Content::view(fn(Context $c) => view($c, $router, $notes, $name))));
});
App::run();
