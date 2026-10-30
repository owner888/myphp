<?php
declare(strict_types=1);

namespace MyPHP\Native\Build;

use RuntimeException;

/**
 * The slug that names an app's files where a name must be plain ASCII
 * (mygo's slug.go): the update archives and deltas, a web project's
 * package. Chinese characters take their most common Mandarin reading,
 * each its own word, as yun-yin-le of 云音乐 (Japanese kanji too), from
 * pinyin.bin, which scripts/gen-pinyin.php writes from Unicode's Unihan;
 * kana and Hangul their romanization, as ramen of ラーメン and seoul of 서울.
 *
 * @internal
 */
final class Slug
{
    /** What a slug is: lower-case letters and digits, with single hyphens between them. */
    public const PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /** @var ?array{0: list<string>, 1: string} the readings and the table of pinyin.bin */
    private static ?array $pinyin = null;

    /**
     * The Hepburn romanization of hiragana from U+3041 (ぁ) to U+3096 (ゖ),
     * whose katakana come 0x60 later; kana() handles the small ones and っ.
     */
    public const KANA_ROMAJI = 'a a i i u u e e o o ka ga ki gi ku gu ke ge ko go
        sa za shi ji su zu se ze so zo ta da chi ji tsu tsu zu te de to do
        na ni nu ne no ha ba pa hi bi pi fu bu pu he be pe ho bo po
        ma mi mu me mo ya ya yu yu yo yo ra ri ru re ro wa wa i e o n vu ka ke';

    /**
     * The Revised Romanization of a Hangul syllable's jamo: the initial
     * consonants, the vowels, and the final consonants, which sound
     * otherwise before a vowel (the next syllable's silent ㅇ), as g of 한국
     * in 한국어, hangugeo.
     */
    private const HANGUL_INITIALS = 'g,kk,n,d,tt,r,m,b,pp,s,ss,,j,jj,ch,k,t,p,h';
    private const HANGUL_VOWELS = 'a,ae,ya,yae,eo,e,yeo,ye,o,wa,wae,oe,yo,u,wo,we,wi,yu,eu,ui,i';
    private const HANGUL_FINALS = ',k,k,k,n,n,n,t,l,k,m,l,l,l,p,l,m,p,p,t,t,ng,t,t,k,t,p,t';
    private const HANGUL_FINALS_LINKED = ',g,kk,ks,n,nj,n,d,r,lg,lm,lb,ls,lt,lp,r,m,b,ps,s,ss,ng,j,ch,k,t,p,';

    /** The slug of config $c: its slug, or else its name as of() makes it (mygo's Config.slug). @param array<string, mixed> $c */
    public static function ofConfig(array $c): string
    {
        $slug = (string)($c['slug'] ?? '');
        return $slug !== '' ? $slug : self::of((string)($c['name'] ?? ''));
    }

    /**
     * $s as a slug, lower-case letters and digits with single hyphens
     * between words, or "app" without any (mygo's slugify).
     */
    public static function of(string $s): string
    {
        $b = '';
        $gap = false; // a hyphen goes before the next word
        $word = function (string $w) use (&$b, &$gap): void {
            if ($gap && $b !== '') { $b .= '-'; }
            $gap = false;
            $b .= $w;
        };
        $rs = array_map(fn(string $c): int => mb_ord($c), mb_str_split(mb_strtolower($s)));
        for ($i = 0, $n = count($rs); $i < $n; $i++) {
            $r = $rs[$i];
            if ($r >= 0x61 && $r <= 0x7a || $r >= 0x30 && $r <= 0x39) {
                $word(chr($r));
            } elseif (($m = self::mandarin($r)) !== '') {
                $gap = true;
                $word($m);
                $gap = true;
            } elseif (self::isHangul($r)) {
                $word(self::hangul($r, $rs[$i + 1] ?? 0));
            } elseif (self::kanaIndex($r) >= 0) {
                [$w, $took] = self::kana(array_slice($rs, $i));
                $word($w);
                $i += $took - 1;
            } else {
                $gap = true;
            }
        }
        return $b === '' ? 'app' : $b;
    }

