<?php
declare(strict_types=1);

namespace MyPHP\Media;

use RuntimeException;

/**
 * Text subtitles: the cues of a file (SubRip .srt, Advanced SubStation
 * .ass and .ssa, WebVTT .vtt) and the text of a subtitle packet of a
 * track (Matroska's S_TEXT/UTF8, S_TEXT/ASS, S_TEXT/SSA, S_TEXT/WEBVTT,
 * MP4's tx3g), as plain lines: styling left out (ASS's override tags,
 * SubRip's and WebVTT's markup), \N and <br> breaking lines. A file in
 * UTF-16 or, failing UTF-8, in GB18030 (Chinese subtitles often are) is
 * read as such. Image tracks (PICTURES) are read by Pgs and VobSub.
 */
final class Subtitles
{
    /** Matroska's codec ids of the text subtitles read. */
    public const CODECS = ['S_TEXT/UTF8', 'S_TEXT/ASS', 'S_TEXT/SSA', 'S_ASS', 'S_SSA', 'S_TEXT/WEBVTT', 'S_TEXT/TX3G'];
    /** Matroska's codec ids of the image subtitles read: Blu-ray's (Pgs) and DVD's (VobSub). */
    public const PICTURES = ['S_HDMV/PGS', 'S_VOBSUB'];
    /** The extensions of subtitle files, by their codec id. */
    public const FILES = ['srt' => 'S_TEXT/UTF8', 'ass' => 'S_TEXT/ASS', 'ssa' => 'S_TEXT/SSA', 'vtt' => 'S_TEXT/WEBVTT'];

    /** Whether the player reads subtitle track $t: text or images. */
    public static function readable(Track $t): bool
    {
        return $t->type === Track::SUBTITLE && (in_array($t->codec, self::CODECS, true) || in_array($t->codec, self::PICTURES, true));
    }

