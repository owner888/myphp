<?php
declare(strict_types=1);

namespace MyPHP\Media;

use RuntimeException;

/**
 * MPEG transport streams (ISO/IEC 13818-1) read in PHP, as FFmpeg's mpegts
 * demuxer reads them: a .ts, .m2ts or .mts file, or a segment of HLS. The
 * program's table (PAT, PMT) names its streams; each stream's PES packets
 * are gathered as their TS packets come and made Matroska's frames
 * (Media\Elementary): H.264 and HEVC as lengths and NAL units, their
 * parameter sets the track's avcC or hvcC, a video frame's duration the
 * next one's decode time less its own; AAC (ADTS), AC-3, E-AC-3 and MP3 a
 * frame a packet. A file's times start at its first (FFmpeg's start_time),
 * a segment's are the stream's own (Media\Hls lines the segments up).
 * A seek goes to the keyframe before through a binary search of the
 * file's times, as FFmpeg's generic seek does.
 */
final class Ts implements Source
{
    /** A PTS is of 90 kHz, of 33 bits. */
    public const CLOCK = 90000, WRAP = 1 << 33;
    /** The TS packets read at once, when they are here. */
    private const BATCH = 512;
    /** The stream types FFmpeg's mpegts maps to what the player decodes: Matroska's codec ids (AC-3's of DVB by descriptors). */
    private const TYPES = [0x1B => 'V_MPEG4/ISO/AVC', 0x24 => 'V_MPEGH/ISO/HEVC', 0x0F => 'A_AAC', 0x03 => 'A_MPEG/L3', 0x04 => 'A_MPEG/L3', 0x81 => 'A_AC3', 0x87 => 'A_EAC3'];

    private Bytes $bytes;
    /** The size of a packet (188, 192 of a Blu-ray's .m2ts with its 4-byte timecode first) and where its sync byte is. */
    private int $size = 188, $sync = 0;
    /** The next packet read. */
    private int $pos = 0;
    private bool $end = false;
    /** @var array<int, true> the PIDs of the programs' tables */
    private array $pmts = [];
    /** @var array<int, array{codec: string, language: string}> the streams read, by PID */
    private array $streams = [];
    /** @var array<int, array{data: string, pts: ?int, dts: ?int, want: int, rai: bool}> each stream's PES gathered so far, by PID */
    private array $pes = [];
    /** @var array<int, Track> each stream's track, made once what it decodes from is known, by PID */
    private array $tracks = [];
    /** @var array<int, array<string, string>> the parameter sets each video stream had, by PID and NAL type */
    private array $params = [];
    /**
     * @var array<int, array{buf: string, at: int, times: list<array{0: int, 1: int, 2: int}>, au: list<string>, auAt: int, slice: bool}>
     * each video stream's bytes not yet made NAL units (from $at of them), the PES times (where each started, its PTS and
     * DTS) not yet given, the access unit gathered (where it starts, whether it has a slice), by PID
     */
    private array $es = [];
    /** @var array<int, array{0: int, 1: int}> each video stream's last frame's PTS and DTS, by PID */
    private array $previous = [];
    /** @var array<int, array{0: int, 1: int, 2: bool, 3: string}> each video stream's frame read, held for the next one's decode time: its PTS, DTS, whether a keyframe, its data */
    private array $held = [];
    /** @var array<int, int> each video stream's last frame duration, in the clock's ticks */
    private array $last = [];
    /** @var list<array{0: Track, 1: int, 2: int, 3: bool, 4: string}> the frames made, not yet read: track, PTS, duration (ticks), keyframe, data */
    private array $queue = [];
    /** @var array<int, true> the video streams whose frames wait for a keyframe (after a seek), by PID */
    private array $waitKey = [];
    /** The first PTS, a file's time 0 (null: a segment's, its times the stream's own). */
    private ?int $origin = null;
    private float $duration = 0.0;
    /** Where the times went past a wrap of 33 bits: the last PTS given, ticks added. */
    private ?int $lastPts = null;
    private int $wraps = 0;

