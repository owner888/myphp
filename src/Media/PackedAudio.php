<?php
declare(strict_types=1);

namespace MyPHP\Media;

/**
 * An HLS segment of packed audio (RFC 8216 3.4): AAC's ADTS frames, AC-3's,
 * E-AC-3's or MP3's one after the other, with no container, after an ID3
 * tag whose PRIV frame com.apple.streaming.transportStreamTimestamp says
 * the first frame's time (a PTS of 90 kHz, as a TS segment's), as FFmpeg's
 * hls demuxer reads it (Apple's examples' AC-3 renditions, .ac3 files).
 * Read as it comes, a frame a packet.
 *
 * @internal Media\HlsStream
 */
final class PackedAudio
{
    private const OWNER = "com.apple.streaming.transportStreamTimestamp\0";

    private int $pos = 0;
    /** The next frame's time, in 90 kHz ticks; null before the tag is read. */
    private ?float $time = null;
    private ?Track $track = null;
    private ?string $codec = null;
    private bool $end = false;
    private ?Packet $next = null;

    public function __construct(private readonly Bytes $bytes) {}

    /** Whether $head, a segment's first bytes, is packed audio: an ID3 tag, or the sync of a frame of ADTS or AC-3. */
    public static function is(string $head): bool
    {
        return str_starts_with($head, 'ID3') || str_starts_with($head, "\x0B\x77") || strlen($head) >= 2 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0;
    }

    public function ready(): bool
    {
        if ($this->next !== null || $this->end) {
            return true;
        }
        if ($this->time === null) { // the ID3 tag: its timestamp, and past it
            if (!$this->bytes->has(0, 10)) { return false; }
            $h = $this->bytes->read(0, 10);
            $this->time = 0.0;
            if (str_starts_with($h, 'ID3') && strlen($h) === 10) {
                $size = 10 + ((ord($h[6]) & 0x7f) << 21 | (ord($h[7]) & 0x7f) << 14 | (ord($h[8]) & 0x7f) << 7 | (ord($h[9]) & 0x7f));
                if (!$this->bytes->has(0, $size)) { $this->time = null; return false; }
                $tag = $this->bytes->read(0, $size);
                $at = strpos($tag, self::OWNER);
                if ($at !== false && strlen($tag) >= $at + strlen(self::OWNER) + 8) {
                    $this->time = (float)(unpack('J', $tag, $at + strlen(self::OWNER))[1] & (Ts::WRAP - 1));
                }
                $this->pos = $size;
            }
        }
        while (true) {
            if (!$this->bytes->has($this->pos, 8)) { return false; }
            $h = $this->bytes->read($this->pos, 8);
            if (strlen($h) < 7) { $this->end = true; return true; }
            $this->codec ??= str_starts_with($h, "\x0B\x77") ? 'A_AC3' : ((ord($h[1]) & 0x06) === 0 ? 'A_AAC' : 'A_MPEG/L3');
            $f = match ($this->codec) { 'A_AAC' => Elementary::adts($h, 0), 'A_MPEG/L3' => Elementary::mpa($h, 0), default => Elementary::ac3($h, 0) };
            if ($f === null || $f['len'] <= 0) { $this->pos++; continue; } // not at a frame: on to the next sync
            if (!$this->bytes->has($this->pos, $f['len'])) { return false; }
            $s = $this->bytes->read($this->pos, $f['len']);
            $this->pos += $f['len'];
            if ($this->codec === 'A_AAC') {
                $rate = Elementary::AAC_RATES[$f['rate']] ?? 0;
                [$data, $samples, $private] = [substr($s, $f['head']), 1024, Elementary::aacConfig($f['profile'], $f['rate'], $f['channels'])];
            } else {
                [$rate, $data, $samples, $private] = [$f['rate'], $s, $f['samples'], ''];
            }
            if ($rate <= 0) { continue; }
            $this->track ??= new Track(1, Track::AUDIO, ($f['eac3'] ?? false) ? 'A_EAC3' : $this->codec, $private, 'und', '', true, 0.0, 0, 0, (float)$rate, $f['channels']);
            $d = $samples * Ts::CLOCK / $rate;
            $this->next = new Packet($this->track, $this->time / Ts::CLOCK, $d / Ts::CLOCK, true, $data);
            $this->time += $d;
            return true;
        }
    }

    public function next(): ?Packet
    {
        if (!$this->ready()) {
            return null;
        }
        $p = $this->next;
        $this->next = null;
        return $p;
    }
}
