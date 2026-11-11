<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/**
 * Accordion (Flutter's ExpansionPanelList / bordered DisclosureGroup stack):
 * renders DisclosureGroup sections in a bordered container. When $exclusive is
 * true (or $selection is bound), opening one section automatically closes the
 * others.
 */
final class Accordion extends View
{
    public const UNBOUND = "\0unbound";

    /** @var list<mixed> */
    private array $content = [];
    private mixed $selection = null;
    private bool $hasSelection = false;

    public function __construct(
        mixed &$selection = self::UNBOUND,
        bool $hasSelection = false,
        public readonly bool $exclusive = true,
    ) {
        if ($hasSelection) {
            $this->selection = &$selection;
            $this->hasSelection = true;
        }
    }

    public function __invoke(View|array|null ...$content): static
    {
        $this->content = $content;
        return $this;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $items = array_values(array_filter(ListView::flat($this->content), fn($v) => $v instanceof DisclosureGroup));
        $id = $c->upcomingId();
        $kept = $c->runtime?->local($id, fn() => (object)['heads' => [], 'open' => []], \stdClass::class, 'heads') ?? (object)['heads' => [], 'open' => []];

        $n = $c->accordion(function () use ($c, $env, $items, $kept): void {
            foreach ($items as $i => $item) {
                $tag = $item->hasTag ? $item->tagValue : ($item->identity ?? $i);
                $isOpen = $this->hasSelection
                    ? ($this->selection === $tag)
                    : (bool)($kept->open[$tag] ?? false);
                $wasOpen = $isOpen;
                $c->accordionItem(Localized::string($item->title, $env), $isOpen, function () use ($c, $env, $item): void {
                    View::renderAll($c, $env->along(Node::COLUMN), $item->children());
                });
                if ($isOpen !== $wasOpen) {
                    if ($this->hasSelection) {
                        $this->selection = $isOpen ? $tag : null;
                    } else {
                        if ($isOpen && $this->exclusive) {
                            $kept->open = [$tag => true];
                        } else {
                            $kept->open[$tag] = $isOpen;
                        }
                    }
                }
            }
        });
        View::takeRoom($n, $env->axis, View::FLEX_X);
        return $n;
    }
}