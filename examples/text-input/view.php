<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\{Context, Font, Role, TextStyle};

return function (Context $c): void {
    $t = $c->theme;
    if ($this->shaper === null) {
        $font = new TextStyle();
        $font->size = 18;
        $this->shaper = fn(string $s) => $c->shapeText($s, 0, $font);
        $this->shape();
    }
    $c->column(24, 12, 1);
      $c->text('Application-owned text input', 22, style: Font::BOLD);
      $c->muted('Click, type, compose with your input method, select with Shift, and use the Edit menu.');
      $c->box(0, 60, $t->surface)->fillWidth()->border(1, $t->border)->radius($t->radius)->label('Custom text field')->role(Role::TEXT_FIELD)->value($this->text)
          ->focusable()->handleTextInput($this)->autoFocus()->handleInput($this->input(...))->draw($this->draw(...));
      $c->muted('Storage, navigation, clipboard policy and rendering belong to this example; the primitive supplies native text services.');
    $c->end();
};