    /** Reads the file at $path, or $bytes; a $segment of HLS keeps its stream's times, and reads on as it comes, never waiting. */
    public function __construct(string|Bytes $source, private readonly bool $segment = false)
    {
        $this->bytes = $source instanceof Bytes ? $source : new FileBytes($source);
        if ($segment) {
            return;
        }
        $head = $this->bytes->read(0, 200);
        if (($head[0] ?? '') !== 'G' && ($head[4] ?? '') === 'G' && ($head[196] ?? 'G') === 'G') {
            [$this->size, $this->sync] = [192, 4];
        } elseif (($head[0] ?? '') !== 'G') {
            throw new RuntimeException('myphp: no MPEG transport stream (its packets\' sync byte is missing)');
        }
        // Until each stream of the program has its track, and a frame: their tracks, and the time the file starts at.
        while (!$this->end && ($this->streams === [] || count($this->tracks) < count($this->streams) || $this->queue === []) && $this->pos < 8 << 20) {
            $this->parse(true);
        }
        if ($this->tracks === []) {
            throw new RuntimeException('myphp: no stream in the transport stream the player reads');
        }
        $first = null;
        foreach ($this->queue as [, $pts]) { $first = $first === null ? $pts : min($first, $pts); }
        $this->origin = $first ?? 0;
        $this->duration = max(0.0, ($this->lastTime() - $this->origin) / self::CLOCK);
    }

    /** The streams' last PTS in the file, read from its end, a frame's duration past it. */
    private function lastTime(): int
    {
        $total = $this->bytes->size();
        $from = max(0, $total - (1 << 20));
        $from -= ($from - $this->sync) % $this->size;
        $last = $this->origin ?? 0;
        $s = $this->bytes->read($from, $total - $from);
        for ($p = $this->sync; $p + 188 <= strlen($s); $p += $this->size) {
            $h = $this->header($s, $p);
            if ($h === null || !$h['pusi'] || !isset($this->streams[$h['pid']])) { continue; }
            $pes = self::pesHeader(substr($s, $h['payload'], $p + 188 - $h['payload']));
            if ($pes !== null && $pes['pts'] !== null) { $last = max($last, $this->unwrapNear($pes['pts'], $last)); }
        }
        return $last + (int)round(self::CLOCK / 25);
    }

    /** $pts as near $near as its 33 bits allow (a wrap's). */
    private function unwrapNear(int $pts, int $near): int
    {
        while ($pts - $near < -self::WRAP / 2) { $pts += self::WRAP; }
        while ($pts - $near > self::WRAP / 2) { $pts -= self::WRAP; }
        return $pts;
    }

    /**
     * The TS packet header at $p of $s: its PID, whether a PES or a section
     * starts in it, the random access flag and where its payload starts;
     * null for one with no payload, or no sync byte.
     * @return ?array{pid: int, pusi: bool, rai: bool, payload: int}
     */
    private function header(string $s, int $p): ?array
    {
        if ($s[$p] !== 'G') {
            return null;
        }
        $b1 = ord($s[$p + 1]);
        $afc = (ord($s[$p + 3]) >> 4) & 3;
        $q = $p + 4;
        $rai = false;
        if ($afc & 2) {
            $alen = ord($s[$q]);
            if ($alen > 0) { $rai = (ord($s[$q + 1]) & 0x40) !== 0; }
            $q += 1 + $alen;
        }
        if (!($afc & 1) || $q >= $p + 188) {
            return null;
        }
        return ['pid' => (($b1 & 0x1f) << 8) | ord($s[$p + 2]), 'pusi' => ($b1 & 0x40) !== 0, 'rai' => $rai, 'payload' => $q];
    }

    /**
     * Reads the packets that are here, a batch (or, at the edge of what
     * came, one); false when none is, unless it waits for one ($wait).
     */
    private function parse(bool $wait = false): bool
    {
        $batch = $this->size * self::BATCH;
        if ($this->bytes->has($this->pos, $batch)) {
            $s = $this->bytes->read($this->pos, $batch);
        } elseif ($wait || $this->bytes->has($this->pos, $this->size)) {
            $s = $this->bytes->read($this->pos, $this->size);
        } else {
            return false;
        }
        if (strlen($s) < $this->size) {
            $this->finish();
            return true;
        }
        $n = intdiv(strlen($s), $this->size) * $this->size;
        for ($p = $this->sync; $p + 188 <= $n; $p += $this->size) {
            if ($s[$p] !== 'G') { // lost: on a byte, until a packet starts again
                $this->pos += $p - $this->sync + 1;
                return true;
            }
            $this->packet($s, $p);
        }
        $this->pos += $n;
        return true;
    }

