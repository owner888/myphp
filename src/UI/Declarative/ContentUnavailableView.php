<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node};

/**
 * ContentUnavailableView, as SwiftUI's: an empty-state view centered in its
 * room with a symbol (or an Image), a bold title, a muted description and
 * optional action buttons (ContentUnavailableView::search() for no search
 * results).
 */
final class ContentUnavailableView extends View
{
    /** @var list<mixed> */
    private array $actions = [];

    public function __construct(
        public readonly string $title,
        public readonly ?string $systemImage = null,
        public readonly string $description = '',
    ) {}

    public function __invoke(View|array|null ...$actions): static
    {
        $this->actions = $actions;
        return $this;
    }

    /** SwiftUI's ContentUnavailableView.search(text:). */
    public static function search(string $text = ''): self
    {
        $desc = $text !== '' ? "Check the spelling or try a new search for \"$text\"." : 'Check the spelling or try a new search.';
        return new self('No Results', 'magnifyingglass', $desc);
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $col = $c->column(24, 12, 1)->alignSelf(Node::STRETCH)->center();
          if ($this->systemImage !== null && $this->systemImage !== '') {
              (new ImageView(null, $this->systemImage))
                  ->font(\Font::system(40))
                  ->foregroundStyle(\Color::secondary)
                  ->render($c, $env->along(Node::COLUMN));
          }
          (new Text($this->title))
              ->font(\Font::title2)
              ->bold()
              ->multilineTextAlignment(\TextAlignment::center)
              ->render($c, $env->along(Node::COLUMN));
          if ($this->description !== '') {
              (new Text($this->description))
                  ->font(\Font::subheadline)
                  ->foregroundStyle(\Color::secondary)
                  ->multilineTextAlignment(\TextAlignment::center)
                  ->render($c, $env->along(Node::COLUMN));
          }
          if ($this->actions !== []) {
              $row = $c->row(8, 8)->alignItems(Node::CENTER)->justify(Node::CENTER);
                View::renderAll($c, $env->along(Node::ROW), $this->actions);
              $c->end();
          }
        $c->end();
        View::takeRoom($col, $env->axis, View::FLEX_X | View::FLEX_Y);
        return $col;
    }
}