    /**
     * The cues of subtitle file $path, by start: [start, end, text] in
     * seconds; its codec id (FILES) by its extension.
     * @return array{0: string, 1: list<array{0: float, 1: float, 2: string}>}
     */
    public static function file(string $path): array
    {
        $codec = self::FILES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? throw new RuntimeException("myphp: no subtitles of the kind of $path");
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException("cannot read $path");
        }
        $s = str_replace(["\r\n", "\r"], "\n", self::utf8($bytes));
        $cues = match ($codec) {
            'S_TEXT/UTF8', 'S_TEXT/WEBVTT' => self::timed($s, $codec),
            default => self::ass($s),
        };
        usort($cues, fn(array $a, array $b): int => $a[0] <=> $b[0]);
        return [$codec, $cues];
    }

    /** Whether codec $codec is ASS's or SSA's, whose lines have styles (Ass). */
    public static function styled(string $codec): bool
    {
        return in_array($codec, ['S_TEXT/ASS', 'S_TEXT/SSA', 'S_ASS', 'S_SSA'], true);
    }

    /**
     * The cues of ASS or SSA file $path in their styles: [start, end, line]
     * by start, as Ass reads its header and its dialogue.
     * @return list<array{0: float, 1: float, 2: AssLine}>
     */
    public static function assFile(string $path): array
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException("cannot read $path");
        }
        $s = str_replace(["\r\n", "\r"], "\n", self::utf8($bytes));
        $ass = new Ass($s);
        $cues = [];
        foreach (self::events($s) as $f) {
            [$start, $end] = [self::clock(trim($f['start'] ?? '')), self::clock(trim($f['end'] ?? ''))];
            $line = $ass->line($f['text'] ?? '', $f['style'] ?? 'Default', (int)($f['layer'] ?? 0), (int)($f['marginl'] ?? 0), (int)($f['marginr'] ?? 0), (int)($f['marginv'] ?? 0), $start, $end - $start);
            if ($line !== null) {
                $cues[] = [$start, $end, $line];
            }
        }
        usort($cues, fn(array $a, array $b): int => $a[0] <=> $b[0]);
        return $cues;
    }

    /** The line of a Matroska ASS or SSA packet in its styles, as $ass reads it, showing from $start for $duration seconds: ReadOrder, Layer (SSA's Marked), Style, Name, MarginL, MarginR, MarginV, Effect, Text. */
    public static function assPacket(Ass $ass, string $data, float $start = 0.0, float $duration = INF): ?AssLine
    {
        $f = explode(',', str_replace(["\r\n", "\r"], "\n", $data), 9);
        return count($f) < 9 ? null : $ass->line($f[8], trim($f[2]), (int)$f[1], (int)$f[4], (int)$f[5], (int)$f[6], $start, $duration);
    }

    /** The text of a subtitle packet of codec $codec. */
    public static function packet(string $codec, string $data): string
    {
        $data = str_replace(["\r\n", "\r"], "\n", $data);
        return match ($codec) {
            'S_TEXT/ASS', 'S_TEXT/SSA', 'S_ASS', 'S_SSA' => self::assText(explode(',', $data, 9)[8] ?? ''), // ReadOrder, Layer, Style, Name, MarginL, MarginR, MarginV, Effect, Text
            'S_TEXT/TX3G' => self::markup(strlen($data) >= 2 ? substr($data, 2, unpack('n', $data)[1]) : ''), // a 16-bit length, the text, then its style boxes
            default => self::markup($data),
        };
    }

    /** $bytes as UTF-8: by a byte order mark, else as UTF-8 when it is, else as GB18030. */
    private static function utf8(string $bytes): string
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            return substr($bytes, 3);
        }
        if (str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF")) {
            return mb_convert_encoding(substr($bytes, 2), 'UTF-8', $bytes[0] === "\xFF" ? 'UTF-16LE' : 'UTF-16BE');
        }
        return mb_check_encoding($bytes, 'UTF-8') ? $bytes : mb_convert_encoding($bytes, 'UTF-8', 'GB18030');
    }

    /** The cues of SubRip or WebVTT text: blocks of a timing line "start --> end" and text, separated by blank lines. @return list<array{0: float, 1: float, 2: string}> */
    private static function timed(string $s, string $codec): array
    {
        $cues = [];
        foreach (preg_split('/\n{2,}/', trim($s)) ?: [] as $block) {
            $lines = explode("\n", $block);
            foreach ($lines as $i => $line) {
                if (preg_match('/^\s*([\d:.,]+)\s*-->\s*([\d:.,]+)/', $line, $m) === 1) {
                    $text = self::markup(implode("\n", array_slice($lines, $i + 1)));
                    if ($text !== '') {
                        $cues[] = [self::clock($m[1]), self::clock($m[2]), $text];
                    }
                    break;
                }
            }
        }
        return $cues;
    }

    /** The cues of the [Events] of ASS or SSA text, by its Format line. @return list<array{0: float, 1: float, 2: string}> */
    private static function ass(string $s): array
    {
        $cues = [];
        foreach (self::events($s) as $f) {
            $text = self::assText($f['text'] ?? '');
            if ($text !== '') {
                $cues[] = [self::clock(trim($f['start'] ?? '')), self::clock(trim($f['end'] ?? '')), $text];
            }
        }
        return $cues;
    }

    /** The Dialogue lines of the [Events] of ASS or SSA text, their fields by its Format line, lowercase. @return list<array<string, string>> */
    private static function events(string $s): array
    {
        $out = []; $format = null; $events = false;
        foreach (explode("\n", $s) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '[')) {
                $events = strcasecmp($line, '[Events]') === 0;
                continue;
            }
            if (!$events) {
                continue;
            }
            if (stripos($line, 'Format:') === 0) {
                $format = array_map(fn($f) => strtolower(trim($f)), explode(',', substr($line, 7)));
            } elseif (stripos($line, 'Dialogue:') === 0 && $format !== null) {
                $out[] = array_combine($format, array_pad(explode(',', substr($line, 9), count($format)), count($format), ''));
            }
        }
        return $out;
    }

    /** ASS dialogue text as plain lines: override tags {\…} left out, \N and \n break lines, \h is a space. */
    private static function assText(string $t): string
    {
        $t = preg_replace('/\{[^}]*\}/', '', $t) ?? $t;
        return trim(str_replace(['\N', '\n', '\h'], ["\n", "\n", ' '], $t));
    }

    /** SubRip or WebVTT text as plain lines: <br> breaks a line, other tags (<i>, <font …>, <c.yellow>) and the entities they escape with are read as text. */
    private static function markup(string $t): string
    {
        $t = preg_replace('/<br\s*\/?>/i', "\n", $t) ?? $t;
        $t = preg_replace('/<[^>]*>|\{\\\\[^}]*\}/', '', $t) ?? $t; // SubRip files carry ASS tags at times
        return trim(html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** Seconds of a time as subtitles write them: h:mm:ss.cc (ASS), hh:mm:ss,mmm (SubRip), [hh:]mm:ss.mmm (WebVTT). */
    private static function clock(string $t): float
    {
        $parts = array_reverse(explode(':', str_replace(',', '.', $t)));
        $s = 0.0;
        foreach ($parts as $i => $p) {
            $s += (float)$p * 60 ** $i;
        }
        return $s;
    }
}