    /** The packet at $p of $s. */
    private function packet(string $s, int $p): void
    {
        $h = $this->header($s, $p);
        if ($h === null) {
            return;
        }
        $pid = $h['pid'];
        $payload = substr($s, $h['payload'], $p + 188 - $h['payload']);
        if ($pid === 0) {
            if ($h['pusi']) { $this->pat($payload); }
        } elseif (isset($this->pmts[$pid])) {
            if ($h['pusi']) { $this->pmt($payload); }
        } elseif (isset($this->streams[$pid])) {
            $this->payload($pid, $payload, $h['pusi'], $h['rai']);
        }
    }

    /** The section a payload starts (past its pointer field): its table id and body, of one packet (PAT's and PMT's are). @return ?array{0: int, 1: string} */
    private static function section(string $payload): ?array
    {
        $p = 1 + ord($payload[0]);
        if ($p + 3 > strlen($payload)) {
            return null;
        }
        $len = ((ord($payload[$p + 1]) & 0x0f) << 8) | ord($payload[$p + 2]);
        return [ord($payload[$p]), substr($payload, $p + 3, max(0, $len - 4))]; // its CRC left off
    }

    /** The program association table: the PIDs of the programs' tables. */
    private function pat(string $payload): void
    {
        $sec = self::section($payload);
        if ($sec === null || $sec[0] !== 0) {
            return;
        }
        for ($p = 5, $d = $sec[1]; $p + 4 <= strlen($d); $p += 4) {
            if (unpack('n', $d, $p)[1] !== 0) { $this->pmts[unpack('n', $d, $p + 2)[1] & 0x1fff] = true; } // program 0 is the network's
        }
    }

    /** A program map table: its streams, those of codecs the player reads. */
    private function pmt(string $payload): void
    {
        $sec = self::section($payload);
        if ($sec === null || $sec[0] !== 2) {
            return;
        }
        $d = $sec[1];
        $p = 9 + (unpack('n', $d, 7)[1] & 0x0fff); // past the program's descriptors
        while ($p + 5 <= strlen($d)) {
            $type = ord($d[$p]);
            $pid = unpack('n', $d, $p + 1)[1] & 0x1fff;
            $len = unpack('n', $d, $p + 3)[1] & 0x0fff;
            $desc = substr($d, $p + 5, $len);
            $p += 5 + $len;
            $codec = self::TYPES[$type] ?? null;
            $language = 'und';
            for ($q = 0; $q + 2 <= strlen($desc); $q += 2 + ord($desc[$q + 1])) {
                $tag = ord($desc[$q]);
                $body = substr($desc, $q + 2, ord($desc[$q + 1]));
                if ($tag === 0x0A && strlen($body) >= 3) { $language = substr($body, 0, 3); }
                if ($type === 0x06 && ($tag === 0x6A || $tag === 0x05 && $body === 'AC-3')) { $codec = 'A_AC3'; }
                if ($type === 0x06 && ($tag === 0x7A || $tag === 0x05 && $body === 'EAC3')) { $codec = 'A_EAC3'; }
            }
            if ($codec !== null && !isset($this->streams[$pid])) {
                $this->streams[$pid] = ['codec' => $codec, 'language' => $language];
            }
        }
    }

    /** A payload of stream $pid: a PES's start, or more of the one gathered. */
    private function payload(int $pid, string $s, bool $start, bool $rai): void
    {
        if ($start) {
            if (isset($this->pes[$pid])) { $this->complete($pid); }
            $h = self::pesHeader($s);
            if ($h === null) {
                return;
            }
            $this->pes[$pid] = ['data' => substr($s, $h['data']), 'pts' => $h['pts'], 'dts' => $h['dts'], 'want' => $h['length'], 'rai' => $rai];
        } elseif (isset($this->pes[$pid])) {
            $this->pes[$pid]['data'] .= $s;
        } else {
            return; // the rest of one begun before the stream was read
        }
        if ($this->pes[$pid]['want'] > 0 && strlen($this->pes[$pid]['data']) >= $this->pes[$pid]['want']) {
            $this->complete($pid);
        }
    }

