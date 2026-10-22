<?php
declare(strict_types=1);

namespace MyPHP\Native\Update;

/**
 * Ed25519 signatures (RFC 8032) in plain PHP, as Go's crypto/ed25519 makes
 * and checks them: TweetNaCl's arithmetic, field elements as 16 limbs of 16
 * bits in PHP's 64-bit integers, with SHA-512 from hash(). A signature is
 * checked in a few tenths of a second, which an update needs once. Not
 * constant time where TweetNaCl is not either: fine for checking a public
 * signature; signing runs on the developer's machine.
 *
 * @internal
 */
final class Ed25519
{
    public const PUBLIC_KEY_SIZE = 32, PRIVATE_KEY_SIZE = 64, SIGNATURE_SIZE = 64, SEED_SIZE = 32;

    private const D = [0x78a3, 0x1359, 0x4dca, 0x75eb, 0xd8ab, 0x4141, 0x0a4d, 0x0070, 0xe898, 0x7779, 0x4079, 0x8cc7, 0xfe73, 0x2b6f, 0x6cee, 0x5203];
    private const D2 = [0xf159, 0x26b2, 0x9b94, 0xebd6, 0xb156, 0x8283, 0x149a, 0x00e0, 0xd130, 0xeef3, 0x80f2, 0x198e, 0xfce7, 0x56df, 0xd9dc, 0x2406];
    private const X = [0xd51a, 0x8f25, 0x2d60, 0xc956, 0xa7b2, 0x9525, 0xc760, 0x692c, 0xdc5c, 0xfdd6, 0xe231, 0xc0a4, 0x53fe, 0xcd6e, 0x36d3, 0x2169];
    private const Y = [0x6658, 0x6666, 0x6666, 0x6666, 0x6666, 0x6666, 0x6666, 0x6666, 0x6666, 0x6666, 0x6666, 0x6666, 0x6666, 0x6666, 0x6666, 0x6666];
    private const I = [0xa0b0, 0x4a0e, 0x1b27, 0xc4ee, 0xe478, 0xad2f, 0x1806, 0x2f43, 0xd7a7, 0x3dfb, 0x0099, 0x2b4d, 0xdf0b, 0x4fc1, 0x2480, 0x2b83];
    private const GF0 = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
    private const GF1 = [1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
    /** The order of the base point, little endian. */
    private const L = [0xed, 0xd3, 0xf5, 0x5c, 0x1a, 0x63, 0x12, 0x58, 0xd6, 0x9c, 0xf7, 0xa2, 0xde, 0xf9, 0xde, 0x14, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0x10];

    /** The private key (seed and public key, 64 bytes, as Go's) and the public key of $seed, 32 random bytes. @return array{0: string, 1: string} [private, public] */
    public static function keyPair(string $seed): array
    {
        if (strlen($seed) !== self::SEED_SIZE) {
            throw new \InvalidArgumentException('ed25519: bad seed length');
        }
        $d = self::clamp(hash('sha512', $seed, true));
        $pk = self::packPoint(self::scalarBase($d));
        return [$seed . $pk, $pk];
    }

    /** The signature of $message with the 64-byte $privateKey. */
    public static function sign(string $privateKey, string $message): string
    {
        if (strlen($privateKey) !== self::PRIVATE_KEY_SIZE) {
            throw new \InvalidArgumentException('ed25519: bad private key length');
        }
        $h = hash('sha512', substr($privateKey, 0, 32), true);
        $d = self::clamp($h);
        $r = self::reduce(hash('sha512', substr($h, 32) . $message, true));
        $rPoint = self::packPoint(self::scalarBase($r));
        $k = self::reduce(hash('sha512', $rPoint . substr($privateKey, 32) . $message, true));
        $x = array_fill(0, 64, 0);
        for ($i = 0; $i < 32; $i++) {
            $x[$i] = $r[$i];
        }
        for ($i = 0; $i < 32; $i++) {
            for ($j = 0; $j < 32; $j++) {
                $x[$i + $j] += $k[$i] * $d[$j];
            }
        }
        return $rPoint . self::bytes(self::modL($x));
    }

    /** Whether $signature is one of $message by $publicKey. */
    public static function verify(string $publicKey, string $message, string $signature): bool
    {
        if (strlen($publicKey) !== self::PUBLIC_KEY_SIZE || strlen($signature) !== self::SIGNATURE_SIZE) {
            return false;
        }
        $s = array_values(unpack('C*', substr($signature, 32)));
        if (!self::canonical($s)) {
            return false; // as Go's: S must be below L
        }
        $a = self::unpackNeg($publicKey);
        if ($a === null) {
            return false;
        }
        $k = self::reduce(hash('sha512', substr($signature, 0, 32) . $publicKey . $message, true));
        $p = self::scalarMult($a, $k);
        $q = self::scalarBase($s);
        self::add($p, $q);
        return hash_equals(substr($signature, 0, 32), self::packPoint($p));
    }

    /** @param list<int> $s */
    private static function canonical(array $s): bool
    {
        for ($i = 31; $i >= 0; $i--) {
            if ($s[$i] !== self::L[$i]) {
                return $s[$i] < self::L[$i];
            }
        }
        return false; // S == L
    }

    /** The clamped scalar of a SHA-512 digest's first half. @return list<int> */
    private static function clamp(string $h): array
    {
        $d = array_values(unpack('C32', $h));
        $d[0] &= 248;
        $d[31] &= 127;
        $d[31] |= 64;
        return $d;
    }

    /** @param list<int> $b */
    private static function bytes(array $b): string
    {
        return pack('C*', ...$b);
    }

    // ---- the field, mod 2^255 - 19 ------------------------------------

    /** @param list<int> $o */
    private static function car(array &$o): void
    {
        for ($i = 0; $i < 16; $i++) {
            $o[$i] += 65536;
            $c = $o[$i] >> 16;
            if ($i < 15) {
                $o[$i + 1] += $c - 1;
            } else {
                $o[0] += 38 * ($c - 1);
            }
            $o[$i] -= $c << 16;
        }
    }

    private static function sel(array &$p, array &$q, int $b): void
    {
        $c = ~($b - 1);
        for ($i = 0; $i < 16; $i++) {
            $t = $c & ($p[$i] ^ $q[$i]);
            $p[$i] ^= $t;
            $q[$i] ^= $t;
        }
    }

    private static function pack25519(array $n): string
    {
        $t = $n;
        self::car($t);
        self::car($t);
        self::car($t);
        $m = self::GF0;
        for ($j = 0; $j < 2; $j++) {
            $m[0] = $t[0] - 0xffed;
            for ($i = 1; $i < 15; $i++) {
                $m[$i] = $t[$i] - 0xffff - (($m[$i - 1] >> 16) & 1);
                $m[$i - 1] &= 0xffff;
            }
            $m[15] = $t[15] - 0x7fff - (($m[14] >> 16) & 1);
            $b = ($m[15] >> 16) & 1;
            $m[14] &= 0xffff;
            self::sel($t, $m, 1 - $b);
        }
        $o = '';
        for ($i = 0; $i < 16; $i++) {
            $o .= chr($t[$i] & 0xff) . chr($t[$i] >> 8);
        }
        return $o;
    }

    private static function neq(array $a, array $b): bool
    {
        return !hash_equals(self::pack25519($a), self::pack25519($b));
    }

    private static function par(array $a): int
    {
        return ord(self::pack25519($a)[0]) & 1;
    }

    private static function unpack25519(string $n): array
    {
        $o = [];
        for ($i = 0; $i < 16; $i++) {
            $o[] = ord($n[2 * $i]) + (ord($n[2 * $i + 1]) << 8);
        }
        $o[15] &= 0x7fff;
        return $o;
    }

    private static function A(array $a, array $b): array
    {
        $o = [];
        for ($i = 0; $i < 16; $i++) {
            $o[] = $a[$i] + $b[$i];
        }
        return $o;
    }

    private static function Z(array $a, array $b): array
    {
        $o = [];
        for ($i = 0; $i < 16; $i++) {
            $o[] = $a[$i] - $b[$i];
        }
        return $o;
    }

    private static function M(array $a, array $b): array
    {
        $t = array_fill(0, 31, 0);
        for ($i = 0; $i < 16; $i++) {
            $ai = $a[$i];
            for ($j = 0; $j < 16; $j++) {
                $t[$i + $j] += $ai * $b[$j];
            }
        }
        for ($i = 0; $i < 15; $i++) {
            $t[$i] += 38 * $t[$i + 16];
        }
        $o = array_slice($t, 0, 16);
        self::car($o);
        self::car($o);
        return $o;
    }

    private static function inv(array $i): array
    {
        $c = $i;
        for ($a = 253; $a >= 0; $a--) {
            $c = self::M($c, $c);
            if ($a !== 2 && $a !== 4) {
                $c = self::M($c, $i);
            }
        }
        return $c;
    }

    private static function pow2523(array $i): array
    {
        $c = $i;
        for ($a = 250; $a >= 0; $a--) {
            $c = self::M($c, $c);
            if ($a !== 1) {
                $c = self::M($c, $i);
            }
        }
        return $c;
    }

    // ---- points, in extended coordinates [X, Y, Z, T] -------------------

    /** @param array{0: array, 1: array, 2: array, 3: array} $p p += q */
    private static function add(array &$p, array $q): void
    {
        $a = self::M(self::Z($p[1], $p[0]), self::Z($q[1], $q[0]));
        $b = self::M(self::A($p[0], $p[1]), self::A($q[0], $q[1]));
        $c = self::M(self::M($p[3], $q[3]), self::D2);
        $d = self::M($p[2], $q[2]);
        $d = self::A($d, $d);
        $e = self::Z($b, $a);
        $f = self::Z($d, $c);
        $g = self::A($d, $c);
        $h = self::A($b, $a);
        $p = [self::M($e, $f), self::M($h, $g), self::M($g, $f), self::M($e, $h)];
    }

    private static function cswap(array &$p, array &$q, int $b): void
    {
        for ($i = 0; $i < 4; $i++) {
            self::sel($p[$i], $q[$i], $b);
        }
    }

    private static function packPoint(array $p): string
    {
        $zi = self::inv($p[2]);
        $tx = self::M($p[0], $zi);
        $ty = self::M($p[1], $zi);
        $r = self::pack25519($ty);
        $r[31] = chr(ord($r[31]) ^ (self::par($tx) << 7));
        return $r;
    }

    /** $s times the point $q. @param list<int> $s 32 bytes, little endian */
    private static function scalarMult(array $q, array $s): array
    {
        $p = [self::GF0, self::GF1, self::GF1, self::GF0];
        for ($i = 255; $i >= 0; $i--) {
            $b = ($s[$i >> 3] >> ($i & 7)) & 1;
            self::cswap($p, $q, $b);
            self::add($q, $p);
            self::add($p, $p);
            self::cswap($p, $q, $b);
        }
        return $p;
    }

    private static function scalarBase(array $s): array
    {
        return self::scalarMult([self::X, self::Y, self::GF1, self::M(self::X, self::Y)], $s);
    }

    /** The negation of the point $p encodes, null when it is none. */
    private static function unpackNeg(string $p): ?array
    {
        $r2 = self::GF1;
        $r1 = self::unpack25519($p);
        $num = self::M($r1, $r1);
        $den = self::M($num, self::D);
        $num = self::Z($num, $r2);
        $den = self::A($r2, $den);
        $den2 = self::M($den, $den);
        $den4 = self::M($den2, $den2);
        $den6 = self::M($den4, $den2);
        $t = self::M($den6, $num);
        $t = self::M($t, $den);
        $t = self::pow2523($t);
        $t = self::M($t, $num);
        $t = self::M($t, $den);
        $t = self::M($t, $den);
        $r0 = self::M($t, $den);
        $chk = self::M(self::M($r0, $r0), $den);
        if (self::neq($chk, $num)) {
            $r0 = self::M($r0, self::I);
        }
        $chk = self::M(self::M($r0, $r0), $den);
        if (self::neq($chk, $num)) {
            return null;
        }
        if (self::par($r0) === (ord($p[31]) >> 7)) {
            $r0 = self::Z(self::GF0, $r0);
        }
        return [$r0, $r1, $r2, self::M($r0, $r1)];
    }

    // ---- scalars, mod L --------------------------------------------------

    /** @param list<int> $x 64 limbs @return list<int> 32 bytes */
    private static function modL(array $x): array
    {
        for ($i = 63; $i >= 32; $i--) {
            $carry = 0;
            for ($j = $i - 32; $j < $i - 12; $j++) {
                $x[$j] += $carry - 16 * $x[$i] * self::L[$j - ($i - 32)];
                $carry = ($x[$j] + 128) >> 8;
                $x[$j] -= $carry << 8;
            }
            $x[$j] += $carry;
            $x[$i] = 0;
        }
        $carry = 0;
        for ($j = 0; $j < 32; $j++) {
            $x[$j] += $carry - ($x[31] >> 4) * self::L[$j];
            $carry = $x[$j] >> 8;
            $x[$j] &= 255;
        }
        for ($j = 0; $j < 32; $j++) {
            $x[$j] -= $carry * self::L[$j];
        }
        $r = [];
        for ($i = 0; $i < 32; $i++) {
            $x[$i + 1] += $x[$i] >> 8;
            $r[] = $x[$i] & 255;
        }
        return $r;
    }

    /** A 64-byte digest, mod L. @return list<int> */
    private static function reduce(string $h): array
    {
        return self::modL(array_values(unpack('C64', $h)));
    }
}
