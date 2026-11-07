<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

/** The moment a TimelineView's content is made of, as SwiftUI's TimelineViewDefaultContext: its date, and the frame's time in nanoseconds (Context::now). */
final class TimelineViewContext
{
    public readonly \DateTimeImmutable $date;

    public function __construct(public readonly int $nanoseconds)
    {
        $this->date = new \DateTimeImmutable();
    }
}