    /** A PES packet's header: its times, where its data starts and how much there is of it (0: to the next). @return ?array{pts: ?int, dts: ?int, data: int, length: int} */
    private static function pesHeader(string $s): ?array
    {
        if (strlen($s) < 9 || !str_starts_with($s, "\0\0\1")) {
            return null;
        }
        $len = unpack('n', $s, 4)[1];
        $flags = ord($s[7]);
        $hlen = ord($s[8]);
        $ts = fn(int $p): int => (((ord($s[$p]) >> 1) & 7) << 30) | (ord($s[$p + 1]) << 22) | ((ord($s[$p + 2]) >> 1) << 15) | (ord($s[$p + 3]) << 7) | (ord($s[$p + 4]) >> 1);
        $pts = ($flags & 0x80) && strlen($s) >= 14 ? $ts(9) : null;
        $dts = ($flags & 0x40) && strlen($s) >= 19 ? $ts(14) : $pts;
        return ['pts' => $pts, 'dts' => $dts, 'data' => 9 + $hlen, 'length' => $len > 0 ? $len - 3 - $hlen : 0];
    }

    /** Stream $pid's PES gathered is whole: its frames made. */
    private function complete(int $pid): void
    {
        $pes = $this->pes[$pid];
        unset($this->pes[$pid]);
        $codec = $this->streams[$pid]['codec'];
        if ($pes['data'] === '' || $codec[0] !== 'V' && $pes['pts'] === null) {
            return;
        }
        if ($codec[0] === 'V') {
            $this->video($pid, $pes);
        } else {
            $this->audio($pid, $pes);
        }
    }

    /**
     * A video PES: its bytes go on the stream's, whose NAL units are each
     * whole once the next one's start code came, made access units by
     * where a picture starts (an AUD, parameter sets, an SEI, or a slice
     * that is its picture's first), as FFmpeg's h264 and hevc parsers make
     * them: an encoder's PES may hold part of a frame (Apple's segmenter's
     * frames of over 64 KiB, of several PES, the rest of no PTS) or more
     * than one.
     * @param array{data: string, pts: ?int, dts: ?int, want: int, rai: bool} $pes
     */
    private function video(int $pid, array $pes): void
    {
        $this->es[$pid] ??= ['buf' => '', 'at' => 0, 'times' => [], 'au' => [], 'auAt' => 0, 'slice' => false];
        $e = &$this->es[$pid];
        if ($pes['pts'] !== null) { $e['times'][] = [$e['at'] + strlen($e['buf']), $pes['pts'], $pes['dts'] ?? $pes['pts']]; }
        $e['buf'] .= $pes['data'];
        $start = strpos($e['buf'], "\0\0\1");
        while ($start !== false && ($next = strpos($e['buf'], "\0\0\1", $start + 3)) !== false) {
            $nal = rtrim(substr($e['buf'], $start + 3, $next - $start - 3), "\0"); // the next one's 4-byte start code's zero, trailing zeros
            if ($nal !== '') { $this->nal($pid, $nal, $e['at'] + ($start > 0 && $e['buf'][$start - 1] === "\0" ? $start - 1 : $start)); } // where it starts: a 4-byte start code's zero, where its PES starts
            $start = $next;
        }
        $keep = $start === false ? max(0, strlen($e['buf']) - 2) : $start; // the last NAL unit, not whole yet (or a start code's first bytes)
        $e['buf'] = substr($e['buf'], $keep);
        $e['at'] += $keep;
    }

    /** A NAL unit of video stream $pid at $at of its bytes: the access unit gathered given when it starts the next. */
    private function nal(int $pid, string $nal, int $at): void
    {
        $e = &$this->es[$pid];
        $hevc = $this->streams[$pid]['codec'] === 'V_MPEGH/ISO/HEVC';
        $type = $hevc ? Elementary::hevcType($nal) : Elementary::avcType($nal);
        $slice = $hevc ? $type <= 31 : $type === 1 || $type === 5;
        $firstSlice = $slice && (ord($nal[$hevc ? 2 : 1] ?? "\0") & 0x80) !== 0; // first_slice_segment_in_pic_flag; first_mb_in_slice 0 (ue's single 1)
        $starts = $hevc ? in_array($type, [35, 32, 33, 34, 39], true) : in_array($type, [9, 7, 8, 6, 14, 15, 16, 17, 18], true);
        if ($e['slice'] && ($starts || $firstSlice)) {
            $this->frame($pid, $at);
        }
        if ($e['au'] === []) { $e['auAt'] = $at; }
        $e['au'][] = $nal;
        $e['slice'] = $e['slice'] || $slice;
    }

