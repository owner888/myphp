<?php
declare(strict_types=1);

namespace MyPHP\Audio\Vorbis;

/**
 * The Vorbis I codec of a stream, its packets in and its frames out:
 * made of its three headers (identification, comment, setup), each audio
 * packet decoded into the frames it completes, its blocks overlapped as
 * they come. Ogg files are read by Decoder; Matroska's A_VORBIS tracks
 * give their headers laced in CodecPrivate (fromXiph).
 *
 * @internal Audio\Vorbis\Decoder, Native\SoftAudio
 */
final class Codec
{
    private int $channels = 0, $rate = 0, $bs0 = 0, $bs1 = 0;
    /** @var list<Codebook> */
    private array $books = [];
    /** @var list<array> floors of type 1: partitions, classes, multiplier, x list and their order */
    private array $floors = [];
    /** @var list<array> residues: type, begin, end, partition size, classifications, classbook, books */
    private array $residues = [];
    /** @var list<array> mappings: submaps, coupling, mux, each submap's floor and residue */
    private array $mappings = [];
    /** @var list<array{0: bool, 1: int}> modes: long block, mapping */
    private array $modes = [];
    /** @var array<int, list<float>> the rising slope of the window of half of each block size */
    private array $slopes = [];
    /** The right part of the last block decoded, windowed, of each channel, waiting for the next block's left; and the last block's size, 0 before the first. */
    private array $overlap = [];
    private int $prevN = 0;
    /** The floor1 inverse dB table of the spec (floor1_inverse_dB_table). @var list<float> */
    private static array $dB = [];

    /** The codec of header packets $id, $comment and $setup. */
    public function __construct(string $id, string $comment, string $setup)
    {
        if (self::$dB === []) {
            for ($i = 0; $i < 256; $i++) { self::$dB[] = 1.0649863e-07 * 1.0649863 ** $i; } // the table's ratio, 0.547 dB a step, from 1.0649863e-07 to 1
        }
        if (substr($id, 0, 7) !== "\x01vorbis") { throw new \RuntimeException('vorbis: not a Vorbis stream'); }
        $b = new Bits(substr($id, 7));
        if ($b->read(32) !== 0) { throw new \RuntimeException('vorbis: a version other than Vorbis I'); }
        $this->channels = $b->read(8);
        $this->rate = $b->read(32);
        $b->read(32); $b->read(32); $b->read(32); // bitrates
        $this->bs0 = 1 << $b->read(4);
        $this->bs1 = 1 << $b->read(4);
        if ($this->channels < 1 || $this->rate < 1 || $this->bs0 < 64 || $this->bs1 < $this->bs0 || $this->bs1 > 8192) {
            throw new \RuntimeException('vorbis: an invalid identification header');
        }
        if (substr($comment, 0, 7) !== "\x03vorbis") { throw new \RuntimeException('vorbis: no comment header'); }
        if (substr($setup, 0, 7) !== "\x05vorbis") { throw new \RuntimeException('vorbis: no setup header'); }
        $this->setup(new Bits(substr($setup, 7)));
        foreach ([$this->bs0, $this->bs1] as $n) {
            $h = $n >> 1; $s = [];
            for ($i = 0; $i < $h; $i++) { $x = sin(($i + 0.5) / $h * M_PI / 2); $s[] = sin(M_PI / 2 * $x * $x); }
            $this->slopes[$h] = $s;
        }
    }

    /**
     * The codec of a Matroska A_VORBIS track's CodecPrivate: its three
     * headers in Xiph lacing (the count less one, the sizes of the first
     * two in runs of 255, then the three).
     */
    public static function fromXiph(string $private): self
    {
        if ($private === '' || ord($private[0]) !== 2) {
            throw new \RuntimeException('vorbis: no three headers laced in the track');
        }
        $at = 1; $sizes = [];
        for ($k = 0; $k < 2; $k++) {
            $n = 0;
            do { $b = ord($private[$at++] ?? throw new \RuntimeException('vorbis: the headers\' lacing cut short')); $n += $b; } while ($b === 255);
            $sizes[] = $n;
        }
        if ($at + $sizes[0] + $sizes[1] > strlen($private)) {
            throw new \RuntimeException('vorbis: the headers cut short');
        }
        return new self(substr($private, $at, $sizes[0]), substr($private, $at + $sizes[0], $sizes[1]), substr($private, $at + $sizes[0] + $sizes[1]));
    }

