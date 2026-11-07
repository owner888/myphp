<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node, Track};

/** ZStack, as SwiftUI's: the views given one over the other, the first at the back, placed in the stack by $alignment. */
final class ZStack extends View
{
    /** @var list<mixed> */
    private array $content = [];

    public function __construct(public readonly \Alignment $alignment) {}

    public function __invoke(View|array|null ...$content): static
    {
        $this->content = $content;
        return $this;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $n = $c->grid([Track::fr()]);
        $flex = 0;
        foreach (self::flat($this->content) as $v) {
            $from = count($n->kids);
            $v->render($c, $env);
            foreach (array_slice($n->kids, $from) as $kid) { // all it built: an Each's views too, which it builds in the stack, returning none
                $kid->columnStart(1)->rowStart(1);
                $f = View::flexOf($kid, $env->axis); // as SwiftUI's: a view that takes the room takes the stack's, the others placed by the alignment
                $flex |= $f;
                $this->alignment->place($kid, true);
                if ($f & View::FLEX_X) { $kid->justifySelf(Node::STRETCH); }
                if ($f & View::FLEX_Y) { $kid->alignSelf(Node::STRETCH); }
            }
        }
        $c->end();
        View::takeRoom($n, $env->axis, $flex); // and the stack takes the room when one of them does
        return $n;
    }

    /** @param array<mixed> $content @return list<View> */
    private static function flat(array $content): array
    {
        $out = [];
        foreach ($content as $v) {
            if ($v instanceof View) { $out[] = $v; } elseif (is_array($v)) { array_push($out, ...self::flat($v)); }
        }
        return $out;
    }
}