    /**
     * Video stream $pid's access unit gathered, the next starting at $end of
     * its bytes: timed by the first PES that started in it (one of none, of
     * a PES of more frames, the one before's next decode time), its NAL
     * units as lengths, held for the next one's decode time (its duration).
     */
    private function frame(int $pid, float $end): void
    {
        $e = &$this->es[$pid];
        $au = $e['au'];
        $e['au'] = []; $e['slice'] = false;
        $time = null;
        while ($e['times'] !== [] && $e['times'][0][0] < $end) { // the PES that started in it: the first's its time
            $t = array_shift($e['times']);
            $time ??= $t;
        }
        $hevc = $this->streams[$pid]['codec'] === 'V_MPEGH/ISO/HEVC';
        $nals = [];
        $key = false;
        foreach ($au as $nal) {
            $type = $hevc ? Elementary::hevcType($nal) : Elementary::avcType($nal);
            if ($hevc ? $type === 35 : $type === 9) { continue; } // access unit delimiters: left out, as of MP4's
            if ($hevc ? in_array($type, [32, 33, 34], true) : in_array($type, [7, 8], true)) { $this->params[$pid][$type] = $nal; }
            if ($hevc ? $type >= 16 && $type <= 23 : $type === 5) { $key = true; }
            if (!$hevc && $type === 6 && ord($nal[1] ?? "\0") === 6) { $key = true; } // a recovery point (SEI 6): where decoding can start, as FFmpeg's h264 parser has it
            $nals[] = $nal;
        }
        if ($nals === []) {
            return;
        }
        if ($time === null) { // no PES of its own: after the one before, by a frame
            if (!isset($this->previous[$pid])) { return; }
            [$pts, $dts] = $this->previous[$pid];
            $d = $this->last[$pid] ?? 0;
            [$pts, $dts] = [$pts + $d, $dts + $d];
        } else {
            [, $pts, $dts] = $time;
        }
        $this->previous[$pid] = [$pts, $dts];
        if (!isset($this->tracks[$pid])) {
            $ps = $this->params[$pid] ?? [];
            if ($hevc ? !isset($ps[32], $ps[33], $ps[34]) : !isset($ps[7], $ps[8])) {
                return; // nothing decodes before its parameter sets
            }
            if ($hevc) {
                $sps = Elementary::hevcSps($ps[33]);
                [$private, $w, $h] = [Elementary::hvcC($ps[32], $ps[33], $ps[34]), $sps['width'], $sps['height']];
            } else {
                [$private, [$w, $h]] = [Elementary::avcC($ps[7], $ps[8]), Elementary::avcSize($ps[7])];
            }
            $this->tracks[$pid] = new Track($pid, Track::VIDEO, $this->streams[$pid]['codec'], $private, $this->streams[$pid]['language'], '', true, 0.0, $w, $h, 0.0, 0);
        }
        if (isset($this->waitKey[$pid])) {
            if (!$key) { return; }
            unset($this->waitKey[$pid]);
        }
        $this->release($pid, $dts);
        $this->held[$pid] = [$pts, $dts, $key, Elementary::lengthPrefixed($nals)];
    }

    /** Stream $pid's frame held, given now that the next's decode time ($dts; null at the end) says its duration. */
    private function release(int $pid, ?int $dts): void
    {
        if (!isset($this->held[$pid])) {
            return;
        }
        [$pts, $from, $key, $data] = $this->held[$pid];
        unset($this->held[$pid]);
        $d = $dts === null ? 0 : $this->unwrapNear($dts, $from) - $from;
        if ($d > 0 && $d < self::CLOCK) { $this->last[$pid] = $d; } else { $d = $this->last[$pid] ?? 0; }
        $this->queue[] = [$this->tracks[$pid], $pts, $d, $key, $data];
    }

