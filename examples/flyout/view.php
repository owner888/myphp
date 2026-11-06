<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads, the flyouts' views too.
declare(strict_types=1);

use MyPHP\{FlyoutOptions, Rectangle, Window};
use MyPHP\UI\{Content, Context, Node};

/** Opens the list of colors below the button, or closes it. */
$toggleList = function (Rectangle $button): void {
    if ($this->list !== null) { $this->list->close(); return; }
    $this->hover = $this->color;
    $this->list = Window::flyout(new FlyoutOptions(parent: $this->win, anchor: $button, gap: 4, width: 180, height: count(COLORS) * 28 + 12,
        focusable: true, shadow: true, content: Content::file(__DIR__ . '/list.php', $this)));
    $this->list->on('closed', function () { $this->list = null; $this->win->invalidate(); });
    $this->win->invalidate();
};

/** Opens the note editor to the right of the button, which takes the keyboard. */
$openEditor = function (Rectangle $button): void {
    if ($this->editor !== null) { return; }
    $this->editor = Window::flyout(new FlyoutOptions(parent: $this->win, anchor: $button, placement: FlyoutOptions::RIGHT_START, gap: 8, width: 260, height: 96,
        focusable: true, shadow: true, content: Content::file(__DIR__ . '/editor.php', $this)));
    $this->editor->on('closed', function () { $this->editor = null; $this->win->invalidate(); });
};

/** Opens the About box above the button, as a popover on macOS. */
$openAbout = function (Rectangle $button): void {
    if ($this->about !== null) { return; }
    $this->about = Window::flyout(new FlyoutOptions(parent: $this->win, anchor: $button, placement: FlyoutOptions::TOP, gap: 6, width: 240, height: 84,
        focusable: true, shadow: true, popover: true, content: Content::file(__DIR__ . '/about.php', $this)));
    $this->about->on('closed', function () { $this->about = null; });
};

return function (Context $c) use ($toggleList, $openEditor, $openAbout): void {
    $t = $c->theme;
    $c->column(16, 12)->fill()->center();
      $c->row(0, 8)->alignItems(Node::CENTER);
        $c->box(14, 14, COLORS[$this->color][1])->radius(7);
        $b = $c->key('color')->button(COLORS[$this->color][0] . ' ▾');
        if ($c->clicked($b)) { $toggleList(anchor($c, $b)); }
      $c->end();
      $b = $c->key('note')->button('Edit note…');
      if ($c->clicked($b)) { $openEditor(anchor($c, $b)); }
      $b = $c->key('about')->button('About');
      if ($c->clicked($b)) { $openAbout(anchor($c, $b)); }
      $c->text($this->note->text === '' ? 'No note' : $this->note->text, 12, $t->textMuted);
    $c->end();
};
