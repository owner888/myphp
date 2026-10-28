<?php
declare(strict_types=1);

namespace MyPHP\Media;

/** The bits of a byte string, read from the first, most significant first; past the end, zeros. @internal Media\Elementary */
final class Bits
{
    private int $pos = 0;

    public function __construct(private readonly string $s) {}

    public function u(int $n): int
    {
        $v = 0;
        for ($i = 0; $i < $n; $i++, $this->pos++) {
            $byte = ord($this->s[$this->pos >> 3] ?? "\0");
            $v = ($v << 1) | (($byte >> (7 - ($this->pos & 7))) & 1);
        }
        return $v;
    }

    public function skip(int $n): void
    {
        $this->pos += $n;
    }

    /** Exp-Golomb, unsigned. */
    public function ue(): int
    {
        $zeros = 0;
        while ($this->u(1) === 0 && $zeros < 32) { $zeros++; }
        return (1 << $zeros) - 1 + $this->u($zeros);
    }

    /** Exp-Golomb, signed. */
    public function se(): int
    {
        $k = $this->ue();
        return ($k & 1) === 1 ? ($k + 1) >> 1 : -($k >> 1);
    }
}
