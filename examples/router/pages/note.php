<?php
// A note, {id} of /notes/{id}.
declare(strict_types=1);

use MyPHP\UI\{Context, Font, Route};

return function (Context $c, Route $r, int $id): void {
    $title = $this->notes[$id] ?? 'No such note';
    $r->title($title);
    $c->column(20, 10, 1);
      $c->text($title, 20, style: Font::BOLD);
      $c->muted('Cmd+[, or the back button of a mouse, goes back to the list.');
      $c->link('All notes', '/notes');
    $c->end();
};
