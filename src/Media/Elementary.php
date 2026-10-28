<?php
declare(strict_types=1);

namespace MyPHP\Media;

/**
 * Elementary streams as MPEG-TS carries them, made what Matroska holds
 * (as FFmpeg's parsers and bitstream filters make them): H.264's and
 * HEVC's Annex B start codes as 4-byte lengths, their parameter sets an
 * avcC or hvcC record, and the picture's size from the SPS; AAC's ADTS
 * frames, their AudioSpecificConfig; AC-3's, E-AC-3's and MP3's frames
 * found by their headers.
 *
 * @internal Media\Ts
 */
final class Elementary
{
    /** The NAL units of an Annex B stream (start codes of 3 or 4 bytes). @return list<string> */
    public static function nals(string $s): array
    {
        $out = [];
        $n = strlen($s);
        $p = strpos($s, "\0\0\1");
        while ($p !== false) {
            $start = $p + 3;
            $next = strpos($s, "\0\0\1", $start);
            $end = $next === false ? $n : $next;
            $nal = substr($s, $start, $end - $start);
            $nal = rtrim($nal, "\0"); // a 4-byte start code's zero, and trailing zeros
            if ($nal !== '') { $out[] = $nal; }
            $p = $next;
        }
        return $out;
    }

    /** NAL units as 4-byte big-endian lengths and the units (avcC's and hvcC's samples). @param list<string> $nals */
    public static function lengthPrefixed(array $nals): string
    {
        $s = '';
        foreach ($nals as $n) { $s .= pack('N', strlen($n)) . $n; }
        return $s;
    }

    /** $nal without its emulation prevention bytes (00 00 03): the bits it carries. */
    public static function rbsp(string $nal): string
    {
        return preg_replace('/\x00\x00\x03/', "\0\0", $nal) ?? $nal;
    }

    /** The H.264 NAL type of $nal. */
    public static function avcType(string $nal): int
    {
        return ord($nal[0]) & 0x1f;
    }

    /** The HEVC NAL type of $nal. */
    public static function hevcType(string $nal): int
    {
        return (ord($nal[0]) >> 1) & 0x3f;
    }

    /** The avcC record of SPS $sps and PPS $pps, its lengths of 4 bytes (ISO/IEC 14496-15). */
    public static function avcC(string $sps, string $pps): string
    {
        return "\1" . substr($sps, 1, 3) . "\xFF" . "\xE1" . pack('n', strlen($sps)) . $sps . "\1" . pack('n', strlen($pps)) . $pps;
    }

    /** The width and height an H.264 SPS says, its cropping taken off. @return array{0: int, 1: int} */
    public static function avcSize(string $sps): array
    {
        $b = new Bits(self::rbsp(substr($sps, 1)));
        $profile = $b->u(8); $b->u(16); // constraint flags and level
        $b->ue(); // seq_parameter_set_id
        $chroma = 1;
        if (in_array($profile, [100, 110, 122, 244, 44, 83, 86, 118, 128, 138, 139, 134, 135], true)) {
            $chroma = $b->ue();
            if ($chroma === 3) { $b->u(1); }
            $b->ue(); $b->ue(); $b->u(1); // bit depths, qpprime_y_zero_transform_bypass
            if ($b->u(1) === 1) { // seq_scaling_matrix_present
                for ($i = 0; $i < ($chroma === 3 ? 12 : 8); $i++) {
                    if ($b->u(1) === 1) {
                        for ($j = 0, $last = 8, $next = 8, $n = $i < 6 ? 16 : 64; $j < $n; $j++) {
                            if ($next !== 0) { $next = ($last + $b->se() + 256) % 256; }
                            $last = $next === 0 ? $last : $next;
                        }
                    }
                }
            }
        }
        $b->ue(); // log2_max_frame_num_minus4
        $poc = $b->ue();
        if ($poc === 0) {
            $b->ue();
        } elseif ($poc === 1) {
            $b->u(1); $b->se(); $b->se();
            for ($i = 0, $n = $b->ue(); $i < $n; $i++) { $b->se(); }
        }
        $b->ue(); $b->u(1); // max_num_ref_frames, gaps_in_frame_num_value_allowed
        $w = ($b->ue() + 1) * 16;
        $mapUnits = $b->ue() + 1;
        $frameMbsOnly = $b->u(1);
        $h = (2 - $frameMbsOnly) * $mapUnits * 16;
        if ($frameMbsOnly === 0) { $b->u(1); }
        $b->u(1); // direct_8x8_inference
        if ($b->u(1) === 1) { // frame_cropping
            [$l, $r, $t, $btm] = [$b->ue(), $b->ue(), $b->ue(), $b->ue()];
            $cx = $chroma === 0 || $chroma === 3 ? 1 : 2;
            $cy = ($chroma === 1 ? 2 : 1) * (2 - $frameMbsOnly);
            if ($chroma === 0) { $cy = 2 - $frameMbsOnly; }
            $w -= $cx * ($l + $r);
            $h -= $cy * ($t + $btm);
        }
        return [$w, $h];
    }

