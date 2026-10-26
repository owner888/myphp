<?php
// A pointer's address is ObjC::addr's (v1.28): FFI's cast of a void* to an
// integer type reads what the pointer points to, not the pointer, which
// crashed farm at its first step (an AudioQueueRef points to nothing
// readable). ObjC::addr reads the pointer's own bytes through a slot; no
// source casts a pointer to an integer.
declare(strict_types=1);

namespace MyPHP\Tests;

$ffi = \FFI::cdef('');
$cell = $ffi->new('int64_t');
$cell->cdata = 42;
$slot = $ffi->new('void*[1]');
$slot[0] = \FFI::addr($cell);
same(42, $ffi->cast('int64_t', $slot[0])->cdata, "FFI's cast reads through a void*");
$bytes = $ffi->cast('unsigned long[1]', $slot); // ObjC::addr's slot
check($bytes[0] !== 42 && $bytes[0] !== 0, 'the slot\'s bytes are the address');

$casts = [];
$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../src', \FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (str_ends_with($f->getFilename(), '.php') && preg_match("/cast\\(\\s*'u?intptr_t'/", (string)file_get_contents($f->getPathname())) === 1) {
        $casts[] = $f->getFilename();
    }
}
same([], $casts, 'no source casts a pointer to an integer');
