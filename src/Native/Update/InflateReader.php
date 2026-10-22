<?php
declare(strict_types=1);

namespace MyPHP\Native\Update;

use MyPHP\Native\Zlib;
use RuntimeException;

/**
 * Raw DEFLATE data in a section of a file, decompressed as it is read.
 *
 * @internal
 */
final class InflateReader
{
    private Zlib $z;
    private string $buf = '';
    private int $pos;
    private int $end;

    /** @param resource $f */
    public function __construct(private $f, int $at, int $length)
    {
        $this->z = Zlib::inflater(Zlib::RAW);
        $this->pos = $at;
        $this->end = $at + $length;
    }

    /** $n bytes, or fewer once the data ends. */
    public function read(int $n): string
    {
        try {
            while (strlen($this->buf) < $n && !$this->z->ended()) {
                if ($this->pos >= $this->end) {
                    $this->buf .= $this->z->finish();
                    break;
                }
                fseek($this->f, $this->pos);
                $chunk = (string)fread($this->f, min(1 << 16, $this->end - $this->pos));
                if ($chunk === '') {
                    throw new DamagedPatch;
                }
                $this->pos += strlen($chunk);
                $this->buf .= $this->z->update($chunk);
            }
        } catch (RuntimeException $e) {
            throw $e instanceof DamagedPatch ? $e : new DamagedPatch;
        }
        $out = substr($this->buf, 0, $n);
        $this->buf = (string)substr($this->buf, strlen($out));
        return $out;
    }
}
