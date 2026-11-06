<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\UI\{Context, Node};

/**
 * Each, SwiftUI's ForEach (foreach is PHP's): a view for each of $items,
 * made by the function given (the second parentheses), each keeping its
 * identity by its id — $id a property or array key of the item ('id'), a
 * function of it, or null for the item itself (a string or an int) — so
 * that a row's state, focus and animation stay with it as rows come, go
 * and move.
 */
final class Each extends View
{
    private ?Closure $content = null;

    /** @param iterable<mixed> $items */
    public function __construct(public readonly iterable $items, public readonly string|Closure|null $id = null) {}

    /** The view of an item: fn(Item $item) => …, or fn(Item $item, int|string $key). */
    public function __invoke(Closure $content): static
    {
        $this->content = $content;
        return $this;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        foreach ($this->views() as $v) { $v->render($c, $env); }
        return null;
    }

    /** The view of each item, its identity its id: a list's rows, which a Section lays out one by one. @internal @return list<View> */
    public function views(): array
    {
        $content = $this->content ?? throw new \LogicException('Each($items) takes the view of an item: Each($items, id: \'id\')(fn($item) => …)');
        $out = [];
        foreach ($this->items as $key => $item) {
            $v = $content($item, $key);
            if ($v === null) { continue; }
            $id = match (true) {
                $this->id instanceof Closure => ($this->id)($item, $key), // fn($item, $key): its key too, as SwiftUI's id: \.offset
                is_string($this->id) => is_array($item) ? $item[$this->id] : $item->{$this->id},
                is_int($item) || is_string($item) => $item,
                default => $key,
            };
            $v = $v instanceof View ? $v : Group()(...(is_array($v) ? $v : [$v]));
            $v->identity ??= $id;
            $out[] = $v;
        }
        return $out;
    }
}
