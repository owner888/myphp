<?php
declare(strict_types=1);

namespace MyPHP\UI\Shader\Ir;

/**
 * A constant of a shader (Go's go/constant, as much of it as the compiler
 * uses): a bool, a string, an integer or a float, untyped until the
 * compiler gives it a type. Go keeps integers of any size and floats as
 * exact fractions; here an integer is a PHP int, a float when it outgrows
 * 64 bits (its kind still INT), and a float a PHP float, with the exact
 * fraction it is while its numerator and denominator fit in 64 bits, so
 * that arithmetic of constants rounds once, as Go's does: 0.13 * 4.5 is
 * 0.585, not the 0.5850000000000001 of floats.
 *
 * @internal
 */
final class Constant
{
    /** The kinds, as go/constant's Kind. */
    public const UNKNOWN = 0, BOOL = 1, STRING = 2, INT = 3, FLOAT = 4, COMPLEX = 5;

    private function __construct(
        public readonly int $kind,
        public readonly bool|int|float|string|null $value,
        /** A float's exact value, [numerator, denominator], the denominator above 0 and the two coprime; null for one beyond 64 bits. @var ?array{0: int, 1: int} */
        private readonly ?array $rat = null,
    ) {}

    public static function unknown(): self { return new self(self::UNKNOWN, null); }

    public static function bool(bool $b): self { return new self(self::BOOL, $b); }

    public static function string(string $s): self { return new self(self::STRING, $s); }

    /** An integer: a float beyond 64 bits, which stays an integer of that size. */
    public static function int(int|float $i): self
    {
        if (is_float($i)) {
            if (!is_finite($i)) {
                return self::unknown();
            }
            $i = floor($i) === $i ? $i : (float)(int)$i;
            if ($i >= -9.2233720368547758E18 && $i < 9.2233720368547758E18) {
                $i = (int)$i;
            }
        }
        return new self(self::INT, $i);
    }

    /** A float: exactly $f, as go/constant's MakeFloat64. */
    public static function float(float $f): self
    {
        return is_finite($f) ? new self(self::FLOAT, $f, self::ratOfFloat($f)) : self::unknown();
    }

    /** The float $n / $d, exactly, or as near as a PHP float comes when the fraction outgrows 64 bits. */
    private static function ratio(int|float $n, int|float $d): self
    {
        if (is_float($n) || is_float($d) || $d === 0 || $n === PHP_INT_MIN || $d === PHP_INT_MIN) {
            return $d == 0 ? self::unknown() : self::float($n / $d);
        }
        if ($d < 0) {
            $n = -$n; $d = -$d;
        }
        $g = self::gcd(abs($n), $d);
        $n = intdiv($n, $g); $d = intdiv($d, $g);
        $f = (float)$n / (float)$d;
        return is_finite($f) ? new self(self::FLOAT, $f, [$n, $d]) : self::unknown();
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        return $a === 0 ? 1 : $a;
    }

    /** $f as a fraction, its denominator a power of 2, or null where that outgrows 64 bits. @return ?array{0: int, 1: int} */
    private static function ratOfFloat(float $f): ?array
    {
        $d = 1;
        while (floor($f) !== $f) {
            if ($d > 1 << 61) {
                return null;
            }
            $f *= 2; $d *= 2;
        }
        if (abs($f) >= 9.2233720368547758E18) {
            return null;
        }
        $n = (int)$f;
        $g = self::gcd(abs($n), $d);
        return [intdiv($n, $g), intdiv($d, $g)];
    }

    /** The exact fraction of a float or an integer, or null for none. @return ?array{0: int, 1: int} */
    private static function ratOf(self $x): ?array
    {
        return match (true) {
            $x->kind === self::FLOAT => $x->rat,
            $x->kind === self::INT && is_int($x->value) => [$x->value, 1],
            default => null,
        };
    }