    /** The reading of a Chinese character without its tone, or "". */
    private static function mandarin(int $r): string
    {
        if ($r < 0x4E00 || $r > 0x9FFF) { return ''; }
        if (self::$pinyin === null) {
            $data = file_get_contents(__DIR__ . '/pinyin.bin');
            if ($data === false || !str_contains($data, "\n")) { throw new RuntimeException('myphp: pinyin.bin is missing'); }
            [$syllables, $table] = explode("\n", $data, 2);
            self::$pinyin = [preg_split('/\s+/', $syllables, -1, PREG_SPLIT_NO_EMPTY), $table];
        }
        [$syllables, $table] = self::$pinyin;
        $i = ($r - 0x4E00) * 2;
        $k = ord($table[$i]) << 8 | ord($table[$i + 1]);
        return $k > 0 ? $syllables[$k - 1] : '';
    }

    private static function isHangul(int $r): bool
    {
        return 0xAC00 <= $r && $r <= 0xD7A3;
    }

    /** Romanizes the Hangul syllable $r, which $next follows. */
    private static function hangul(int $r, int $next): string
    {
        $s = $r - 0xAC00;
        $finals = explode(',', self::isHangul($next) && intdiv($next - 0xAC00, 588) === 11 ? self::HANGUL_FINALS_LINKED : self::HANGUL_FINALS);
        return explode(',', self::HANGUL_INITIALS)[intdiv($s, 588)] . explode(',', self::HANGUL_VOWELS)[intdiv($s % 588, 28)] . $finals[$s % 28];
    }

    /** The position of a kana in KANA_ROMAJI, or -1. */
    private static function kanaIndex(int $r): int
    {
        return match (true) {
            0x3041 <= $r && $r <= 0x3096 => $r - 0x3041,
            0x30A1 <= $r && $r <= 0x30F6 => $r - 0x30A1,
            default => -1,
        };
    }

    /** Romanizes the kana that $rs starts with as one word, and how many runes it took. @param list<int> $rs @return array{0: string, 1: int} */
    private static function kana(array $rs): array
    {
        static $romaji = null;
        $romaji ??= preg_split('/\s+/', self::KANA_ROMAJI);
        $b = '';
        $double = false; // after っ
        for ($n = 0, $len = count($rs); $n < $len; $n++) {
            $r = $rs[$n];
            if ($r === 0x30FC) { continue; } // ー, a long vowel, which the word leaves out, as ramen
            $i = self::kanaIndex($r);
            if ($i < 0) { break; }
            if ($r === 0x3063 || $r === 0x30C3) { $double = true; continue; } // っ ッ
            $ro = $romaji[$i];
            $last = strlen($b) - 1;
            if (($i === 0x42 || $i === 0x44 || $i === 0x46) && $last > 0 && $b[$last] === 'i') {
                // ゃ, ゅ and ょ after the i of a syllable: kya of きゃ, sha of しゃ.
                $b = substr($b, 0, $last);
                if (str_ends_with($b, 'sh') || str_ends_with($b, 'ch') || str_ends_with($b, 'j')) { $ro = substr($ro, 1); }
            } elseif ($i <= 8 && $i % 2 === 0 && $last >= 0 && str_contains('aiueo', $b[$last])) {
                // A small vowel in place of the one before: fa of ファ, ti of ティ, wi of ウィ.
                $b = substr($b, 0, $last);
                if ($last === 0 || str_contains('aiueo', $b[$last - 1])) { $b .= 'w'; }
            }
            if ($double && !str_contains('aiueon', $ro[0])) {
                $b .= str_starts_with($ro, 'ch') ? 't' : $ro[0];
            }
            $double = false;
            $b .= $ro;
        }
        return [$b, $n];
    }
}
