<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\{Context, Node};

return function (Context $c): void {
    $t = $c->theme;
    $c->column(20, 12, 1)->fill();
      $c->text("Count: {$this->count}");
      if ($c->clicked($c->key('add')->button('Add'))) { $this->count++; }
      $c->key('name')->input($this->name, 'Name', 240)->label('Name');
      $c->text("Hello, {$this->name->text}");
      // Its Delete button shows while the row hovers; at rest it ignores input, a press there the row's, but assistive technology presses it.
      $row = $c->key('row')->row(8, 8, 0, $t->surface)->size(320, 44)->radius(8)->label('Row')->alignItems(Node::CENTER);
      $hovered = $c->hovered($row);
        $c->text('A row')->grow = 1;
        $del = $c->key('delete')->button('Delete')->ignoreInput(!$hovered);
        if (!$hovered) { $del->opacity(0); }
        if ($c->clicked($del)) { $this->deletes++; }
      $c->end();
      if ($c->clicked($row)) { $this->rowClicks++; }
      $c->text("Row clicks: {$this->rowClicks}, deletes: {$this->deletes}");
      // Shapes drawn in bands of rows around their round corners (v1.115): their edges should look as ever.
      $c->row(0, 16)->alignItems(Node::CENTER);
        $c->column(0, 0)->size(64, 64)->radius(32)->border(2, $t->accent);
        $c->end();
        $c->column(0, 0)->size(120, 64)->radius(12)->border(1, $t->border);
        $c->end();
        $c->column(0, 0, 0, $t->background)->size(120, 64)->radius(16)->shadow(0, 6, 18, 0, 0x000000, 0.35);
        $c->end();
      $c->end();
    $c->end();
};