    /** The constant of a literal of $kind (Syntax\Token's INT, FLOAT, IMAG, CHAR or STRING), unknown for one it can't read (go/constant's MakeFromLiteral). */
    public static function fromLiteral(string $lit, string $kind): self
    {
        $digits = str_replace('_', '', $lit);
        switch ($kind) {
            case 'INT':
                return self::parseInt($digits) ?? self::unknown();
            case 'FLOAT':
                $f = self::parseFloat($digits);
                if ($f === null) {
                    return self::unknown();
                }
                return self::decimalRat($digits) ?? self::float($f);
            case 'IMAG':
                return new self(self::COMPLEX, substr($digits, 0, -1));
            case 'CHAR':
                $s = self::unquote($lit);
                return $s === null || mb_strlen($s, 'UTF-8') !== 1 ? self::unknown() : self::int(mb_ord($s, 'UTF-8'));
            case 'STRING':
                $s = self::unquote($lit);
                return $s === null ? self::unknown() : self::string($s);
        }
        return self::unknown();
    }

    private static function parseInt(string $d): ?self
    {
        [$base, $body] = match (true) {
            preg_match('/^0[xX]/', $d) === 1 => [16, substr($d, 2)],
            preg_match('/^0[bB]/', $d) === 1 => [2, substr($d, 2)],
            preg_match('/^0[oO]/', $d) === 1 => [8, substr($d, 2)],
            strlen($d) > 1 && $d[0] === '0' => [8, substr($d, 1)],
            default => [10, $d],
        };
        if ($body === '' || !preg_match($base === 16 ? '/^[0-9a-fA-F]+$/' : ($base === 10 ? '/^[0-9]+$/' : ($base === 8 ? '/^[0-7]+$/' : '/^[01]+$/')), $body)) {
            return null;
        }
        $v = 0; $big = false; $f = 0.0;
        foreach (str_split(strtolower($body)) as $c) {
            $x = (int)hexdec($c);
            if (!$big && $v > intdiv(PHP_INT_MAX - $x, $base)) {
                $big = true;
                $f = (float)$v;
            }
            if ($big) { $f = $f * $base + $x; } else { $v = $v * $base + $x; }
        }
        return self::int($big ? $f : $v);
    }

    /** The exact float of the decimal literal $d, or null when it is hexadecimal or outgrows 64 bits. */
    private static function decimalRat(string $d): ?self
    {
        if (!preg_match('/^([0-9]*)(?:\.([0-9]*))?(?:[eE]([+-]?[0-9]+))?$/', $d, $m)) {
            return null;
        }
        $digits = ltrim($m[1] . ($m[2] ?? ''), '0');
        $exp = (int)($m[3] ?? 0) - strlen($m[2] ?? '');
        if ($digits === '') {
            return self::ratio(0, 1);
        }
        if (strlen($digits) > 18 || abs($exp) > 18 || $exp > 0 && strlen($digits) + $exp > 18) {
            return null;
        }
        return $exp >= 0 ? self::ratio((int)$digits * 10 ** $exp, 1) : self::ratio((int)$digits, 10 ** -$exp);
    }

    private static function parseFloat(string $d): ?float
    {
        if (preg_match('/^0[xX]([0-9a-fA-F]*)(?:\.([0-9a-fA-F]*))?[pP]([+-]?[0-9]+)$/', $d, $m)) {
            $mant = 0.0;
            foreach (str_split($m[1] . ($m[2] ?? '')) as $c) {
                if ($c !== '') { $mant = $mant * 16 + hexdec($c); }
            }
            return $mant * 2 ** ((int)$m[3] - 4 * strlen($m[2] ?? ''));
        }
        if (!is_numeric($d) && !preg_match('/^[0-9]*\.?[0-9]*([eE][+-]?[0-9]+)?$/', $d)) {
            return null;
        }
        return (float)$d;
    }