    public function channels(): int { return $this->channels; }
    public function rate(): int { return $this->rate; }

    /** The frames of a long block: how far before a frame decoding starts to have it whole. */
    public function longBlock(): int { return $this->bs1; }

    /** Forgets the block before: the next packet decoded starts afresh (after a seek), completing no frames. */
    public function reset(): void
    {
        $this->overlap = []; $this->prevN = 0;
    }

    private function setup(Bits $b): void
    {
        for ($i = 0, $n = $b->read(8) + 1; $i < $n; $i++) { $this->books[] = new Codebook($b); }
        for ($i = 0, $n = $b->read(6) + 1; $i < $n; $i++) {
            if ($b->read(16) !== 0) { throw new \RuntimeException('vorbis: a time domain transform'); }
        }
        for ($i = 0, $n = $b->read(6) + 1; $i < $n; $i++) {
            $type = $b->read(16);
            if ($type !== 1) { throw new \RuntimeException("vorbis: floor type $type"); }
            $this->floors[] = $this->floor1($b);
        }
        for ($i = 0, $n = $b->read(6) + 1; $i < $n; $i++) {
            $type = $b->read(16);
            if ($type > 2) { throw new \RuntimeException("vorbis: residue type $type"); }
            $r = ['type' => $type, 'begin' => $b->read(24), 'end' => $b->read(24), 'size' => $b->read(24) + 1, 'classes' => $b->read(6) + 1, 'classbook' => $b->read(8)];
            $cascade = [];
            for ($c = 0; $c < $r['classes']; $c++) {
                $low = $b->read(3);
                $cascade[] = ($b->flag() ? $b->read(5) << 3 : 0) | $low;
            }
            $r['books'] = [];
            for ($c = 0; $c < $r['classes']; $c++) {
                $books = [];
                for ($j = 0; $j < 8; $j++) { $books[] = ($cascade[$c] >> $j) & 1 ? $b->read(8) : -1; }
                $r['books'][] = $books;
            }
            $this->residues[] = $r;
        }
        for ($i = 0, $n = $b->read(6) + 1; $i < $n; $i++) {
            if ($b->read(16) !== 0) { throw new \RuntimeException('vorbis: a mapping of a type other than 0'); }
            $submaps = $b->flag() ? $b->read(4) + 1 : 1;
            $coupling = [];
            if ($b->flag()) {
                $bits = Bits::ilog($this->channels - 1);
                for ($s = 0, $steps = $b->read(8) + 1; $s < $steps; $s++) { $coupling[] = [$b->read($bits), $b->read($bits)]; }
            }
            if ($b->read(2) !== 0) { throw new \RuntimeException('vorbis: reserved mapping bits set'); }
            $mux = array_fill(0, $this->channels, 0);
            if ($submaps > 1) { for ($c = 0; $c < $this->channels; $c++) { $mux[$c] = $b->read(4); } }
            $subs = [];
            for ($s = 0; $s < $submaps; $s++) { $b->read(8); $subs[] = [$b->read(8), $b->read(8)]; }
            $this->mappings[] = ['coupling' => $coupling, 'mux' => $mux, 'submaps' => $subs];
        }
        for ($i = 0, $n = $b->read(6) + 1; $i < $n; $i++) {
            $long = $b->flag();
            $b->read(16); $b->read(16); // window and transform types: 0
            $this->modes[] = [$long, $b->read(8)];
        }
        if ($b->eop || !$b->flag()) { throw new \RuntimeException('vorbis: an invalid setup header'); }
    }

