<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * Decodes a video's frames one after another, for a VideoPlayer to show
 * (mygo's VideoDecoder): GifDecoder decodes animated GIFs; implement one
 * for another format. A decoder with a close() method has it called as
 * its player closes.
 *
 *     final class Frames implements VideoDecoder
 *     {
 *         private int $next = 0;
 *         public function __construct(private array $pics) {} // [w, h, rgba] each
 *         public function next(): ?VideoFrame
 *         {
 *             if ($this->next === count($this->pics)) { return null; }
 *             [$w, $h, $rgba] = $this->pics[$this->next++];
 *             return new VideoFrame($w, $h, $rgba, 1 / 30);
 *         }
 *         public function rewind(): void { $this->next = 0; }
 *     }
 */
interface VideoDecoder
{
    /** The next frame, null after the last; it throws for one that does not decode. */
    public function next(): ?VideoFrame;

    /** Goes back to before the first frame, for the video to play again. */
    public function rewind(): void;
}
