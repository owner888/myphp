<?php
// The list: 200 000 rows, each swiped for Flag and Delete, tapped for its page; $this the Mobile main.php makes.
declare(strict_types=1);

use MyPHP\Haptics;
use MyPHP\UI\{Context, Node, Route};

return function (Context $c, Route $r): void {
    $t = $c->theme;
    $r->title(number_format(self::ROWS) . ' 行');
    $c->list($this->rows, self::ROWS, function (int $i) use ($c, $t): void {
        // a row swiped to the left shows Flag and Delete, as Mail's; a full swipe deletes (here: notes it); a tap opens the row
        $row = $c->swipeActions($action, function () use ($c, $t, $i): void {
            $c->row(0, 12)->alignSelf(Node::STRETCH)->padding(12, 16)->alignItems(Node::CENTER)->borderWidth(0, 0, 1, 0)->borderColor($t->border);
              $c->text((string)($i + 1), 15, $t->textMuted)->size(64, 0)->singleLine();
              $c->text("第 $i 行 · " . md5((string)$i), 15, $t->text)->singleLine()->shrink(1);
            $c->end();
        }, '标记', '!删除');
        if ($action >= 0) { $this->lastAction = 'swipeActions: 第 ' . ($i + 1) . ' 行 ' . ['标记', '删除'][$action]; Haptics::impact('light'); }
        if ($c->clicked($row)) { $this->router->push("/row/$i"); }
    });
};
