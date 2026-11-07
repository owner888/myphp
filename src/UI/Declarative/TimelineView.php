<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\UI\{Context, Node};

/**
 * TimelineView, as SwiftUI's with the animation schedule: the view its
 * content makes of the moment, built again every frame the display draws
 * while it shows (TimelineView(.animation) { context in … }): a live chart,
 * a clock. Its content gets a TimelineViewContext: its date, and the
 * frame's time in nanoseconds.
 */
final class TimelineView extends View
{
    private ?Closure $content = null;

    /**
     * $paused: it builds no frames of its own, as SwiftUI's TimelineView(.animation(paused:)); $explicit: it builds only
     * at those times, as TimelineView(.explicit(dates)) — frame times in nanoseconds, as TimelineViewContext's.
     * Closure: of those times, asked once its content is built, which may have changed what is due.
     * @param list<int>|Closure(): list<int>|null $explicit
     */
    public function __construct(private readonly bool $paused = false, private readonly array|Closure|null $explicit = null) {}

    public function __invoke(Closure $content): static
    {
        $this->content = $content;
        return $this;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $now = $c->now();
        if ($this->explicit === null && !$this->paused) { $c->animationFrame(); } // another frame, and another, while it shows
        $view = ($this->content ?? throw new \LogicException('TimelineView() takes the view of a moment: TimelineView()(fn(TimelineViewContext $context) => …)'))(new TimelineViewContext($now));
        $n = $c->column(0, 0)->alignItems(Node::STRETCH);
          View::renderAll($c, $env->along(Node::COLUMN), $view);
        $c->end();
        if ($this->explicit !== null && !$this->paused) { // a frame at the next of its times
            $next = null;
            foreach ($this->explicit instanceof Closure ? ($this->explicit)() : $this->explicit as $at) { $at = max($at, $now); if ($next === null || $at < $next) { $next = $at; } }
            if ($next !== null) { $c->after(($next - $now) / 1e9); }
        }
        $flex = View::flexOfKids($n);
        if ($flex !== 0) { View::takeRoom($n, $env->axis, $flex); }
        return $n;
    }
}
