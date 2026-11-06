<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/** Text, as SwiftUI's Text: in the environment's font and color, wrapping at the room it is given. */
final class Text extends View
{
    public function __construct(public readonly string $text) {}

    protected function build(Context $c, Env $env): ?Node
    {
        $n = $c->text($this->text);
        self::style($n, $env);
        return $n;
    }

    /** Gives text node $n the environment's font, weight, slant, color, alignment and line limit. @internal */
    public static function style(Node $n, Env $env): void
    {
        $env->font?->apply($n);
        if ($env->weight !== null) { $n->fontWeight($env->weight->value); }
        if ($env->italic) { $n->italic(); }
        if ($env->mono) { $n->font('monospace'); }
        if ($env->color !== null) { $n->textColor($env->color->rgb($env)); }
        if ($env->textAlign !== null) { $n->textAlign($env->textAlign->align()); }
        if ($env->lineLimit !== null) { $env->lineLimit === 1 ? $n->singleLine() : $n->maxLines($env->lineLimit); }
    }
}