    private function floor1(Bits $b): array
    {
        $partitions = $b->read(5);
        $classOf = []; $max = -1;
        for ($i = 0; $i < $partitions; $i++) { $classOf[] = $c = $b->read(4); $max = max($max, $c); }
        $classes = [];
        for ($c = 0; $c <= $max; $c++) {
            $dims = $b->read(3) + 1;
            $sub = $b->read(2);
            $master = $sub > 0 ? $b->read(8) : -1;
            $books = [];
            for ($j = 0; $j < (1 << $sub); $j++) { $books[] = $b->read(8) - 1; }
            $classes[] = ['dims' => $dims, 'sub' => $sub, 'master' => $master, 'books' => $books];
        }
        $mult = $b->read(2) + 1;
        $bits = $b->read(4);
        $x = [0, 1 << $bits];
        foreach ($classOf as $c) { for ($j = 0; $j < $classes[$c]['dims']; $j++) { $x[] = $b->read($bits); } }
        $order = array_keys($x);
        usort($order, fn($a, $b) => $x[$a] <=> $x[$b]);
        $low = []; $high = []; // each point's neighbors before it: the nearest below and above (low_neighbor, high_neighbor)
        for ($i = 2, $n = count($x); $i < $n; $i++) {
            $lo = 0; $hi = 1;
            for ($j = 0; $j < $i; $j++) {
                if ($x[$j] < $x[$i] && $x[$j] > $x[$lo]) { $lo = $j; }
                if ($x[$j] > $x[$i] && $x[$j] < $x[$hi]) { $hi = $j; }
            }
            $low[$i] = $lo; $high[$i] = $hi;
        }
        return ['classOf' => $classOf, 'classes' => $classes, 'mult' => $mult, 'x' => $x, 'order' => $order, 'low' => $low, 'high' => $high];
    }

    /**
     * Decodes an audio packet (Vorbis I, section 4.3) and returns the
     * frames it completes, interleaved: none for the first after a start.
     * @return list<float>
     */
    public function decode(string $data): array
    {
        $b = new Bits($data);
        if ($b->read(1) !== 0) { return []; } // not audio
        $mode = $this->modes[$b->read(Bits::ilog(count($this->modes) - 1))] ?? null;
        if ($mode === null || $b->eop) { return []; }
        [$long, $mi] = $mode;
        $n = $long ? $this->bs1 : $this->bs0;
        $prevLong = $nextLong = true;
        if ($long) { $prevLong = $b->flag(); $nextLong = $b->flag(); }
        $half = $n >> 1;
        $map = $this->mappings[$mi];
        $ch = $this->channels;
        // The floors.
        $floors = []; $zero = [];
        for ($c = 0; $c < $ch; $c++) {
            $f = $this->floors[$map['submaps'][$map['mux'][$c]][0]];
            $floors[$c] = $this->floorDecode($b, $f);
            $zero[$c] = $floors[$c] === null;
            if (!$zero[$c]) { $floors[$c] = $this->curve($floors[$c], $half); }
        }
        $noResidue = $zero;
        foreach ($map['coupling'] as [$m, $a]) { // a pair is decoded if either is not silent
            if (!$zero[$m] || !$zero[$a]) { $noResidue[$m] = $noResidue[$a] = false; }
        }
        // The residues, by submap.
        $vec = array_fill(0, $ch, null);
        foreach ($map['submaps'] as $s => [, $ri]) {
            $chs = [];
            for ($c = 0; $c < $ch; $c++) { if ($map['mux'][$c] === $s) { $chs[] = $c; } }
            $decoded = $this->residue($b, $this->residues[$ri], $half, array_map(fn($c) => $noResidue[$c], $chs));
            foreach ($chs as $k => $c) { $vec[$c] = $decoded[$k]; }
        }
        // Inverse coupling.
        for ($i = count($map['coupling']) - 1; $i >= 0; $i--) {
            [$mc, $ac] = $map['coupling'][$i];
            $M = $vec[$mc]; $A = $vec[$ac];
            for ($j = 0; $j < $half; $j++) {
                $m = $M[$j]; $a = $A[$j];
                if ($m > 0) {
                    if ($a > 0) { $A[$j] = $m - $a; } else { $A[$j] = $m; $M[$j] = $m + $a; }
                } else {
                    if ($a > 0) { $A[$j] = $m + $a; } else { $A[$j] = $m; $M[$j] = $m - $a; }
                }
            }
            $vec[$mc] = $M; $vec[$ac] = $A;
        }
        // The window's slopes, as the blocks around this one are long or short.
        $leftN = $long && !$prevLong ? $this->bs0 >> 1 : $half;
        $rightN = $long && !$nextLong ? $this->bs0 >> 1 : $half;
        $leftStart = ($n >> 2) - ($leftN >> 1);
        $rightStart = ($n * 3 >> 2) - ($rightN >> 1);
        $up = $this->slopes[$leftN]; $down = $this->slopes[$rightN];
        $first = $this->prevN === 0;
        $outs = [];
        for ($c = 0; $c < $ch; $c++) {
            if ($zero[$c]) {
                $y = array_fill(0, $n, 0.0);
            } else {
                $v = $vec[$c]; $f = $floors[$c];
                for ($j = 0; $j < $half; $j++) { $v[$j] *= $f[$j]; }
                $y = Mdct::inverse($v);
            }
            for ($i = 0; $i < $leftStart; $i++) { $y[$i] = 0.0; }
            for ($i = 0; $i < $leftN; $i++) { $y[$leftStart + $i] *= $up[$i]; }
            for ($i = 0; $i < $rightN; $i++) { $y[$rightStart + $i] *= $down[$rightN - 1 - $i]; }
            if (!$first) {
                $o = $this->overlap[$c];
                for ($i = 0, $m = count($o); $i < $m; $i++) { $y[$leftStart + $i] += $o[$i]; }
            }
            $outs[$c] = array_slice($y, $leftStart, $rightStart - $leftStart);
            $this->overlap[$c] = array_slice($y, $rightStart, $rightN);
        }
        $this->prevN = $n;
        if ($first) { return []; }
        $frames = $rightStart - $leftStart;
        if ($ch === 1) { return $outs[0]; }
        $inter = [];
        for ($i = 0; $i < $frames; $i++) { for ($c = 0; $c < $ch; $c++) { $inter[] = $outs[$c][$i]; } }
        return $inter;
    }

