<?php
// Native\Paths: absolute paths as the system has them (Go's filepath.IsAbs),
// relative ones put under a base; a file's path through a transfer's URL and
// back, on Windows a drive's and a share's too.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Native\Paths;
use MyPHP\Transfer\Data;

if (PHP_OS_FAMILY === 'Windows') {
    same([true, true, true, true, false, false], array_map(fn(string $p) => Paths::isAbsolute($p), ['C:\\x', 'c:/x', '\\\\server\\share', '\\x', 'x\\y', 'C:x']), 'a drive\'s, a share\'s, the current drive\'s root; not a relative one, nor C:x');
    same('C:\\base\\x\\y', Paths::absolute('x\\y', 'C:\\base\\'), 'a relative path under its base');
    same(['C:\\Users\\Ada\\a b.txt', '\\\\server\\share\\c.txt'], Data::files('C:\\Users\\Ada\\a b.txt', '\\\\server\\share\\c.txt')->paths(), 'a drive\'s and a share\'s file through file URLs and back');
    same([], Data::url('file://server/share/c.txt')->paths(), 'a URL of a host is none, as mygo has it');
} else {
    same([true, false, false], array_map(fn(string $p) => Paths::isAbsolute($p), ['/x', 'x/y', 'C:\\x']), 'from / alone');
    same('/base/x/y', Paths::absolute('x/y', '/base/'), 'a relative path under its base');
    same(['/tmp/a b.txt'], Data::files('/tmp/a b.txt')->paths(), 'a file through its URL and back');
}
same('/abs', Paths::absolute('/abs', '/base'), 'an absolute one as it is');
