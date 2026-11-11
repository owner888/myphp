<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/**
 * ViewThatFits, as SwiftUI's: evaluates its candidate views in order and
 * displays the first one whose ideal width/height fits within the container,
 * or the last candidate if none fit.
 */
final class ViewThatFits extends View
{
    /** @var list<mixed> */
    private array $content = [];

    public function __construct(
        public readonly ?\Axis $in = null,
    ) {}

    public function __invoke(View|array|null ...$content): static
    {
        $this->content = $content;
        return $this;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $candidates = array_values(array_filter(ListView::flat($this->content), fn($v) => $v !== null));
        if ($candidates === []) {
            return null;
        }
        $n = $c->column(0, 0)->alignItems(Node::STRETCH);
          $rect = $c->runtime?->rectOf($n->id);
          $availW = $rect !== null && $rect[2] > 0 ? $rect[2] : INF;
          $availH = $rect !== null && $rect[3] > 0 ? $rect[3] : INF;
          $chosen = $candidates[count($candidates) - 1];
          foreach ($candidates as $cand) {
              $iw = $cand->idealWidth() ?? $cand->minWidth();
              $ih = $cand->idealHeight();
              $fitsW = $this->in === \Axis::vertical || $iw === null || $iw <= $availW;
              $fitsH = $this->in === \Axis::horizontal || $ih === null || $ih <= $availH;
              if ($fitsW && $fitsH) {
                  $chosen = $cand;
                  break;
              }
          }
          $chosen->render($c, $env->along(Node::COLUMN));
        $c->end();
        View::takeRoom($n, $env->axis, View::flexOfKids($n));
        return $n;
    }
}