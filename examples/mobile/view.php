<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\{App, Dialog, FileFilter, Haptics, Http, OpenDialogOptions};
use MyPHP\UI\{Context, Font, Image, Node, Route};

$page = function (Context $c, Route $r) use (&$pick, &$fetch): void {
    $t = $c->theme;
    if ($r->match('/home')) {
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
    } elseif ($r->match('/row/{i}')) {
        $i = $r->param('i');
        $r->title("第 $i 行");
        $c->column(16, 12, 1)->alignSelf(Node::STRETCH);
          $c->text("这是第 $i 行的详情页让我们来试试看 hot reloahhvi。", 17);
          $c->text('导航栏的返回、Android 的返回键和手势都回到列表，列表停在原处。', 15, $t->textMuted);
          if ($c->clicked($c->button('再推一页'))) { $this->router->push('/row/' . ((int)$i + 1)); }
        $c->end();
    } elseif ($r->match('/form')) {
        $r->title('表单');
        $c->scroll(16, 16, 1)->alignSelf(Node::STRETCH);
          $c->text('姓名（点输入框弹键盘，中文输入法组字，点空白收起）', 13, $t->textMuted);
          $c->input($this->name, '请输入姓名', 0)->alignSelf(Node::STRETCH);
          $c->row(0, 12);
            if ($c->clicked($c->button('选图片'))) { $pick(['jpg', 'jpeg', 'png', 'heic', 'webp'], true); }
            if ($c->clicked($c->button('选文件'))) { $pick(['pdf', 'xlsx', 'docx', 'txt', 'csv'], false); }
          $c->end();
          if ($this->photo !== null) { $c->image($this->photo, 0, 240, 8)->alignSelf(Node::STRETCH); }
          if ($this->photoPath !== '') { $c->text(basename($this->photoPath) . ' · ' . number_format((int)filesize($this->photoPath)) . ' B', 13, $t->textMuted); }
          foreach ($this->files as $f) { $c->text(basename($f) . ' · ' . number_format((int)filesize($f)) . ' B', 13, $t->textMuted); }
          $c->divider();
          if ($c->clicked($c->button('HTTPS GET example.com'))) { $fetch(); }
          if ($this->http !== '') { $c->text($this->http, 13, $t->textMuted); }
        $c->end();
    } elseif ($r->match('/me')) {
        $r->title('我的');
        // a settings page as iOS's: a large title, pull to refresh, inset grouped sections of cells, the text styles, haptics, and the system's settings the theme follows
        if ($this->refreshing && $c->now() - $this->refreshedAt > 1_500_000_000) { $this->refreshing = false; $this->refreshes++; } // the work a refresh does, here a second and a half
        if ($this->refreshing) { $c->after(0.1); }
        $page = $c->refreshable($this->meScroll, $this->refreshing, function () use ($c, $t): void {
          $c->largeTitle('我的');
          $c->text('下拉刷新过 ' . $this->refreshes . ' 次', 13, $t->textMuted)->padding(0, 16, 8, 16);
          $c->section('弹层', function () use ($c): void {
              if ($c->clicked($c->cell('底部弹层 sheet', '', true))) { $this->sheet = true; }
              if ($c->clicked($c->cell('操作菜单 actionSheet', '', true))) { $this->actions = true; }
              if ($c->clicked($c->cell('确认弹窗 alertDialog', '', true))) { $this->alert = true; }
              if ($c->clicked($c->cell('提示 toast'))) { $c->toast('已保存'); }
          }, $this->lastAction);
          $c->section('触感 Haptics', function () use ($c): void {
              foreach (['light', 'medium', 'heavy', 'soft', 'rigid'] as $style) {
                  if ($c->clicked($c->cell("impact $style"))) { Haptics::impact($style); }
              }
              if ($c->clicked($c->cell('selection'))) { Haptics::selection(); }
              if ($c->clicked($c->cell('success'))) { Haptics::success(); }
              if ($c->clicked($c->cell('warning'))) { Haptics::warning(); }
              if ($c->clicked($c->cell('error'))) { Haptics::error(); }
          }, 'iPhone 的 Taptic Engine、Android 的振动马达；Mac 的触控板要有手指放着。');
          $c->section('文字层级 preferredFont', function () use ($c): void {
              foreach (['LARGE_TITLE', 'TITLE1', 'TITLE2', 'TITLE3', 'HEADLINE', 'BODY', 'CALLOUT', 'SUBHEADLINE', 'FOOTNOTE', 'CAPTION1', 'CAPTION2'] as $name) {
                  $style = constant(Font::class . '::' . $name);
                  $n = $c->text($style)->preferredFont($style)->padding(11, 16);
                  $n->tooltip(round($n->textStyle()->size ?? 0, 1) . ' pt');
              }
          }, '手机上是 iOS 的尺寸（body 17），桌面是 macOS 的（body 13），都随系统文字大小缩放。');
          $c->section('系统设置', function () use ($c, $t): void {
              $c->cell('文字大小 textScale', sprintf('%.2f × · body %.1f pt', $t->textScale(), Font::preferred(Font::BODY, $c->runtime?->touch ?? false, $t->textScale())[0]));
              $c->cell('减弱动态效果 Reduce Motion', ($c->runtime?->reduceMotion ?? false) ? '开' : '关');
              $c->cell('深色模式', $t->dark ? '开' : '关');
              $c->cell('安全区 · 键盘 · 标签', implode(', ', array_map(fn(float $v) => (string)round($v), $c->safeArea)) . ' · ' . round($c->keyboard) . ' · ' . $c->tabsPlacement());
          }, '在系统设置里改文字大小、减弱动态效果或深浅模式，回到这里应当立刻跟着变。');
        });
        $page->background($t->groupBackground ?? $t->background);
        if ($c->changed($page)) { $this->refreshedAt = $c->now(); }
    } else {
        $c->text('没有这一页');
    }
};

