<?php
// About.
declare(strict_types=1);

use MyPHP\UI\{Context, Font, Route};

return function (Context $c, Route $r): void {
    $r->title('About');
    $c->column(20, 10, 1);
      $c->text('About', 20, style: Font::BOLD);
      $c->link('mygo on GitHub', 'https://github.com/egoist/mygo');
    $c->end();
};