    /**
     * Decodes a channel's floor of type 1 (section 7.2): its points'
     * amplitudes and which are used, for curve(); null for a channel
     * silent in this block, or at the end of the packet.
     * @return ?array{0: list<int>, 1: list<bool>, 2: array}
     */
    private function floorDecode(Bits $b, array $f): ?array
    {
        if (!$b->flag()) { return null; }
        $range = [256, 128, 86, 64][$f['mult'] - 1];
        $bits = Bits::ilog($range - 1);
        $y = [$b->read($bits), $b->read($bits)];
        foreach ($f['classOf'] as $cls) {
            $c = $f['classes'][$cls];
            $cbits = $c['sub']; $csub = (1 << $cbits) - 1; $cval = 0;
            if ($cbits > 0) { $cval = $this->books[$c['master']]->decode($b); }
            for ($j = 0; $j < $c['dims']; $j++) {
                $book = $c['books'][$cval & $csub];
                $cval >>= $cbits;
                $y[] = $book >= 0 ? $this->books[$book]->decode($b) : 0;
            }
        }
        if ($b->eop) { return null; }
        // Amplitude synthesis: each point predicted from its neighbors, then moved by what was read.
        $x = $f['x']; $count = count($x);
        $final = [$y[0], $y[1]]; $step2 = [true, true];
        for ($i = 2; $i < $count; $i++) {
            $lo = $f['low'][$i]; $hi = $f['high'][$i];
            $pred = self::renderPoint($x[$lo], $final[$lo], $x[$hi], $final[$hi], $x[$i]);
            $val = $y[$i];
            $highroom = $range - $pred; $lowroom = $pred;
            $room = ($highroom < $lowroom ? $highroom : $lowroom) * 2;
            if ($val !== 0) {
                $step2[$lo] = $step2[$hi] = true; $step2[$i] = true;
                if ($val >= $room) {
                    $final[$i] = $highroom > $lowroom ? $val - $lowroom + $pred : $pred - $val + $highroom - 1;
                } else {
                    $final[$i] = ($val & 1) ? $pred - (($val + 1) >> 1) : $pred + ($val >> 1);
                }
            } else {
                $step2[$i] = false;
                $final[$i] = $pred;
            }
        }
        return [$final, $step2, $f]; // curve() renders it
    }

    /** The spec's render_point. */
    private static function renderPoint(int $x0, int $y0, int $x1, int $y1, int $x): int
    {
        $dy = $y1 - $y0; $adx = $x1 - $x0; $ady = abs($dy);
        $off = intdiv($ady * ($x - $x0), $adx);
        return $dy < 0 ? $y0 - $off : $y0 + $off;
    }

    /** The floor's curve over $half values, as gains (the spec's floor1 step 2 and render_line). @return list<float> */
    private function curve(array $floor, int $half): array
    {
        [$final, $step2, $f] = $floor;
        $mult = $f['mult']; $x = $f['x'];
        $v = array_fill(0, $half, 0);
        $lx = 0; $ly = $final[$f['order'][0]] * $mult; $hx = 0; $hy = $ly;
        foreach ($f['order'] as $k => $i) {
            if ($k === 0 || !$step2[$i]) { continue; }
            $hy = $final[$i] * $mult; $hx = $x[$i];
            self::renderLine($lx, $ly, $hx, $hy, $v, $half);
            $lx = $hx; $ly = $hy;
        }
        if ($hx < $half) { self::renderLine($hx, $hy, $half, $hy, $v, $half); }
        $out = [];
        foreach ($v as $i => $d) { $out[] = self::$dB[max(0, min(255, $d))]; }
        return $out;
    }

