<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\{Context, InputEvent, Key, Node, Painter};

return function (Context $c): void {
    $t = $c->theme;
    // While the desktop asks for less motion, the texture shows the wind as it stands.
    $this->tex->still = $c->preferences()->reduceMotion;
    if ($c->shortcut(Key::R, Key::COMMAND)) {
        $this->tex->reseed(random_int(0, U32));
    }
    if ($this->draft->text !== $this->typed) {
        $this->typed = $this->draft->text;
        $this->tex->wake();
    }
    $this->tex->makeAtlases($c);
    [$w] = $c->size();

    $pane = $c->column(0, 0, 0, $t->background)->fill()->alignItems(Node::CENTER);
    $pane->draw(fn(Painter $p, array $r) => $this->tex->draw($p, $r, $t->dark));
    // The pointer stirs the air wherever it is over the pane, but for the
    // controls, which take it themselves.
    $pane->handleInput(function (InputEvent $ev): bool {
        switch ($ev->kind) {
            case InputEvent::POINTER_MOVE:
                $calm = $this->tex->calm;
                $this->tex->move($ev->x, $ev->y, hrtime(true));
                // A frame, to wake the texture as it rests.
                return $calm;
            case InputEvent::POINTER_DOWN:
                if ($ev->button === 0 && !$this->onControl($ev->x, $ev->y)) {
                    $this->tex->tap($ev->x, $ev->y, hrtime(true));
                    return true;
                }
                break;
            case InputEvent::KEY_DOWN:
                $this->tex->wake();
                break;
        }
        return false;
    });
      // The card's middle lies where the texture is calmest, 45% down.
      $c->spacer()->grow(45);
      $c->column(0, 12)->width(min(640, $w - 48));
        $input = $c->key('draft')->input($this->draft, 'Describe a task or ask a question', 0)->label('Task')->padding(14, 16)->radius(14)
            ->background($t->surface)->shadow(0, 4, 18, 0, 0x000000, 0x0f / 255);
        $this->controls = [$c->bounds($input)];
        $c->text('Move the pointer to warm the air, click to blow a ripple (every third a heart), ⌘R for another wind.', 12, $t->textMuted)->textAlign(Node::CENTER);
      $c->end();
      $c->spacer()->grow(55);
      $c->row()->alignSelf(Node::END)->padding(0, 14, 10, 0);
        $c->text(sprintf('%s/%08x', $this->tex->w->look, $this->tex->w->seed), 11, $t->textMuted)->font(FAMILY);
      $c->end();
    $c->end();
};
