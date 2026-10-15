<?php
declare(strict_types=1);

namespace MyPHP;

final class FileFilter
{
    /** @param string[] $extensions without dots */
    public function __construct(public string $name, public array $extensions) {}
}
