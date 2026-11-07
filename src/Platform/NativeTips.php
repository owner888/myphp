<?php
declare(strict_types=1);

namespace MyPHP\Platform;

/**
 * A window whose system shows the tips of its views (macOS's tool tips,
 * which SwiftUI's help() is): the system's look, at the pointer, after its
 * own delay. Its views' tips are given to it rather than drawn.
 */
interface NativeTips
{
    /** The tip of the view under the pointer, '' for none: the system shows it as the pointer rests there. */
    public function setToolTip(string $tip): void;
}
