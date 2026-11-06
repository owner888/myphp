<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\{Context, Role, TextStyle};

// The field is the example's own — its storage, its caret, its drawing — over the system's text services (input
// methods, the Edit menu), as an NSView with NSTextInputClient in a representable.
return fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
    Text('Application-owned text input')->font(Font::system(size: 22, weight: Weight::bold)),
    Text('Click, type, compose with your input method, select with Shift, and use the Edit menu.')->foregroundStyle(Color::secondary),
    Representable(function (Context $c) {
        $t = $c->theme;
        if ($this->shaper === null) {
            $font = new TextStyle();
            $font->size = 18;
            $this->shaper = fn(string $s) => $c->shapeText($s, 0, $font);
            $this->shape();
        }
        return $c->box(0, 60, $t->surface)->fillWidth()->border(1, $t->border)->radius($t->radius)->label('Custom text field')->role(Role::TEXT_FIELD)->value($this->text)
            ->focusable()->handleTextInput($this)->autoFocus()->handleInput($this->input(...))->draw($this->draw(...));
    }),
    Text('Storage, navigation, clipboard policy and rendering belong to this example; the primitive supplies native text services.')->foregroundStyle(Color::secondary),
)->padding(24)->frame(maxWidth: INF, maxHeight: INF, alignment: Alignment::topLeading);