    /** The spec's render_line, into $v below $n. */
    private static function renderLine(int $x0, int $y0, int $x1, int $y1, array &$v, int $n): void
    {
        $dy = $y1 - $y0; $adx = $x1 - $x0;
        if ($adx <= 0) { return; }
        $ady = abs($dy);
        $base = intdiv($dy, $adx);
        $sy = $dy < 0 ? $base - 1 : $base + 1;
        $ady -= abs($base) * $adx;
        $y = $y0; $err = 0;
        if ($x0 < $n) { $v[$x0] = $y; }
        for ($xx = $x0 + 1; $xx < $x1 && $xx < $n; $xx++) {
            $err += $ady;
            if ($err >= $adx) { $err -= $adx; $y += $sy; } else { $y += $base; }
            $v[$xx] = $y;
        }
    }

    /**
     * Decodes a submap's residue (section 8) for its channels, $skip
     * saying which are not decoded, into vectors of $half values each.
     * @param list<bool> $skip
     * @return list<list<float>>
     */
    private function residue(Bits $b, array $r, int $half, array $skip): array
    {
        $ch = count($skip);
        if ($r['type'] === 2) { // the channels interleaved, decoded as one vector of type 1
            $out = array_fill(0, $ch, array_fill(0, $half, 0.0));
            if (!in_array(false, $skip, true)) { return $out; }
            [$big] = $this->residueVectors($b, $r, $half * $ch, [false], 1);
            for ($i = 0, $n = $half * $ch; $i < $n; $i++) { $out[$i % $ch][intdiv($i, $ch)] = $big[$i]; }
            return $out;
        }
        return $this->residueVectors($b, $r, $half, $skip, $r['type']);
    }

    /** @param list<bool> $skip @return list<list<float>> */
    private function residueVectors(Bits $b, array $r, int $size, array $skip, int $type): array
    {
        $ch = count($skip);
        $v = array_fill(0, $ch, array_fill(0, $size, 0.0));
        $begin = min($r['begin'], $size); $end = min($r['end'], $size);
        $psize = $r['size'];
        $parts = intdiv($end - $begin, $psize);
        if ($parts <= 0) { return $v; }
        $classbook = $this->books[$r['classbook']];
        $words = $classbook->dims;
        $classes = $r['classes'];
        $cls = array_fill(0, $ch, []);
        for ($pass = 0; $pass < 8; $pass++) {
            $p = 0;
            while ($p < $parts) {
                if ($pass === 0) {
                    for ($j = 0; $j < $ch; $j++) {
                        if ($skip[$j]) { continue; }
                        $t = $classbook->decode($b);
                        if ($t < 0) { return $v; } // the end of the packet
                        for ($i = $words - 1; $i >= 0; $i--) { $cls[$j][$p + $i] = $t % $classes; $t = intdiv($t, $classes); }
                    }
                }
                for ($i = 0; $i < $words && $p < $parts; $i++, $p++) {
                    for ($j = 0; $j < $ch; $j++) {
                        if ($skip[$j]) { continue; }
                        $book = $r['books'][$cls[$j][$p]][$pass];
                        if ($book < 0) { continue; }
                        $cb = $this->books[$book];
                        $off = $begin + $p * $psize;
                        $dims = $cb->dims;
                        if ($type === 0) {
                            $step = intdiv($psize, $dims);
                            for ($k = 0; $k < $step; $k++) {
                                $e = $cb->decode($b);
                                if ($e < 0) { return $v; }
                                $vq = $cb->vectors[$e];
                                for ($d = 0; $d < $dims; $d++) { $v[$j][$off + $k + $d * $step] += $vq[$d]; }
                            }
                        } else {
                            for ($k = 0; $k < $psize;) {
                                $e = $cb->decode($b);
                                if ($e < 0) { return $v; }
                                $vq = $cb->vectors[$e];
                                for ($d = 0; $d < $dims && $k < $psize; $d++, $k++) { $v[$j][$off + $k] += $vq[$d]; }
                            }
                        }
                    }
                }
            }
        }
        return $v;
    }
}
