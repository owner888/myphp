<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

/**
 * Where to scroll a ScrollView, as SwiftUI's ScrollPosition: kept by the
 * app, given to scrollPosition(), told scrollTo(y:) or scrollTo(edge:);
 * the scroll goes there as it is built next.
 */
final class ScrollPosition
{
    private ?float $y = null;
    private ?\Edge $edge = null;

    public function scrollTo(?float $y = null, ?\Edge $edge = null): void
    {
        [$this->y, $this->edge] = [$y, $edge];
    }

    /** What was asked, once: [y, edge]. @internal @return array{0: ?float, 1: ?\Edge}|null */
    public function take(): ?array
    {
        if ($this->y === null && $this->edge === null) { return null; }
        $asked = [$this->y, $this->edge];
        $this->y = $this->edge = null;
        return $asked;
    }
}
