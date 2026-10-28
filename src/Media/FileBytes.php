<?php
declare(strict_types=1);

namespace MyPHP\Media;

use RuntimeException;

/** A file's bytes: all here. */
final class FileBytes implements Bytes
{
    /** @var resource */
    private $f;
    private int $size;

    public function __construct(string $path)
    {
        $f = @fopen($path, 'rb');
        if ($f === false) {
            throw new RuntimeException("cannot open $path");
        }
        $this->f = $f;
        $this->size = (int)fstat($f)['size'];
    }

    public function size(): int
    {
        return $this->size;
    }

    public function read(int $pos, int $n): string
    {
        fseek($this->f, $pos);
        $s = $n > 0 ? fread($this->f, $n) : '';
        return $s === false ? '' : $s;
    }

    public function has(int $pos, int $n): bool
    {
        return true;
    }

    public function __destruct()
    {
        fclose($this->f);
    }
}
