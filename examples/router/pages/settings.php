<?php
// Settings: a layout, its list of sections staying as the section beside it changes (Route::view, a table of its own).
declare(strict_types=1);

use MyPHP\UI\{Context, Font, Route};

return function (Context $c, Route $r): void {
    $r->title('Settings');
    $c->column(20, 10, 1);
      $c->text('Settings', 20, style: Font::BOLD);
      $c->row(0, 16, 1);
        $c->column(0, 6);
          $c->link('General', '/settings/general');
          $c->link('Account', '/settings/account');
        $c->end();
        $r->view($c, [
            '/general' => function (Context $c, Route $r): void {
                $r->title('General');
                $c->column(0, 8, 1);
                  $c->text('General settings');
                  $c->muted('The section changes inside the layout, which stays.');
                $c->end();
            },
            '/account' => function (Context $c, Route $r): void {
                $r->title('Account');
                $c->column(0, 8, 1);
                  $c->text('Your name');
                  $c->input($this->name);
                $c->end();
            },
            '*' => fn(Context $c) => $c->text('No such section'),
        ]);
      $c->end();
    $c->end();
};
