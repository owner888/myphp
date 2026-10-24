<?php
declare(strict_types=1);

namespace MyPHP\UI;

/** The parts of a segmented control without a look (mygo's SegmentedParts): $track, which holds the segments segment() builds; end() it after them. */
final class SegmentedParts
{
    public function __construct(public readonly Node $track, private readonly Context $c, private int &$selected) {}

    /** Segment $i: a radio button choosing $i, centering what is built in it until end(); style it from whether $i is the one chosen. */
    public function segment(int $i): Node
    {
        $s = $this->c->radioBase($this->selected, $i)->center();
        $s->segment = true;
        if ($s->changed) { $this->track->changed = true; }
        return $s;
    }
}
