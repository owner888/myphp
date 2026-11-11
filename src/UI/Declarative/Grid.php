<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Node, Track};

/**
 * Grid and GridRow, as SwiftUI's 2D Grid: rows of cells aligned across columns,
 * with gridCellColumns($span) spanning several columns.
 */
final class Grid extends View
{
    /** @var list<mixed> */
    private array $content = [];

    public function __construct(
        public readonly \Alignment $alignment = \Alignment::center,
        public readonly float $horizontalSpacing = 8,
        public readonly float $verticalSpacing = 8,
    ) {}

    public function __invoke(View|array|null ...$content): static
    {
        $this->content = $content;
        return $this;
    }

    protected function build(Context $c, Env $env): ?Node
    {
        $rows = ListView::flat($this->content);
        $maxCols = 1;
        foreach ($rows as $r) {
            if ($r instanceof GridRow) {
                $cols = 0;
                foreach (ListView::flat($r->cells) as $cell) {
                    $cols += max(1, $cell->colSpan);
                }
                $maxCols = max($maxCols, $cols);
            }
        }
        $n = $c->grid(array_fill(0, $maxCols, Track::fit()))->gap($this->horizontalSpacing, $this->verticalSpacing);
        $cellEnv = $env->along(Node::ROW);
        $rowIdx = 1;
        foreach ($rows as $r) {
            if ($r instanceof GridRow) {
                $colIdx = 1;
                foreach (ListView::flat($r->cells) as $cell) {
                    $k = $cell->render($c, $cellEnv);
                    if ($k !== null) {
                        $span = max(1, $cell->colSpan);
                        $k->rowStart($rowIdx)->columnStart($colIdx)->columnSpan($span);
                        $this->alignment->place($k, true);
                        $colIdx += $span;
                    }
                }
                $rowIdx++;
            } else {
                $k = $r->render($c, $cellEnv);
                if ($k !== null) {
                    $k->rowStart($rowIdx)->columnStart(1)->columnSpan($maxCols)->justifySelf(Node::STRETCH);
                    $rowIdx++;
                }
            }
        }
        $c->end();
        View::takeRoom($n, $env->axis, View::flexOfKids($n));
        return $n;
    }
}