    /** The text of a quoted Go string or rune, its escapes read, or null. */
    private static function unquote(string $lit): ?string
    {
        if (strlen($lit) < 2) {
            return null;
        }
        $q = $lit[0];
        $body = substr($lit, 1, -1);
        if ($q === '`') {
            return str_replace("\r", '', $body);
        }
        $out = '';
        for ($i = 0, $n = strlen($body); $i < $n; $i++) {
            $c = $body[$i];
            if ($c !== '\\') {
                $out .= $c;
                continue;
            }
            $e = $body[++$i] ?? '';
            switch ($e) {
                case 'a': $out .= "\x07"; break;
                case 'b': $out .= "\x08"; break;
                case 'f': $out .= "\f"; break;
                case 'n': $out .= "\n"; break;
                case 'r': $out .= "\r"; break;
                case 't': $out .= "\t"; break;
                case 'v': $out .= "\v"; break;
                case '\\': case "'": case '"': $out .= $e; break;
                case 'x': $out .= chr((int)hexdec(substr($body, $i + 1, 2))); $i += 2; break;
                case 'u': $out .= mb_chr((int)hexdec(substr($body, $i + 1, 4)), 'UTF-8'); $i += 4; break;
                case 'U': $out .= mb_chr((int)hexdec(substr($body, $i + 1, 8)), 'UTF-8'); $i += 8; break;
                default:
                    if ($e >= '0' && $e <= '7') { $out .= chr((int)octdec(substr($body, $i, 3)) & 255); $i += 2; break; }
                    return null;
            }
        }
        return $out;
    }

    /** $x as an integer, if it is one, or unknown (go/constant's ToInt): a float within rounding of an integer is that integer. */
    public static function toInt(self $x): self
    {
        if ($x->kind === self::INT) {
            return $x;
        }
        if ($x->kind !== self::FLOAT) {
            return self::unknown();
        }
        if ($x->rat !== null) {
            return $x->rat[1] === 1 ? self::int($x->rat[0]) : self::unknown();
        }
        $f = $x->value;
        if (floor($f) === $f) {
            return self::int($f);
        }
        // Rounding errors of earlier computations, as Go allows a few bits.
        $r = round($f);
        if (abs($f - $r) <= abs($f) * 3.552713678800501E-15) {
            return self::int($r);
        }
        return self::unknown();
    }

    /** $x as a float, or unknown (go/constant's ToFloat). */
    public static function toFloat(self $x): self
    {
        return match ($x->kind) {
            self::INT => is_int($x->value) ? self::ratio($x->value, 1) : self::float($x->value),
            self::FLOAT => $x,
            default => self::unknown(),
        };
    }

    /** $x's int64 and whether it is exact (go/constant's Int64Val). @return array{0: int, 1: bool} */
    public static function int64Val(self $x): array
    {
        if (is_int($x->value)) {
            return [$x->value, true];
        }
        return [is_float($x->value) ? (int)max(min($x->value, 4.6116860184273879E18), -4.6116860184273879E18) : 0, false];
    }

    /** $x's uint64 (as an int, the same bits) and whether it is exact (go/constant's Uint64Val). @return array{0: int, 1: bool} */
    public static function uint64Val(self $x): array
    {
        if (is_int($x->value)) {
            return [$x->value, $x->value >= 0];
        }
        if (is_float($x->value) && $x->value >= 0 && $x->value < 1.8446744073709552E19) {
            return [(int)($x->value - 9.2233720368547758E18) ^ PHP_INT_MIN, true]; // 2^63 and above, in two's complement
        }
        return [0, false];
    }

    /** $x's float64 and whether it is exact (go/constant's Float64Val). @return array{0: float, 1: bool} */
    public static function float64Val(self $x): array
    {
        return match ($x->kind) {
            self::INT => [(float)$x->value, is_int($x->value) ? abs($x->value) <= 2 ** 53 : true],
            self::FLOAT => [$x->value, true],
            default => [0.0, false],
        };
    }

    public static function boolVal(self $x): bool
    {
        return $x->value === true;
    }

    /** -1, 0 or 1, as $x is below, at or above 0 (go/constant's Sign). */
    public static function sign(self $x): int
    {
        return is_int($x->value) || is_float($x->value) ? ($x->value <=> 0) : 0;
    }

    /** The kind both of $x and $y become in an operation: the larger (go/constant's match). */
    private static function match(self $x, self $y): array
    {
        if ($x->kind === $y->kind || $x->kind === self::UNKNOWN || $y->kind === self::UNKNOWN) {
            return [$x, $y];
        }
        if ($x->kind === self::INT && $y->kind === self::FLOAT) {
            return [self::toFloat($x), $y];
        }
        if ($x->kind === self::FLOAT && $y->kind === self::INT) {
            return [$x, self::toFloat($y)];
        }
        return [$x, $y];
    }

