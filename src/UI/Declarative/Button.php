<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\UI\{Context, Node};

/**
 * Button, as SwiftUI's: a title, or a label view (Label, Image…), and the
 * action a click or tap runs — or Return, Space, its keyboardShortcut —
 * in the environment's buttonStyle: automatic (bordered on the desktop, a
 * capsule on a phone), borderedProminent (filled with the tint), bordered,
 * borderless and plain (its label alone). A destructive role is red.
 */
final class Button extends View
{
    public function __construct(
        public readonly string $title,
        public readonly ?Closure $action = null,
        public readonly ?\ButtonRole $role = null,
        public readonly ?View $label = null,
    ) {}

    protected function build(Context $c, Env $env): ?Node
    {
        $style = $env->buttonStyle ?? \ButtonStyle::automatic;
        if ($this->label !== null) {
            $n = $c->buttonBase()->alignItems(Node::CENTER);
            $this->label->render($c, $env->along(Node::ROW));
            $c->end();
        } else {
            $n = $style === \ButtonStyle::borderedProminent ? $c->primaryButton($this->title) : $c->button($this->title);
        }
        if ($style === \ButtonStyle::borderless || $style === \ButtonStyle::plain) {
            $n->bordered = false;
            $n->color = null;
            $n->fg = $style === \ButtonStyle::plain ? ($env->color?->rgb($env) ?? $env->theme->text) : ($env->tint?->rgb($env) ?? $env->theme->accent);
        } elseif ($style === \ButtonStyle::borderedProminent && $env->tint !== null) {
            $n->color = $env->tint->rgb($env);
        }
        if ($this->role === \ButtonRole::destructive) { $n->fg = $style === \ButtonStyle::borderedProminent ? 0xffffff : $env->theme->danger; if ($style === \ButtonStyle::borderedProminent) { $n->color = $env->theme->danger; } }
        if ($env->disabled) { $n->disabled(); }
        if ($this->label === null) { $n->label($this->title); }
        if ($c->clicked($n) && $this->action !== null) { ($this->action)(); }
        return $n;
    }

    protected function shortcut(): void
    {
        $this->run();
    }

    /** Runs the button's action, as a click does. @internal */
    public function run(): void
    {
        if ($this->action !== null) { ($this->action)(); }
    }
}
