<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\WindowOptions;
use MyPHP\UI\{Context, Font, Node};

/** The materials to choose from, under the room the window controls take. */
$sidebar = function (Context $c, Vibrancy $a, bool $vibrant): void {
    $c->box(0, TITLE_BAR_HEIGHT, 0)->fillWidth()->shrink(0)->dragWindow()->color = null;
    $list = $c->sidebar($a->chosen, function () use ($c, $a) {
        foreach (sections() as [$title, $icon, $items]) {
            $a->open[$title] ??= true;
            $c->sidebarSection($title, $a->open[$title], function () use ($c, $icon, $items) {
                foreach ($items as [$value, $label, $about]) { $c->sidebarItem($value, $label, $icon)->tooltip($about); }
            });
        }
    }, SIDEBAR_WIDTH)->grow(1)->label('Materials');
    if ($vibrant) { $list->color = null; }
    if ($c->changed($list)) { $a->win?->setVibrancy($a->chosen); }
};

/** The material chosen, and how to choose it in code; its title starts left of the window controls when the sidebar hides. */
$pane = function (Context $c, Vibrancy $a, bool $vibrant, float $titleLeft): void {
    $t = $c->theme;
    [$value, $label, $about] = $a->find();
    $bar = $c->titleBar();
    $pane = $c->column(0, 0, 1)->minWidth(0)->minHeight(0);
    if (!$a->seeThrough || !$vibrant) { $pane->background($t->background); }
      $c->row()->size(0, TITLE_BAR_HEIGHT)->fillWidth()->shrink(0)->alignItems(Node::CENTER)->padding(0, $bar['right'] + 20, 0, $titleLeft)->dragWindow();
        $c->text($label, 15, style: Font::BOLD)->singleLine();
      $c->end();
      $c->divider();
      $c->column(0, 0, 1)->scroll($a->scroll);
        $c->column(0, 16)->padding(24, 28)->maxWidth(560);
          $c->text($about, 15);
          $c->text("new WindowOptions(vibrancy: '$value')  // or \$win->setVibrancy('$value')", 12)->font('monospace')->padding(12, 14)->radius(8)->background($t->surface)->selectable();
          $c->text('A material shows wherever the view draws no background: the sidebar has none, this pane has one.');
          $c->checkbox('Show the material behind this pane too', $a->seeThrough)->disabled(!$vibrant);
          $c->text($vibrant ? 'The material is the system\'s, behind the window, and follows the appearance and the desktop.'
              : 'This window shows no material here, so the sidebar draws a background of its own: materials show on macOS.', 12, $t->textMuted)->margin(8, 0, 0, 0);
        $c->end();
      $c->end();
    $c->end();
};

return function (Context $c) use (&$sidebar, &$pane): void {
    $t = $c->theme;
    // The material shows wherever the view draws no background, where the window shows one: the root draws none then, nor does the sidebar.
    $vibrant = $c->vibrancy();
    if ($vibrant) { $c->root->color = null; }
    $bar = $c->titleBar();
    $row = $c->row(0, 0, 1)->alignItems(Node::STRETCH);
    $shown = $c->animate($row, 'sidebar', $this->collapsed ? 0 : 1, 0.25);
      // The sidebar slides out to the left as it hides.
      $w = SIDEBAR_WIDTH * $shown;
      $c->row()->size($w, 0)->shrink(0)->alignItems(Node::STRETCH)->clip();
        $side = $c->column()->size(SIDEBAR_WIDTH, 0)->shrink(0)->margin(0, 0, 0, $w - SIDEBAR_WIDTH);
        if (!$vibrant) { $side->background($t->surface); }
          $sidebar($c, $this, $vibrant);
        $c->end();
      $c->end();
      if ($w > 0) { $c->box(1, 0, $t->border); }
      $pane($c, $this, $vibrant, 20 + (1 - $shown) * ($bar['left'] + 36));
    $c->end();
    // Next to the window controls on the left, whether the sidebar shows or not.
    $label = $this->collapsed ? 'Show Sidebar' : 'Hide Sidebar';
    $toggle = $c->key('toggle')->buttonBase()->absolute()->left($bar['left'] + 8)->top((TITLE_BAR_HEIGHT - 28) / 2)->size(32, 28)->radius(6)->label($label)->tooltip($label);
    if ($c->hovered($toggle)) { $toggle->background($t->surfaceHover); }
      $c->icon($this->sidebarIcon, 16, $t->textMuted);
    $c->end();
    if ($c->clicked($toggle)) { $this->collapsed = !$this->collapsed; }
};
