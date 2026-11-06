<?php
// The window's view of the Mobile main.php makes, $this in it (Content::file): the tabs, the bars, the pages of pages/, the sheets. Saved, each file hot reloads.
declare(strict_types=1);

use MyPHP\UI\{Context};

/** The sheets, the action sheet and the alert, over every page. */
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

return function (Context $c) use ($sheets): void {
    // the tabs where Apple puts them: a bar across the bottom on a phone, across the top on a tablet upright, a sidebar on its side and on the desktop
    $tabs = $c->tabView([[$this->homeIcon, '列表'], [$this->formIcon, '表单'], [$this->meIcon, '我的']], $this->tab, function (int $tab, string $where) use ($c): void {
        $c->navigationBar($this->router->title(), $this->router, null, $this->router->location() === '/me' ? $this->meScroll : null); // the large title of 我的 scrolls under the bar
        $c->safeArea(function () use ($c): void {
            $this->router->view($c, [
                '/home'    => __DIR__ . '/pages/home.php',
                '/row/{i}' => __DIR__ . '/pages/row.php',
                '/form'    => __DIR__ . '/pages/form.php',
                '/me'      => __DIR__ . '/pages/me.php',
            ]);
        });
    });
    if ($c->changed($tabs)) { $this->router->replace(['/home', '/form', '/me'][$this->tab]); }
    $sheets($c);
};
