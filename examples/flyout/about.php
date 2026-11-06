<?php
// The About box's flyout, $this the state main.php makes (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\Context;

return function (Context $c): void {
    $c->root->color = null;
    $box = $c->vibrancy() ? $c->column()->fill() : panel($c); // over the popover's material; without one, a panel
    $box->padding(12)->gap(4);
      $c->text('Flyout')->bold();
      $c->text('Windows owned by their parent, placed next to an anchor.', 12, $c->theme->textMuted);
    $c->end();
};
