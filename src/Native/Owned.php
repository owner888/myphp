<?php
declare(strict_types=1);

namespace MyPHP\Native;

/**
 * An Objective-C object held by the PHP value that owns it, released when
 * that goes: a texture kept with the image or canvas it holds the pixels
 * of, in a WeakMap, released with it.
 *
 * @internal
 */
final class Owned
{
    public function __construct(public readonly mixed $id) {}

    public function __destruct()
    {
        ObjC::msg('v:', $this->id, 'release');
    }
}
