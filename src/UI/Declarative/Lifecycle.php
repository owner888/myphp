<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\App;
use MyPHP\UI\{Context, Node};

/**
 * A view's onAppear, onDisappear and task: kept by the runtime with its
 * node (Runtime::$lifecycles), made the first frame the node is built —
 * when it appears, running onAppear and starting the task — and let go the
 * first frame it is not, when it disappears, running onDisappear.
 */
final class Lifecycle
{
    /** @var list<Closure> */
    private array $gone = [];

    /** Notes the lifecycle $events (kind, action) of $node. @internal @param list<array{0: string, 1: Closure}> $events */
    public static function note(Context $c, Node $node, array $events): void
    {
        $rt = $c->runtime;
        if ($rt === null) { return; }
        if (isset($rt->lifecycles[$node->id])) { return; }
        $self = $rt->lifecycles[$node->id] = new self();
        foreach ($events as [$kind, $action]) {
            match ($kind) {
                'appear' => $action(),
                'task' => App::go($action),
                'disappear' => $self->gone[] = $action,
            };
        }
    }

    public function __destruct()
    {
        foreach ($this->gone as $fn) { $fn(); }
    }
}
