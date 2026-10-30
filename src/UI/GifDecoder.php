<?php
declare(strict_types=1);

namespace MyPHP\UI;

use MyPHP\Native\ImageIO;
use RuntimeException;

/**
 * Decodes an animated GIF for a ClipPlayer (mygo's NewGIFDecoder), as
 * browsers show it: each frame composed over what the ones before it
 * left, as its disposal says, by ImageIO, which decodes a frame as it
 * plays; a frame of 10 ms or less shows for 100 ms. It plays once or loops
 * as ClipPlayer::setLoop says, whatever loop count the GIF gives. macOS
 * only.
 */
final class GifDecoder implements ClipDecoder
{
    private mixed $source = null;
    private int $count = 0, $next = 0;

    /** The decoder of the GIF $bytes; it throws for what is no GIF. */
    public function __construct(private readonly string $bytes)
    {
        if (!str_starts_with($bytes, 'GIF87a') && !str_starts_with($bytes, 'GIF89a')) {
            throw new RuntimeException('myphp: not a GIF');
        }
        [$this->source, $this->count] = ImageIO::openFrames($bytes);
        if ($this->count === 0) {
            $this->close();
            throw new RuntimeException('myphp: a GIF of no frames');
        }
    }

    /** The GIF of file $path. */
    public static function fromFile(string $path): self
    {
        $b = @file_get_contents($path);
        return new self($b === false ? throw new RuntimeException("cannot read $path") : $b);
    }

    public function next(): ?ClipFrame
    {
        if ($this->source === null) { throw new RuntimeException('myphp: the GIF decoder is closed'); }
        if ($this->next >= $this->count) { return null; }
        [$w, $h, $rgba, $delay] = ImageIO::frame($this->source, $this->next++);
        return new ClipFrame($w, $h, $rgba, $delay <= 0.010 ? 0.1 : $delay);
    }

    public function rewind(): void
    {
        $this->next = 0;
    }

    /** Lets go of ImageIO's source. */
    public function close(): void
    {
        if ($this->source !== null) { ImageIO::closeFrames($this->source); }
        $this->source = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
