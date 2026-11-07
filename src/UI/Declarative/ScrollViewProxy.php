<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;

/** What a ScrollViewReader gives its content, as SwiftUI's ScrollViewProxy: scrollTo($id) brings the row of that id of the ListView inside into view. */
final class ScrollViewProxy
{
    /** @var (Closure(int|string, ?\UnitPoint): void)|null the ListView inside, as its last frame built it @internal */
    public ?Closure $list = null;

    /** Scrolls the ListView inside to the row of $id, gliding there: at $anchor (top, center, bottom) of it, or only into view when null. */
    public function scrollTo(int|string $id, ?\UnitPoint $anchor = null): void
    {
        if ($this->list === null) { throw new \LogicException('scrollTo() scrolls a ListView inside the ScrollViewReader: ScrollViewReader(fn(ScrollViewProxy $proxy) => VStack()( Button(\'Top\', action: fn() => $proxy->scrollTo(0)), ListView(…)(…) ))'); }
        ($this->list)($id, $anchor);
    }
}