    /**
     * $x $op $y (go/constant's BinaryOp): $op one of + - * / % & | ^ &^ &&
     * ||, and "/=" for the integer division of integers (Go's QUO_ASSIGN);
     * / of integers is the float of their quotient. Unknown where the
     * operation is not defined.
     */
    public static function binaryOp(self $x, string $op, self $y): self
    {
        [$x, $y] = self::match($x, $y);
        if ($x->kind !== $y->kind || $x->kind === self::UNKNOWN) {
            return self::unknown();
        }
        $a = $x->value; $b = $y->value;
        switch ($x->kind) {
            case self::BOOL:
                return match ($op) { '&&' => self::bool($a && $b), '||' => self::bool($a || $b), default => self::unknown() };
            case self::STRING:
                return $op === '+' ? self::string($a . $b) : self::unknown();
            case self::INT:
                if ($op === '/') {
                    return $b == 0 ? self::unknown() : self::ratio($a, $b);
                }
                if (is_float($a) || is_float($b)) { // beyond 64 bits
                    return match ($op) {
                        '+' => self::int($a + $b), '-' => self::int($a - $b), '*' => self::int($a * $b),
                        '/=' => $b == 0 ? self::unknown() : self::int(self::trunc($a / $b)),
                        '%' => $b == 0 ? self::unknown() : self::int(fmod((float)$a, (float)$b)),
                        default => self::unknown(),
                    };
                }
                return match ($op) {
                    '+' => self::int(self::overflows($a + $b) ? (float)$a + $b : $a + $b),
                    '-' => self::int(self::overflows($a - $b) ? (float)$a - $b : $a - $b),
                    '*' => self::int(self::overflows($a * $b) ? (float)$a * $b : $a * $b),
                    '/=' => $b === 0 ? self::unknown() : ($a === PHP_INT_MIN && $b === -1 ? self::int(-(float)$a) : self::int(intdiv($a, $b))),
                    '%' => $b === 0 ? self::unknown() : ($b === -1 ? self::int(0) : self::int($a % $b)),
                    '&' => self::int($a & $b), '|' => self::int($a | $b), '^' => self::int($a ^ $b), '&^' => self::int($a & ~$b),
                    default => self::unknown(),
                };
            case self::FLOAT:
                if ($x->rat !== null && $y->rat !== null) {
                    [$p, $q] = $x->rat; [$r, $t] = $y->rat;
                    [$n, $d] = match ($op) {
                        '+' => [$p * $t + $r * $q, $q * $t],
                        '-' => [$p * $t - $r * $q, $q * $t],
                        '*' => [$p * $r, $q * $t],
                        '/' => [$p * $t, $q * $r],
                        default => [null, null],
                    };
                    // A fraction that outgrows 64 bits (PHP makes it a float) is the floats' sum, product or quotient, as below.
                    if (is_int($n) && is_int($d)) {
                        return $d === 0 ? self::unknown() : self::ratio($n, $d);
                    }
                }
                return match ($op) {
                    '+' => self::float($a + $b), '-' => self::float($a - $b), '*' => self::float($a * $b),
                    '/' => $b == 0 ? self::unknown() : self::float($a / $b),
                    default => self::unknown(),
                };
        }
        return self::unknown();
    }

    /** Whether an int operation's result is a float, as PHP makes it past 64 bits. */
    private static function overflows(int|float $r): bool
    {
        return is_float($r);
    }

    private static function trunc(float $f): float
    {
        return $f < 0 ? ceil($f) : floor($f);
    }

    /**
     * $op $x (go/constant's UnaryOp): + - ! and ^, ^ complementing the low
     * $prec bits of an unsigned integer for $prec > 0, and all of a signed
     * one for 0.
     */
    public static function unaryOp(string $op, self $x, int $prec): self
    {
        switch ($op) {
            case '+':
                return in_array($x->kind, [self::INT, self::FLOAT], true) ? $x : self::unknown();
            case '-':
                return match ($x->kind) {
                    self::INT => self::int(is_int($x->value) && $x->value !== PHP_INT_MIN ? -$x->value : -(float)$x->value),
                    self::FLOAT => $x->rat !== null && $x->rat[0] !== PHP_INT_MIN ? new self(self::FLOAT, -$x->value, [-$x->rat[0], $x->rat[1]]) : self::float(-$x->value),
                    default => self::unknown(),
                };
            case '!':
                return $x->kind === self::BOOL ? self::bool(!$x->value) : self::unknown();
            case '^':
                if ($x->kind !== self::INT || !is_int($x->value)) {
                    return self::unknown();
                }
                if ($prec > 0 && $prec < 64) {
                    return self::int(~$x->value & ((1 << $prec) - 1));
                }
                return self::int(~$x->value);
        }
        return self::unknown();
    }