    /** An audio PES: its frames, each a packet, timed from the PES's by the samples before it. @param array{data: string, pts: ?int, dts: ?int, want: int, rai: bool} $pes */
    private function audio(int $pid, array $pes): void
    {
        $codec = $this->streams[$pid]['codec'];
        $s = $pes['data'];
        $t = (float)$pes['pts'];
        for ($p = 0, $n = strlen($s); $p < $n;) {
            $f = match ($codec) { 'A_AAC' => Elementary::adts($s, $p), 'A_MPEG/L3' => Elementary::mpa($s, $p), default => Elementary::ac3($s, $p) };
            if ($f === null || $f['len'] <= 0 || $p + $f['len'] > $n) {
                $p++; // not at a frame: on to the next sync
                continue;
            }
            if ($codec === 'A_AAC') {
                $rate = Elementary::AAC_RATES[$f['rate']] ?? 0;
                $samples = 1024;
                $data = substr($s, $p + $f['head'], $f['len'] - $f['head']);
                $private = Elementary::aacConfig($f['profile'], $f['rate'], $f['channels']);
            } else {
                $rate = $f['rate'];
                $samples = $f['samples'];
                $data = substr($s, $p, $f['len']);
                $private = '';
            }
            $p += $f['len'];
            if ($codec === 'A_MPEG/L3' && $f['layer'] !== 3) { // layer II: no codec the system decodes
                unset($this->streams[$pid]);
                return;
            }
            if ($rate <= 0) { continue; }
            if (!isset($this->tracks[$pid])) {
                $this->tracks[$pid] = new Track($pid, Track::AUDIO, ($f['eac3'] ?? false) ? 'A_EAC3' : $codec, $private, $this->streams[$pid]['language'], '', true, 0.0, 0, 0, (float)$rate, $f['channels']);
            }
            $d = $samples * self::CLOCK / $rate;
            $this->queue[] = [$this->tracks[$pid], (int)round($t), (int)round($d), true, $data];
            $t += $d;
        }
    }

    /** The end: what is gathered and held is made frames. */
    private function finish(): void
    {
        $this->end = true;
        foreach (array_keys($this->pes) as $pid) { $this->complete($pid); }
        foreach ($this->es as $pid => $e) { // the last NAL unit, and its access unit
            $start = strpos($e['buf'], "\0\0\1");
            $nal = $start === false ? '' : rtrim(substr($e['buf'], $start + 3), "\0");
            if ($nal !== '') { $this->nal($pid, $nal, $e['at'] + $start); }
            if ($this->es[$pid]['au'] !== []) { $this->frame($pid, INF); }
        }
        $this->es = [];
        foreach (array_keys($this->held) as $pid) { $this->release($pid, null); }
    }

    /** Whether each stream of the program has its track (the parameter sets, the first frame came). @internal Media\HlsStream */
    public function probed(): bool
    {
        return $this->streams !== [] && count($this->tracks) >= count($this->streams);
    }

    public function tracks(): array
    {
        $t = array_values($this->tracks);
        usort($t, fn(Track $a, Track $b) => [$a->type !== Track::VIDEO, $a->number] <=> [$b->type !== Track::VIDEO, $b->number]);
        return $t;
    }

    public function duration(): float
    {
        return $this->duration;
    }

    public function chapters(): array
    {
        return [];
    }

    public function ready(): bool
    {
        while ($this->queue === [] && !$this->end) {
            if (!$this->parse()) {
                return false;
            }
        }
        return true;
    }

    public function next(): ?Packet
    {
        if (!$this->ready() || $this->queue === []) {
            return null;
        }
        [$track, $pts, $d, $key, $data] = array_shift($this->queue);
        if ($this->origin === null) { // a segment's: the stream's own time
            return new Packet($track, $pts / self::CLOCK, $d / self::CLOCK, $key, $data);
        }
        $pts = $this->unwrapNear($pts, $this->lastPts ?? $this->origin);
        $this->lastPts = $pts;
        return new Packet($track, ($pts - $this->origin) / self::CLOCK, $d / self::CLOCK, $key, $data);
    }

