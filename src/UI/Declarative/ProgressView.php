<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/** ProgressView, as SwiftUI's: a spinner while $value is null, else a bar filled $value of $total; its title above it. */
final class ProgressView extends View
{
    public function __construct(public readonly string $title = '', public readonly ?float $value = null, public readonly float $total = 1.0) {}

    protected function build(Context $c, Env $env): ?Node
    {
        if ($this->value === null && $this->title === '') { return $c->spinner(); }
        $n = $c->column(0, 6)->alignSelf($this->value === null ? Node::AUTO : Node::STRETCH);
          if ($this->title !== '') { new Text($this->title)->render($c, $env->along(Node::COLUMN)); }
          if ($this->value === null) { $c->spinner(); }
          else { $c->progress($this->total > 0 ? max(0.0, min(1.0, $this->value / $this->total)) : 0.0, 0)->alignSelf(Node::STRETCH); }
        $c->end();
        $n->label(Localized::string($this->title !== '' ? $this->title : 'Progress', $env));
        return $n;
    }
}
