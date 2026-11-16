<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node, Role};

/** ProgressView, as SwiftUI's: a spinner while $value is null, else a bar filled $value of $total; its title above it. */
final class ProgressView extends View
{
    public function __construct(public readonly string $title = '', public readonly ?float $value = null, public readonly float $total = 1.0) {}

    protected function build(Context $c, Env $env): ?Node
    {
        $size = $env->theme->fontSize * 1.25;
        if ($this->value === null && $this->title === '') {
            return $c->attach(new Node(Node::SPINNER, 0, 0, 0, $size, $size))->role(Role::PROGRESS)->range(0, 1, -1);
        }
        $n = $c->open(new Node(Node::CONTAINER, Node::COLUMN, 0, 6))->alignSelf($this->value === null ? Node::AUTO : Node::STRETCH);
          if ($this->title !== '') { new Text($this->title)->render($c, $env->along(Node::COLUMN)); }
          if ($this->value === null) {
              $c->attach(new Node(Node::SPINNER, 0, 0, 0, $size, $size))->role(Role::PROGRESS)->range(0, 1, -1);
          } else {
              $frac = $this->total > 0 ? max(0.0, min(1.0, $this->value / $this->total)) : 0.0;
              $bar = $c->attach(new Node(Node::PROGRESS, 0, 0, 0, 0, $env->theme->space(1.5)))->alignSelf(Node::STRETCH);
              $bar->fraction = $frac;
              $bar->role(Role::PROGRESS)->range(0, 1, $frac);
          }
        $c->end();
        $n->label(Localized::string($this->title !== '' ? $this->title : 'Progress', $env));
        return $n;
    }
}
