<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use FFI\CData;

/**
 * FFmpeg on Windows (Av::ffi): its types' FFI, whose new() and cast() it
 * has, and its functions, pointers of a struct GetProcAddress filled,
 * called by name as an FFI's are ($f->av_frame_alloc()).
 *
 * @internal Av
 */
final class AvFunctions
{
    /** @param CData $struct the struct of the pointers, kept: $fns reads it through a pointer */
    public function __construct(private readonly FFI $ffi, private readonly CData $struct, private readonly CData $fns) {}

    public function new(string|\FFI\CType $type, bool $owned = true, bool $persistent = false): CData
    {
        return $this->ffi->new($type, $owned, $persistent);
    }

    public function cast(string|\FFI\CType $type, mixed $ptr): CData
    {
        return $this->ffi->cast($type, $ptr);
    }

    /** @param list<mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return ($this->fns->$name)(...$args);
    }
}