$sheets = function (Context $c): void {
    $t = $c->theme;
    $c->sheet($this->sheet, function () use ($c, $t): void {
        $c->text('底部弹层', 17)->fontWeight(600);
        $c->text('点空白处、按返回键或 Escape 关闭。', 15, $t->textMuted);
        if ($c->clicked($c->primaryButton('关闭'))) { $this->sheet = false; }
    });
    $chosen = $c->actionSheet($this->actions, '这一行', '分享', '重命名', '!删除');
    if ($chosen !== -1) { $this->lastAction = 'actionSheet: ' . ($chosen === Context::SHEET_DISMISSED ? '取消' : ['分享', '重命名', '删除'][$chosen]); }
    $answer = $c->alertDialog($this->alert, '删除这一行？', '删除后不能恢复。', '取消', '删除');
    if ($answer !== -1) { $this->lastAction = 'alertDialog: ' . ($answer === 1 ? '删除' : '取消'); }
};

/** @param list<string> $ext */
$pick = function (array $ext, bool $photo): void {
    $files = Dialog::open(new OpenDialogOptions(filters: [new FileFilter($photo ? '图片' : '文件', $ext)], multiple: !$photo));
    if ($files === []) { return; }
    if ($photo) {
        $this->photoPath = $files[0];
        $this->photo = App::isAndroid() ? null : Image::fromFile($files[0]); // Android decodes no images yet (UI\Image is CoreImage's)
    } else {
        $this->files = $files;
    }
};

$fetch = function (): void {
    $this->http = '请求中…';
    App::go(function (): void {
        $t = hrtime(true);
        try {
            $r = Http::get('https://example.com/');
            $this->http = "HTTP {$r->status} · " . strlen($r->body) . " B · " . round((hrtime(true) - $t) / 1e6) . ' ms';
        } catch (\RuntimeException $e) {
            $this->http = '失败：' . $e->getMessage(); // shown, as an app would: the request's failure is not the app's
        }
    });
};

return function (Context $c) use (&$page, &$sheets): void {
    // the tabs where Apple puts them: a bar across the bottom on a phone, across the top on a tablet upright, a sidebar on its side and on the desktop
    $tabs = $c->tabView([[$this->homeIcon, '列表'], [$this->formIcon, '表单'], [$this->meIcon, '我的']], $this->tab, function (int $tab, string $where) use ($c, $page): void {
        $c->navigationBar($this->router->title(), $this->router, null, $this->router->location() === '/me' ? $this->meScroll : null); // the large title of 我的 scrolls under the bar
        $c->safeArea(function () use ($c, $page): void {
            $this->router->view($c, fn(Route $r) => $page($c, $r));
        });
    });
    if ($c->changed($tabs)) { $this->router->replace(['/home', '/form', '/me'][$this->tab]); }
    $sheets($c);
};
