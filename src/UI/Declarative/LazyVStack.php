<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Animation, Context, ListState, Node};

/**
 * LazyVStack, as SwiftUI's: its views one under another, $spacing apart,
 * only those in view built — in a ScrollView, which it scrolls itself
 * (ScrollView()( LazyVStack()( Each($this->messages, id: 'id')( … ) ) )),
 * a chat's thousands of messages costing the dozen that show. With the
 * ScrollView's defaultScrollAnchor(.bottom) it starts at its end and
 * follows it as it grows: at once, or gliding with the animation in effect
 * (withAnimation, animation(_:value:)), as SwiftUI's.
 */
final class LazyVStack extends View
{
    /** @var list<mixed> */
    private array $content = [];

    public function __construct(public readonly \HorizontalAlignment $alignment = \HorizontalAlignment::center, public readonly float $spacing = 8) {}

    public function __invoke(View|array|null ...$content): static
    {
        $this->content = $content;
        return $this;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        return $this->scrolled($c, $env, false);
    }

    /** It, scrolling, its end followed when $bottom (ScrollView's defaultScrollAnchor). @internal */
    public function scrolled(Context $c, Env $env, bool $bottom): Node
    {
        $rt = $c->runtime;
        $state = $rt === null ? new ListState() : $rt->local($c->upcomingId(), fn() => new ListState(), ListState::class);
        $state->followEnd = $bottom;
        $state->followAnimation = $bottom ? ($env->animation ?? Transaction::current($c)) : null;
        $views = ListView::flat($this->content);
        $rowEnv = $env->along(Node::COLUMN);
        $align = match ($this->alignment) { \HorizontalAlignment::leading => Node::START, \HorizontalAlignment::trailing => Node::END, default => Node::CENTER };
        $n = $c->list($state, count($views), function (int $i) use ($c, $rowEnv, $views, $align): void {
            $views[$i]->render($c, $rowEnv)?->alignSelf($align);
        })->gap($this->spacing);
        if ($bottom) { $n->justify(Node::END); } // shorter than its room, it sits at its end, as SwiftUI's anchored at the bottom
        View::takeRoom($n, $env->axis, View::FLEX_X | View::FLEX_Y);
        return $n;
    }
}
