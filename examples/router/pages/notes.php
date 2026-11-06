<?php
// The notes, each a link to its page.
declare(strict_types=1);

use MyPHP\UI\{Context, Font, Route};

return function (Context $c, Route $r): void {
    $r->title('Notes');
    $c->column(20, 10, 1);
      $c->text('Notes', 20, style: Font::BOLD);
      foreach ($this->notes as $id => $title) { $c->link($title, "/notes/$id"); }
    $c->end();
};