    /**
     * What an HEVC SPS says: its profile_tier_level (12 bytes, as hvcC
     * holds it), chroma format, bit depths and the size, cropped.
     * @return array{ptl: string, chroma: int, luma: int, chromaDepth: int, width: int, height: int, sublayers: int, nested: int}
     */
    public static function hevcSps(string $sps): array
    {
        $raw = self::rbsp(substr($sps, 2));
        $b = new Bits($raw);
        $b->u(4); // sps_video_parameter_set_id
        $sub = $b->u(3);
        $nested = $b->u(1);
        $ptl = substr($raw, 1, 12); // general_profile_space … general_level_idc: 96 bits from byte 1
        $b->skip(96);
        $profilePresent = []; $levelPresent = [];
        for ($i = 0; $i < $sub; $i++) { $profilePresent[] = $b->u(1); $levelPresent[] = $b->u(1); }
        if ($sub > 0) { for ($i = $sub; $i < 8; $i++) { $b->u(2); } }
        for ($i = 0; $i < $sub; $i++) {
            if ($profilePresent[$i] === 1) { $b->skip(88); }
            if ($levelPresent[$i] === 1) { $b->skip(8); }
        }
        $b->ue(); // sps_seq_parameter_set_id
        $chroma = $b->ue();
        if ($chroma === 3) { $b->u(1); }
        $w = $b->ue(); $h = $b->ue();
        if ($b->u(1) === 1) { // conformance_window
            [$l, $r, $t, $btm] = [$b->ue(), $b->ue(), $b->ue(), $b->ue()];
            $cx = $chroma === 1 || $chroma === 2 ? 2 : 1;
            $cy = $chroma === 1 ? 2 : 1;
            $w -= $cx * ($l + $r);
            $h -= $cy * ($t + $btm);
        }
        $luma = $b->ue() + 8;
        $chromaDepth = $b->ue() + 8;
        return ['ptl' => $ptl, 'chroma' => $chroma, 'luma' => $luma, 'chromaDepth' => $chromaDepth, 'width' => $w, 'height' => $h, 'sublayers' => $sub + 1, 'nested' => $nested];
    }

    /** The hvcC record of parameter sets $vps, $sps and $pps, its lengths of 4 bytes (ISO/IEC 14496-15 8.3.3). */
    public static function hvcC(string $vps, string $sps, string $pps): string
    {
        $s = self::hevcSps($sps);
        $h = "\1" . $s['ptl']
            . pack('n', 0xF000) // min_spatial_segmentation_idc 0
            . "\xFC" // parallelismType 0
            . chr(0xFC | $s['chroma'])
            . chr(0xF8 | ($s['luma'] - 8))
            . chr(0xF8 | ($s['chromaDepth'] - 8))
            . pack('n', 0) // avgFrameRate
            . chr(($s['sublayers'] << 3) | ($s['nested'] << 2) | 3) // constantFrameRate 0, lengthSizeMinusOne 3
            . "\3";
        foreach ([32 => $vps, 33 => $sps, 34 => $pps] as $type => $nal) {
            $h .= chr(0x80 | $type) . pack('n', 1) . pack('n', strlen($nal)) . $nal;
        }
        return $h;
    }

    /** The rates of ADTS's and an AudioSpecificConfig's sampling_frequency_index. */
    public const AAC_RATES = [96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050, 16000, 12000, 11025, 8000, 7350];

    /**
     * The ADTS frame at $p of $s: its length, header length, profile (the
     * object type less one), rate index and channel configuration, of its
     * header (the frame may go on past $s); null when there is none there.
     * @return ?array{len: int, head: int, profile: int, rate: int, channels: int}
     */
    public static function adts(string $s, int $p): ?array
    {
        if ($p + 7 > strlen($s) || ord($s[$p]) !== 0xFF || (ord($s[$p + 1]) & 0xF6) !== 0xF0) {
            return null;
        }
        $b1 = ord($s[$p + 1]); $b2 = ord($s[$p + 2]); $b3 = ord($s[$p + 3]); $b4 = ord($s[$p + 4]); $b5 = ord($s[$p + 5]);
        $len = (($b3 & 3) << 11) | ($b4 << 3) | ($b5 >> 5);
        $head = ($b1 & 1) === 1 ? 7 : 9; // protection_absent: no CRC
        if ($len < $head) {
            return null;
        }
        return ['len' => $len, 'head' => $head, 'profile' => $b2 >> 6, 'rate' => ($b2 >> 2) & 15, 'channels' => (($b2 & 1) << 2) | ($b3 >> 6)];
    }

    /** The AudioSpecificConfig of an ADTS header's fields. */
    public static function aacConfig(int $profile, int $rate, int $channels): string
    {
        return pack('n', (($profile + 1) << 11) | ($rate << 7) | ($channels << 3));
    }

