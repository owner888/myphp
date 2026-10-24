<?php
declare(strict_types=1);

namespace MyPHP\Audio\Vorbis;

/**
 * Reads the packets of the first logical stream of an Ogg file (RFC 3533):
 * pages, whose segments' lacing joins packets across pages. Each packet
 * comes with the granule position of the page it ends on when it is the
 * last that ends there (-1 otherwise), and whether that page ends the
 * stream. Pages of other streams are skipped; checksums are not checked.
 * @internal
 */
final class Ogg
{
    /** The stream's serial number, once its first page was read. */
    private ?int $serial = null;
    /** @var list<array{0: string, 1: int, 2: bool}> packets read off the page and not yet taken: data, granule, end of stream */
    private array $ready = [];
    /** A packet begun on a page before, which goes on on the next. */
    private string $partial = '';
    /** Whether the next page's first packet goes on from the last page's, which a reader starting there skips. */
    private bool $skipContinued = false;
    public bool $eos = false;

    /** @param resource $r */
    public function __construct(private $r, private readonly int $base) {}

    /** Reads from the page at byte $offset of the file, dropping a packet that began before it. */
    public function seekPage(int $offset): void
    {
        fseek($this->r, $this->base + $offset);
        $this->ready = []; $this->partial = ''; $this->skipContinued = true; $this->eos = false;
    }

    /** Where the next page is read from, in bytes of the file. */
    public function tell(): int
    {
        return (int)ftell($this->r) - $this->base;
    }

    /**
     * Reads a page's header at the stream's position: [granule, serial,
     * flags, body length, header length], or null at the end; the body is
     * not read.
     * @return ?array{0: int, 1: int, 2: int, 3: int, 4: int, 5: string}
     */
    private function header(): ?array
    {
        $h = (string)fread($this->r, 27);
        if (strlen($h) < 27) { return null; }
        if (substr($h, 0, 4) !== 'OggS') {
            throw new \RuntimeException('vorbis: lost the Ogg pages');
        }
        $flags = ord($h[5]);
        $granule = unpack('P', $h, 6)[1]; // -1 (all ones) for a page on which no packet ends
        $serial = unpack('V', $h, 14)[1];
        $n = ord($h[26]);
        $lacing = $n > 0 ? (string)fread($this->r, $n) : '';
        $len = 0;
        for ($i = 0; $i < $n; $i++) { $len += ord($lacing[$i]); }
        return [$granule, $serial, $flags, $len, 27 + $n, $lacing];
    }

    /**
     * The granule positions of the pages, and where each page starts,
     * reading headers alone, from byte $from: what seeking bisects.
     * @return list<array{0: int, 1: int}> [offset, granule]
     */
    public function pages(int $from): array
    {
        fseek($this->r, $this->base + $from);
        $out = [];
        while (true) {
            $at = (int)ftell($this->r) - $this->base;
            $h = $this->header();
            if ($h === null) { break; }
            if ($this->serial === null || $h[1] === $this->serial) { $out[] = [$at, $h[0]]; }
            fseek($this->r, $h[3], SEEK_CUR);
            if (($h[2] & 4) !== 0 && $h[1] === $this->serial) { break; }
        }
        return $out;
    }

    /** The granule position of the stream's last page: its length in frames, or -1. */
    public function lastGranule(): int
    {
        $size = (int)(fstat($this->r)['size'] ?? 0) - $this->base;
        $last = -1;
        for ($back = 65536; $last < 0 && $back < 4 * 1048576; $back *= 4) {
            $from = max(0, $size - $back);
            fseek($this->r, $this->base + $from);
            $tail = (string)fread($this->r, $size - $from);
            for ($i = strrpos($tail, 'OggS'); $i !== false; $i = $i > 0 ? strrpos(substr($tail, 0, $i), 'OggS') : false) {
                if (strlen($tail) - $i >= 27 && unpack('V', $tail, $i + 14)[1] === $this->serial) {
                    $g = unpack('P', $tail, $i + 6)[1];
                    if ($g >= 0) { $last = $g; break; }
                }
            }
            if ($from === 0) { break; }
        }
        return $last;
    }

    /**
     * The next packet: [data, granule, end of stream], or null at the end.
     * @return ?array{0: string, 1: int, 2: bool}
     */
    public function packet(): ?array
    {
        while ($this->ready === []) {
            if ($this->eos) { return null; }
            $h = $this->header();
            if ($h === null) { return null; }
            [$granule, $serial, $flags, $len, , $lacing] = $h;
            $body = $len > 0 ? (string)fread($this->r, $len) : '';
            $this->serial ??= $serial;
            if ($serial !== $this->serial) { continue; } // another stream's
            $continued = ($flags & 1) !== 0;
            if (!$continued) { $this->partial = ''; }
            $skip = $this->skipContinued && $continued; // a packet begun before where reading started
            $this->skipContinued = false;
            $at = 0; $ends = [];
            for ($i = 0, $n = strlen($lacing); $i < $n; $i++) {
                $seg = ord($lacing[$i]);
                $this->partial .= substr($body, $at, $seg);
                $at += $seg;
                if ($seg < 255) {
                    if ($skip) { $skip = false; } else { $ends[] = $this->partial; }
                    $this->partial = '';
                }
            }
            if ($skip) { $this->partial = ''; } // still the packet begun before
            $last = count($ends) - 1;
            foreach ($ends as $k => $p) { $this->ready[] = [$p, $k === $last ? $granule : -1, $k === $last && ($flags & 4) !== 0]; }
            if (($flags & 4) !== 0) { $this->eos = true; }
        }
        return array_shift($this->ready);
    }
}
