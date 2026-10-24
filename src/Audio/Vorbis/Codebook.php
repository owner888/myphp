<?php
declare(strict_types=1);

namespace MyPHP\Audio\Vorbis;

/**
 * A codebook of the setup header (Vorbis I, section 3): the lengths of
 * its entries' Huffman codewords, assigned as the spec does, decoded
 * through a table of the first TABLE bits and a search of the longer
 * codewords; and, for vector quantization, each entry's vector.
 * @internal
 */
final class Codebook
{
    private const TABLE = 10;

    public int $dims = 0, $entries = 0;
    /** @var array<int, int> the entry and its length for each TABLE-bit lookup, as (entry << 8) | length; -1 for a codeword longer than TABLE bits */
    private array $table = [];
    private int $tableBits = 0;
    /** @var list<array{0: int, 1: int, 2: int}> the codewords longer than the table: bit-reversed code, length, entry */
    private array $long = [];
    /** The one entry of a codebook of a single codeword, and its length; -1 otherwise. */
    private int $single = -1, $singleLen = 0;
    /** @var list<list<float>> each entry's vector, for vector quantization; empty for a scalar codebook */
    public array $vectors = [];

    public function __construct(Bits $b)
    {
        if ($b->read(24) !== 0x564342) { throw new \RuntimeException('vorbis: a codebook out of sync'); }
        $this->dims = $b->read(16);
        $this->entries = $n = $b->read(24);
        $lengths = array_fill(0, $n, 0);
        if ($b->flag()) { // ordered: runs of entries of each length
            $entry = 0; $len = $b->read(5) + 1;
            while ($entry < $n) {
                $count = $b->read(Bits::ilog($n - $entry));
                if ($entry + $count > $n) { throw new \RuntimeException('vorbis: a codebook of too many entries'); }
                for ($i = 0; $i < $count; $i++) { $lengths[$entry++] = $len; }
                $len++;
            }
        } else {
            $sparse = $b->flag();
            for ($i = 0; $i < $n; $i++) {
                $lengths[$i] = !$sparse || $b->flag() ? $b->read(5) + 1 : 0; // 0: unused
            }
        }
        $this->huffman($lengths);
        $type = $b->read(4);
        if ($type === 1 || $type === 2) {
            $min = Bits::float32($b->read(32));
            $delta = Bits::float32($b->read(32));
            $bits = $b->read(4) + 1;
            $seq = $b->flag();
            $values = $type === 1 ? self::lookup1($n, $this->dims) : $n * $this->dims;
            $mult = [];
            for ($i = 0; $i < $values; $i++) { $mult[] = $b->read($bits); }
            for ($e = 0; $e < $n; $e++) {
                $v = []; $last = 0.0; $div = 1;
                for ($i = 0; $i < $this->dims; $i++) {
                    $off = $type === 1 ? intdiv($e, $div) % $values : $e * $this->dims + $i;
                    $x = $mult[$off] * $delta + $min + $last;
                    $v[] = $x;
                    if ($seq) { $last = $x; }
                    $div *= $values;
                }
                $this->vectors[] = $v;
            }
        } elseif ($type !== 0) {
            throw new \RuntimeException("vorbis: codebook lookup type $type");
        }
        if ($b->eop) { throw new \RuntimeException('vorbis: the setup header ends in a codebook'); }
    }

    /** The largest r such that r to the power $dims is at most $entries (the spec's lookup1_values). */
    private static function lookup1(int $entries, int $dims): int
    {
        $r = (int)floor($entries ** (1 / $dims));
        while (($r + 1) ** $dims <= $entries) { $r++; }
        while ($r > 0 && $r ** $dims > $entries) { $r--; }
        return $r;
    }

    /** Assigns the codewords as the spec does (libvorbis's _make_words), and builds the lookup. @param list<int> $lengths */
    private function huffman(array $lengths): void
    {
        $used = [];
        foreach ($lengths as $e => $l) { if ($l > 0) { $used[] = $e; } }
        if (count($used) === 1) { // one codeword: whatever its bits, that entry
            $this->single = $used[0]; $this->singleLen = $lengths[$used[0]];
            return;
        }
        $marker = array_fill(0, 33, 0);
        $codes = [];
        foreach ($lengths as $e => $len) {
            if ($len === 0) { continue; }
            $entry = $marker[$len];
            if ($len < 32 && ($entry >> $len) !== 0) { throw new \RuntimeException('vorbis: a codebook of too many codewords'); }
            $codes[$e] = $entry;
            for ($j = $len; $j > 0; $j--) {
                if (($marker[$j] & 1) !== 0) {
                    $marker[$j] = $j === 1 ? $marker[1] + 1 : $marker[$j - 1] << 1;
                    break;
                }
                $marker[$j]++;
            }
            for ($j = $len + 1; $j < 33; $j++) {
                if (($marker[$j] >> 1) === $entry) {
                    $entry = $marker[$j];
                    $marker[$j] = $marker[$j - 1] << 1;
                } else {
                    break;
                }
            }
        }
        $max = $codes === [] ? 0 : max(array_intersect_key($lengths, $codes));
        $this->tableBits = $t = min($max, self::TABLE);
        $this->table = array_fill(0, 1 << $t, -1);
        foreach ($codes as $e => $code) {
            $len = $lengths[$e];
            $rev = 0;
            for ($i = 0; $i < $len; $i++) { $rev |= (($code >> ($len - 1 - $i)) & 1) << $i; } // the first bit read is the codeword's highest
            if ($len <= $t) {
                for ($fill = $rev; $fill < (1 << $t); $fill += 1 << $len) { $this->table[$fill] = ($e << 8) | $len; }
            } else {
                $this->long[] = [$rev, $len, $e];
            }
        }
        usort($this->long, fn($a, $b) => $a[1] <=> $b[1]);
    }

    /** Decodes an entry; -1 at the end of the packet. */
    public function decode(Bits $b): int
    {
        if ($this->single >= 0) {
            $b->read($this->singleLen);
            return $b->eop ? -1 : $this->single;
        }
        [$v, $have] = $b->peek($this->tableBits);
        $hit = $this->table[$v] ?? -1;
        if ($hit >= 0) {
            $len = $hit & 0xff;
            if ($len > $have) { $b->skip($len); return -1; }
            $b->skip($len);
            return $hit >> 8;
        }
        foreach ($this->long as [$rev, $len, $e]) {
            [$v, $have] = $b->peek($len);
            if ($v === $rev) {
                if ($len > $have) { break; }
                $b->skip($len);
                return $e;
            }
        }
        $b->skip(64); // no codeword, or the packet ends: end of packet
        return -1;
    }
}