    /** $x << $s or $x >> $s, $op "<<" or ">>", of an integer (go/constant's Shift). */
    public static function shift(self $x, string $op, int $s): self
    {
        if ($x->kind !== self::INT || $s < 0) {
            return self::unknown();
        }
        $a = $x->value;
        if ($op === '>>') {
            if (is_float($a)) {
                return self::int(floor($a / 2 ** $s));
            }
            return self::int($s >= 64 ? ($a < 0 ? -1 : 0) : $a >> $s);
        }
        if ($op !== '<<') {
            return self::unknown();
        }
        if ($a === 0) {
            return $x;
        }
        if (is_int($a) && $s < 63 && abs($a) <= (PHP_INT_MAX >> $s)) {
            return self::int($a << $s);
        }
        return self::int((float)$a * 2 ** $s);
    }

    /** $x $op $y, $op one of == != < <= > >= (go/constant's Compare). */
    public static function compare(self $x, string $op, self $y): bool
    {
        [$x, $y] = self::match($x, $y);
        $a = $x->value; $b = $y->value;
        $l = self::ratOf($x); $r = self::ratOf($y);
        if ($l !== null && $r !== null && !is_float($p = $l[0] * $r[1]) && !is_float($q = $r[0] * $l[1])) {
            $a = $p; $b = $q; // the fractions compared exactly, their denominators above 0
        }
        return match ($op) {
            '==' => $a == $b, '!=' => $a != $b, '<' => $a < $b, '<=' => $a <= $b, '>' => $a > $b, '>=' => $a >= $b,
            default => false,
        };
    }

    /** As Go's Value.String prints it: a float to six digits, as %.6g, without losing a fraction it has. */
    public function __toString(): string
    {
        return match ($this->kind) {
            self::BOOL => $this->value ? 'true' : 'false',
            self::STRING => json_encode($this->value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            self::INT => is_int($this->value) ? (string)$this->value : sprintf('%.0f', $this->value),
            self::FLOAT => self::g($this->value),
            self::COMPLEX => "({$this->value}i)",
            default => 'unknown',
        };
    }

    /** $f as Go's %.6g, or %g where that loses a fraction. */
    private static function g(float $f): string
    {
        $s = self::goG($f, 6);
        if (floor($f) !== $f && !str_contains($s, '.') && !str_contains($s, 'e')) {
            $s = self::shortest($f);
        }
        return $s;
    }

    /** $f as Go's %g: the shortest digits that read back as it. */
    private static function shortest(float $f): string
    {
        $r = var_export($f, true); // the shortest round trip, as 1.0E+25 or 0.1
        if (preg_match('/^(-?[0-9.]+)E([+-])([0-9]+)$/', $r, $m)) {
            $mant = str_contains($m[1], '.') ? rtrim(rtrim($m[1], '0'), '.') : $m[1];
            return sprintf('%se%s%02d', $mant, $m[2], (int)$m[3]);
        }
        return str_contains($r, '.') ? rtrim(rtrim($r, '0'), '.') : $r;
    }

    /** Go's %.{p}g: the shortest of %e and %f at p significant digits, without trailing zeros, the exponent of at least two digits. */
    private static function goG(float $f, int $p): string
    {
        if ($f == 0) {
            return '0';
        }
        $e = sprintf('%.' . ($p - 1) . 'e', $f);
        $exp = (int)substr($e, strpos($e, 'e') + 1); // as rounded
        if ($exp < -4 || $exp >= $p) {
            [$m, $x] = explode('e', $e);
            $m = str_contains($m, '.') ? rtrim(rtrim($m, '0'), '.') : $m;
            return sprintf('%se%s%02d', $m, $x[0] === '-' ? '-' : '+', abs((int)$x));
        }
        $s = sprintf('%.' . max(0, $p - 1 - $exp) . 'f', $f);
        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
