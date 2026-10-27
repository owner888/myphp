<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI\CData;

/**
 * A CMSampleBuffer a system reader made (AssetReader), owned: a packet
 * holds it until the renderers take it, and it is released with the
 * packet.
 *
 * @internal
 */
final class SampleBuffer
{
    public function __construct(public readonly CData $buffer) {}

    public function __destruct()
    {
        CoreMedia::c()->CFRelease($this->buffer);
    }
}
