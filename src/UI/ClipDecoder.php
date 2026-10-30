<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * Decodes a clip's frames one after another, for a ClipPlayer to show
 * (mygo's VideoDecoder): GifDecoder decodes animated GIFs; implement one
 * for another format. A decoder with a close() method has it called as
 * its player closes.
 *
 *     final class Frames implements ClipDecoder
 *     {
 *         private int $next = 0;
 *         public function __construct(private array $pics) {} // [w, h, rgba] each
 *         public function next(): ?ClipFrame
 *         {
 *             if ($this->next === count($this->pics)) { return null; }
 *             [$w, $h, $rgba] = $this->pics[$this->next++];
 *             return new ClipFrame($w, $h, $rgba, 1 / 30);
 *         }
 *         public function rewind(): void { $this->next = 0; }
 *     }
 */
interface ClipDecoder
{
    /** The next frame, null after the last; it throws for one that does not decode. */
    public function next(): ?ClipFrame;

    /** Goes back to before the first frame, for the clip to play again. */
    public function rewind(): void;
}
