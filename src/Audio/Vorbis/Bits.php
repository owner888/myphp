<?php
declare(strict_types=1);

namespace MyPHP\Audio\Vorbis;

/**
 * Reads a packet's bits as Vorbis packs them, from the least significant
 * bit of each byte up. Reading past its end sets $eop and reads zeros,
 * which the decoder takes as the spec's end-of-packet condition.
 * @internal
 */
final class Bits
{
    private int $acc = 0, $n = 0, $p = 0, $len;
    public bool $eop = false;

    public function __construct(private readonly string $d)
    {
        $this->len = strlen($d);
    }

    /** The next $bits bits, at most 32, as an unsigned integer. */
    public function read(int $bits): int
    {
        if ($bits === 0) { return 0; }
        while ($this->n < $bits) {
            if ($this->p >= $this->len) {
                $this->eop = true;
                $this->acc = 0; $this->n = 0;
                return 0;
            }
            $this->acc |= ord($this->d[$this->p++]) << $this->n;
            $this->n += 8;
        }
        $v = $this->acc & ((1 << $bits) - 1);
        $this->acc >>= $bits;
        $this->n -= $bits;
        return $v;
    }

    /** The next $bits bits without taking them, zeros past the end, and how many there are. @return array{0: int, 1: int} */
    public function peek(int $bits): array
    {
        while ($this->n < $bits && $this->p < $this->len) {
            $this->acc |= ord($this->d[$this->p++]) << $this->n;
            $this->n += 8;
        }
        return [$this->acc & ((1 << $bits) - 1), $this->n];
    }

    /** Takes $bits bits that peek() showed. */
    public function skip(int $bits): void
    {
        if ($bits > $this->n) {
            $this->eop = true;
            $this->acc = 0; $this->n = 0;
            return;
        }
        $this->acc >>= $bits;
        $this->n -= $bits;
    }

    /** Whether it reads a flag bit set. */
    public function flag(): bool
    {
        return $this->read(1) === 1;
    }

    /** The number of bits of $v (the spec's ilog). */
    public static function ilog(int $v): int
    {
        $n = 0;
        while ($v > 0) { $n++; $v >>= 1; }
        return $n;
    }

    /** A float of the spec's 32-bit format: a 21-bit mantissa and a 10-bit exponent. */
    public static function float32(int $x): float
    {
        $m = $x & 0x1fffff;
        $e = ($x & 0x7fe00000) >> 21;
        if (($x & 0x80000000) !== 0) { $m = -$m; }
        return $m * 2.0 ** ($e - 788);
    }
}
