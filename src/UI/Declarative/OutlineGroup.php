<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;
use MyPHP\UI\{Context, Node};

/**
 * OutlineGroup, as SwiftUI's: recursively renders tree-structured data
 * whose child items live at key/property $children, using DisclosureGroup
 * for branches and the row closure for leaves.
 */
final class OutlineGroup extends View
{
    private ?Closure $content = null;

    /** @param iterable<mixed> $data */
    public function __construct(
        public readonly iterable $data,
        public readonly string|Closure $children = 'children',
        public readonly string|Closure|null $id = null,
    ) {}

    public function __invoke(Closure $content): static
    {
        $this->content = $content;
        return $this;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $content = $this->content ?? throw new \LogicException('OutlineGroup($data) takes a row closure: OutlineGroup($data, children: \'children\')(fn($item) => Text($item->name))');
        $n = $c->column(0, 4)->alignItems(Node::STRETCH);
          foreach ($this->buildViews($this->data, $content, 0) as $v) {
              $v->render($c, $env->along(Node::COLUMN));
          }
        $c->end();
        View::takeRoom($n, $env->axis, View::FLEX_X);
        return $n;
    }

    /**
     * @param iterable<mixed> $items
     * @return list<View>
     */
    private function buildViews(iterable $items, Closure $content, int $depth): array
    {
        $out = [];
        foreach ($items as $k => $item) {
            $kids = match (true) {
                $this->children instanceof Closure => ($this->children)($item),
                is_array($item) => $item[$this->children] ?? null,
                is_object($item) => $item->{$this->children} ?? null,
                default => null,
            };
            $key = match (true) {
                $this->id instanceof Closure => ($this->id)($item, $k),
                is_string($this->id) => is_array($item) ? ($item[$this->id] ?? $k) : ($item->{$this->id} ?? $k),
                default => $k,
            };
            $childList = [];
            if (is_iterable($kids)) {
                foreach ($kids as $ck => $ci) {
                    $childList[$ck] = $ci;
                }
            }
            if ($childList !== []) {
                $labelView = $content($item);
                $title = $labelView instanceof Text ? $labelView->text : ($labelView instanceof Label ? $labelView->title : (is_array($item) ? (string)($item['name'] ?? $item['title'] ?? $key) : (string)($item->name ?? $item->title ?? $key)));
                $subViews = $this->buildViews($childList, $content, $depth + 1);
                $out[] = (new DisclosureGroup((string)$title))(...$subViews)->id("outline:$depth:$key");
            } else {
                $v = $content($item);
                if ($v instanceof View) {
                    $v->identity ??= "outline:$depth:$key";
                    $out[] = $v;
                }
            }
        }
        return $out;
    }
}