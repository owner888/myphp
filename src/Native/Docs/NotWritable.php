<?php
declare(strict_types=1);

namespace MyPHP\Native\Docs;

/** A document's file, or the folder of a new one, that the user may not write (mygo's fs.ErrPermission): saving asks for another file. @internal */
final class NotWritable extends \RuntimeException
{
    public function __construct(string $path)
    {
        parent::__construct("myphp: $path can't be written: permission denied");
    }
}