    /**
     * Goes to the video's keyframe at or before $seconds (a file's): the
     * last packet whose PES starts the reference stream (the video's, else
     * the first's) at or before it, found by a binary search of the file,
     * then back from there to a random access point; its frames go on from
     * that keyframe, the sound from there.
     */
    public function seek(float $seconds): void
    {
        if ($this->origin === null) {
            throw new RuntimeException('myphp: a segment of HLS is read from its start');
        }
        $want = $this->origin + (int)round($seconds * self::CLOCK);
        $video = null;
        foreach ($this->tracks as $pid => $t) { if ($t->type === Track::VIDEO) { $video = $pid; break; } }
        $ref = $video ?? array_key_first($this->tracks);
        $packets = intdiv($this->bytes->size(), $this->size);
        [$lo, $hi] = [0, $packets - 1];
        while ($hi - $lo > 64) { // the PES of the reference stream starting at or after a place: its time
            $mid = intdiv($lo + $hi, 2);
            $found = $this->startAt($mid, $ref, false);
            if ($found === null || $found[1] > $want) { $hi = $mid; } else { $lo = $mid; }
        }
        // Back from there to a keyframe of the video (a random access point) whose time is at or before it.
        $at = $lo;
        if ($video !== null) {
            for ($from = $lo, $tries = 0; $tries < 64; $tries++) {
                $k = $this->startAt($from, $video, true, $lo + 64);
                if ($k !== null && $k[1] <= $want) { $at = $k[0]; break; }
                if ($from === 0) { $at = 0; break; }
                $from = max(0, $from - 4096); // half a megabyte back
            }
            // The last random access point between that one and where the time passes.
            for ($k = $this->startAt($at + 1, $video, true, $hi + 1); $k !== null && $k[1] <= $want; $k = $this->startAt($k[0] + 1, $video, true, $hi + 1)) { $at = $k[0]; }
        }
        $this->pos = $at * $this->size;
        $this->pes = []; $this->es = []; $this->previous = []; $this->held = []; $this->queue = []; $this->end = false; $this->lastPts = null;
        if ($video !== null) { $this->waitKey[$video] = true; }
    }

    /**
     * The first packet from packet $from (before packet $to) starting a PES
     * of stream $pid (a keyframe's, with $key: a random access point, or an
     * IDR's or IRAP's NAL unit in it): its index and PTS, unwrapped.
     * @return ?array{0: int, 1: int}
     */
    private function startAt(int $from, int $pid, bool $key, ?int $to = null): ?array
    {
        $to ??= $from + 8192;
        $hevc = ($this->streams[$pid]['codec'] ?? '') === 'V_MPEGH/ISO/HEVC';
        for ($i = $from; $i < $to; $i += self::BATCH) {
            $s = $this->bytes->read($i * $this->size, min(self::BATCH, $to - $i) * $this->size);
            for ($p = $this->sync, $j = $i; $p + 188 <= strlen($s); $p += $this->size, $j++) {
                $h = $this->header($s, $p);
                if ($h === null || $h['pid'] !== $pid || !$h['pusi']) { continue; }
                $pay = substr($s, $h['payload'], $p + 188 - $h['payload']);
                $pes = self::pesHeader($pay);
                if ($pes === null || $pes['pts'] === null) { continue; }
                if ($key && !$h['rai']) {
                    $idr = false;
                    foreach (Elementary::nals(substr($pay, $pes['data'])) as $nal) {
                        $t = $hevc ? Elementary::hevcType($nal) : Elementary::avcType($nal);
                        if ($hevc ? $t >= 16 && $t <= 23 : $t === 5) { $idr = true; break; }
                    }
                    if (!$idr) { continue; }
                }
                return [$j, $this->unwrapNear($pes['pts'], $this->origin ?? $pes['pts'])];
            }
            if (strlen($s) < $this->size) { break; }
        }
        return null;
    }

    /** All its tracks are read: they are of one file. */
    public function choose(?Track $video, ?Track $audio): void {}

    public function keyframe(float $seconds): ?Packet
    {
        $this->seek($seconds);
        while ($this->ready() && ($p = $this->next()) !== null) {
            if ($p->track->type === Track::VIDEO && $p->keyframe) {
                return $p;
            }
        }
        return null;
    }
}
