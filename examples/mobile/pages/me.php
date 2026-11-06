<?php
// Settings as iOS's: a large title, pull to refresh, inset grouped sections; $this the Mobile main.php makes.
declare(strict_types=1);

use MyPHP\Haptics;
use MyPHP\UI\{Context, Font, Route};

return function (Context $c, Route $r): void {
    $t = $c->theme;
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
};
