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
    private bool $noted = false;
    /** @var array<int, mixed> the values onChange() saw last, by its place among the view's */
    private array $seen = [];
    private bool $hovered = false;
    /** What focused() bound last frame, null before. */
    private ?bool $focusWas = null;

    /** The lifecycle of $node, made the first frame it is built; null without a runtime (a test's single build). @internal */
    public static function of(Context $c, Node $node): ?self
    {
        $rt = $c->runtime;
        return $rt === null ? null : $rt->lifecycles[$node->id] ??= new self();
    }

    /** Notes the lifecycle $events (kind, action) of $node, run as it appears. @internal @param list<array{0: string, 1: Closure}> $events */
    public static function note(Context $c, Node $node, array $events): void
    {
        $self = self::of($c, $node);
        if ($self === null || $self->noted) { return; }
        $self->noted = true;
        foreach ($events as [$kind, $action]) {
            match ($kind) {
                'appear' => $action(),
                'task' => App::go($action),
                'disappear' => $self->gone[] = $action,
            };
        }
    }

    /** Whether $value, the $i-th onChange's, differs from what it was the frame before (not the first frame), as SwiftUI's onChange; the old value in $old. @internal */
    public function changed(int $i, mixed $value, mixed &$old): bool
    {
        $known = array_key_exists($i, $this->seen);
        $old = $this->seen[$i] ?? null;
        $this->seen[$i] = $value;
        return $known && $old !== $value;
    }

    /** Whether the pointer's being over the view changed to $now since the frame before. @internal */
    public function hoverChanged(bool $now): bool
    {
        $was = $this->hovered;
        $this->hovered = $now;
        return $was !== $now;
    }

    /** Whether the view has the keyboard focus, $wanted the binding: the app's change of it moves the focus, the user's writes it back. @internal */
    public function focus(bool $wanted, bool $has, ?\Closure $take): bool
    {
        if ($wanted !== $this->focusWas) { // the app set it (or the first frame): the focus follows
            if ($wanted && !$has && $take !== null) { $take(); }
            $now = $wanted;
        } else {
            $now = $has; // the user moved it
        }
        $this->focusWas = $now;
        return $now;
    }

    public function __destruct()
    {
        foreach ($this->gone as $fn) { $fn(); }
    }
}
