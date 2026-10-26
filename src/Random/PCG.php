<?php
declare(strict_types=1);

namespace MyPHP\Random;

/**
 * Go's math/rand/v2 PCG: a 128-bit permuted congruential generator with
 * the DXSM output, as O'Neill's PCG paper and NumPy's PCG64DXSM. PHP's
 * own PcgOneseq128XslRr64 is another member of the family (another
 * increment and output), so a seed gives other numbers: this one gives
 * Go's, for what a mygo app draws from its seeds to look the same.
 *
 * The numbers are uint64s held in PHP's ints, their bits as they are:
 * past 2^63 an int is negative. U64 does the unsigned arithmetic.
 */
final class PCG
{
    private const MUL_HI = 2549297995355413924, MUL_LO = 4865540595714422341;
    private const INC_HI = 6364136223846793005, INC_LO = 1442695040888963407;
    private const CHEAP_MUL = -2696494805208442699; // 0xda942042e4dd58b5

    /** The state, $hi and $lo, as Go's NewPCG(seed1, seed2) seeds it. */
    public function __construct(private int $hi, private int $lo) {}

    /** The next 64 bits (Go's Uint64): the state advanced, its high half permuted by the low. */
    public function uint64(): int
    {
        // state = state * mul + inc, mod 2^128.
        [$hi, $lo] = U64::mulFull($this->lo, self::MUL_LO);
        $hi = U64::add($hi, U64::add(U64::mul($this->hi, self::MUL_LO), U64::mul($this->lo, self::MUL_HI))[0])[0];
        [$lo, $c] = U64::add($lo, self::INC_LO);
        $hi = U64::add(U64::add($hi, self::INC_HI)[0], $c)[0];
        $this->hi = $hi; $this->lo = $lo;
        // DXSM: double xorshift multiply.
        $hi ^= U64::shr($hi, 32);
        $hi = U64::mul($hi, self::CHEAP_MUL);
        $hi ^= U64::shr($hi, 48);
        return U64::mul($hi, $lo | 1);
    }
}
