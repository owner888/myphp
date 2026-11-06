<?php
// A row's page, {i} of /row/{i} its number; $this the Mobile main.php makes.
declare(strict_types=1);

use MyPHP\UI\{Context, Node, Route};

return function (Context $c, Route $r, int $i): void {
    $t = $c->theme;
    $r->title("第 $i 行");
    $c->column(16, 12, 1)->alignSelf(Node::STRETCH);
      $c->text("这是第 $i 行的详情页。", 17);
      $c->text('导航栏的返回、Android 的返回键和手势都回到列表，列表停在原处。', 15, $t->textMuted);
      if ($c->clicked($c->button('再推一页'))) { $this->router->push('/row/' . ($i + 1)); }
    $c->end();
};
