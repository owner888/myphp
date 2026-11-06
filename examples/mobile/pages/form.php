<?php
// The form: a field, the system's pickers, an HTTPS request; $this the Mobile main.php makes.
declare(strict_types=1);

use MyPHP\UI\{Context, Node, Route};

return function (Context $c, Route $r): void {
    $t = $c->theme;
    $r->title('表单');
    $c->scroll(16, 16, 1)->alignSelf(Node::STRETCH);
      $c->text('姓名（点输入框弹键盘，中文输入法组字，点空白收起）', 13, $t->textMuted);
      $c->input($this->name, '请输入姓名', 0)->alignSelf(Node::STRETCH);
      $c->row(0, 12);
        if ($c->clicked($c->button('选图片'))) { $this->pick(['jpg', 'jpeg', 'png', 'heic', 'webp'], true); }
        if ($c->clicked($c->button('选文件'))) { $this->pick(['pdf', 'xlsx', 'docx', 'txt', 'csv'], false); }
      $c->end();
      if ($this->photo !== null) { $c->image($this->photo, 0, 240, 8)->alignSelf(Node::STRETCH); }
      if ($this->photoPath !== '') { $c->text(basename($this->photoPath) . ' · ' . number_format((int)filesize($this->photoPath)) . ' B', 13, $t->textMuted); }
      foreach ($this->files as $f) { $c->text(basename($f) . ' · ' . number_format((int)filesize($f)) . ' B', 13, $t->textMuted); }
      $c->divider();
      if ($c->clicked($c->button('HTTPS GET example.com'))) { $this->fetch(); }
      if ($this->http !== '') { $c->text($this->http, 13, $t->textMuted); }
    $c->end();
};
