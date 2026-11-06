<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/** SwiftUI's Link (WebLink here: PHP has a link() of its own): $title in the accent, opening $destination in the browser. */
final class WebLink extends View
{
    public function __construct(public readonly string $title, public readonly string $destination) {}

    protected function build(Context $c, Env $env): ?Node
    {
        $n = $c->link(Localized::string($this->title, $env), $this->destination);
        $env->font?->apply($n);
        return $n;
    }
}