    /**
     * The AC-3 or E-AC-3 syncframe at $p of $s: its length, rate,
     * channels and the samples it holds; null when there is none there.
     * @return ?array{len: int, rate: int, channels: int, samples: int, eac3: bool}
     */
    public static function ac3(string $s, int $p): ?array
    {
        if ($p + 8 > strlen($s) || substr($s, $p, 2) !== "\x0B\x77") {
            return null;
        }
        $bsid = ord($s[$p + 5]) >> 3;
        $rates = [48000, 44100, 32000];
        if ($bsid > 10) { // E-AC-3: frmsiz in words, numblkscod
            $b2 = ord($s[$p + 2]); $b3 = ord($s[$p + 3]); $b4 = ord($s[$p + 4]);
            $len = ((($b2 & 7) << 8) | $b3) * 2 + 2;
            $code = $b4 >> 6;
            $blocks = 6; $rate = 0;
            if ($code === 3) { $rate = [24000, 22050, 16000][($b4 >> 4) & 3] ?? 0; }
            else { $rate = $rates[$code]; $blocks = [1, 2, 3, 6][($b4 >> 4) & 3]; }
            $acmod = ($b4 >> 1) & 7; $lfe = $b4 & 1;
            return ['len' => $len, 'rate' => $rate, 'channels' => [2, 1, 2, 3, 3, 4, 4, 5][$acmod] + $lfe, 'samples' => 256 * $blocks, 'eac3' => true];
        }
        $b4 = ord($s[$p + 4]);
        $code = $b4 >> 6; $size = $b4 & 0x3f;
        if ($code === 3 || $size > 37) {
            return null;
        }
        $words = [[64, 69, 96], [64, 70, 96], [80, 87, 120], [80, 88, 120], [96, 104, 144], [96, 105, 144], [112, 121, 168], [112, 122, 168], [128, 139, 192], [128, 140, 192], [160, 174, 240], [160, 175, 240], [192, 208, 288], [192, 209, 288], [224, 243, 336], [224, 244, 336], [256, 278, 384], [256, 279, 384], [320, 348, 480], [320, 349, 480], [384, 417, 576], [384, 418, 576], [448, 487, 672], [448, 488, 672], [512, 557, 768], [512, 558, 768], [640, 696, 960], [640, 697, 960], [768, 835, 1152], [768, 836, 1152], [896, 975, 1344], [896, 976, 1344], [1024, 1114, 1536], [1024, 1115, 1536], [1152, 1253, 1728], [1152, 1254, 1728], [1280, 1393, 1920], [1280, 1394, 1920]][$size][$code];
        // acmod and lfeon follow bsid and bsmod: lfeon's place depends on which of cmixlev, surmixlev, dsurmod acmod has.
        $b = new Bits(substr($s, $p + 6, 2));
        $acmod = $b->u(3);
        if (($acmod & 1) && $acmod !== 1) { $b->u(2); }
        if ($acmod & 4) { $b->u(2); }
        if ($acmod === 2) { $b->u(2); }
        $lfe = $b->u(1);
        return ['len' => $words * 2, 'rate' => $rates[$code], 'channels' => [2, 1, 2, 3, 3, 4, 4, 5][$acmod] + $lfe, 'samples' => 1536, 'eac3' => false];
    }

    /**
     * The MPEG audio frame (layer III, or II) at $p of $s: its length, rate,
     * channels, samples and layer (2 or 3); null when there is none there.
     * @return ?array{len: int, rate: int, channels: int, samples: int, layer: int}
     */
    public static function mpa(string $s, int $p): ?array
    {
        if ($p + 4 > strlen($s)) {
            return null;
        }
        $h = unpack('N', $s, $p)[1];
        if (($h & 0xFFE00000) !== 0xFFE00000) {
            return null;
        }
        $version = ($h >> 19) & 3; $layer = ($h >> 17) & 3; $bitrate = ($h >> 12) & 15; $rateIdx = ($h >> 10) & 3; $pad = ($h >> 9) & 1;
        if ($version === 1 || $layer === 0 || $layer === 3 || $bitrate === 0 || $bitrate === 15 || $rateIdx === 3) {
            return null; // reserved, layer I, free format
        }
        $mpeg1 = $version === 3;
        $rate = [44100, 48000, 32000][$rateIdx] >> ($mpeg1 ? 0 : ($version === 2 ? 1 : 2));
        $kbps = $layer === 1 // layer III
            ? ($mpeg1 ? [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320] : [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160])
            : ($mpeg1 ? [0, 32, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 384] : [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160]);
        $samples = $layer === 1 && !$mpeg1 ? 576 : 1152;
        $len = intdiv($samples / 8 * $kbps[$bitrate] * 1000, $rate) + $pad;
        return ['len' => (int)$len, 'rate' => $rate, 'channels' => (($h >> 6) & 3) === 3 ? 1 : 2, 'samples' => $samples, 'layer' => $layer === 1 ? 3 : 2];
    }
}
