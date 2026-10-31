<?php
declare(strict_types=1);

namespace MyPHP\UI;

use MyPHP\L10n;

/**
 * @internal What a decimalInput (Context::decimalInput) keeps from frame
 * to frame (mygo's decimalInput). Values are integers in units of
 * 10^-scale, written in decimal digits after an optional '-' ("-150" for
 * -1.50 at scale 2): math/big's integers in mygo, added and compared here
 * digit by digit, so no amount is ever a float.
 */
final class DecimalInput
{
    /** What is wrong with a decimal typed (mygo's decimalFault): nothing, not a number, more decimals than the step's (not zeros), below lo, above hi. */
    public const FINE = 0, INVALID = 1, TOO_EXACT = 2, BELOW = 3, ABOVE = 4;

    /** What the input shows. */
    public TextState $text;
    /** The value the text was last made from, or that typing set; $v that value in units, null for none ("") or one that is not a number of the scale. */
    public string $shown = '';
    public ?string $v = null;
    /** What is wrong with the text, or with the value the app set. */
    public int $fault = self::FINE;
    /** The arguments the limits were made from: $min and $max, null for none, and $unit, the step, in units of 10^-$scale, $scale the decimals of the step. */
    private string $lo = '', $hi = '', $step = '';
    public ?string $min = null, $max = null;
    public string $unit = '1';
    public int $scale = 0;
    private bool $ready = false;
    /** The range, the step and the value for assistive technology. */
    public float $minF = -INF, $maxF = INF, $unitF = 1.0, $now = 0.0;

    public function __construct()
    {
        $this->text = new TextState('');
    }

    /** Parses the bounds and the step as they change, and reports whether they did (mygo's limits). Throws for one that is not a decimal, and for lo or hi with more decimals than step. */
    public function limits(string $lo, string $hi, string $step): bool
    {
        if ($this->ready && $lo === $this->lo && $hi === $this->hi && $step === $this->step) {
            return false;
        }
        [$this->ready, $this->lo, $this->hi, $this->step] = [true, $lo, $hi, $step];
        $this->scale = strlen(explode('.', trim($step), 2)[1] ?? '');
        [$u, $f] = self::parseDecimal($step, $this->scale);
        if ($f !== self::FINE || self::cmp($u, '0') <= 0) {
            throw new \InvalidArgumentException('ui: DecimalInput\'s step ' . self::quote($step) . ' is not a positive decimal');
        }
        [$this->unit, $this->unitF] = [$u, self::decimalFloat($u, $this->scale)];
        $bound = function (string $s, float $inf) use ($step): array {
            if ($s === '') { return [null, $inf]; }
            [$v, $f] = self::parseDecimal($s, $this->scale);
            if ($f !== self::FINE) {
                throw new \InvalidArgumentException('ui: DecimalInput\'s bound ' . self::quote($s) . ' is not a decimal with at most the decimals of its step ' . self::quote($step));
            }
            return [$v, self::decimalFloat($v, $this->scale)];
        };
        [$this->min, $this->minF] = $bound($lo, -INF);
        [$this->max, $this->maxF] = $bound($hi, INF);
        return true;
    }

    /** Shows $s, a value the app set or typing did: written in full, or as it is when it is not a number of the scale, which is then wrong (mygo's show). */
    public function show(string $s): void
    {
        [$this->shown, $this->v, $this->fault] = [$s, null, self::FINE];
        $this->put($s);
        $this->now = max($this->minF, min($this->maxF, 0.0));
        if (trim($s) === '') { return; }
        [$v, $f] = self::parseDecimal($s, $this->scale);
        if ($f !== self::FINE) {
            $this->fault = $f;
            return;
        }
        [$this->v, $this->fault] = [$v, $this->inRange($v)];
        $this->put(self::formatDecimal($v, $this->scale));
        $this->now = self::decimalFloat($v, $this->scale);
    }

    /** Sets the text the input shows, the caret after it. */
    public function put(string $s): void
    {
        if ($this->text->text === $s) { return; }
        $this->text->text = $s;
        $this->text->setCursor(strlen($s));
    }

    /** The value written in full (mygo's full). */
    public function full(): string
    {
        return $this->v === null ? $this->shown : self::formatDecimal($this->v, $this->scale);
    }

    /** Whether $v is below lo or above hi (mygo's inRange). */
    public function inRange(string $v): int
    {
        if ($this->min !== null && self::cmp($v, $this->min) < 0) { return self::BELOW; }
        if ($this->max !== null && self::cmp($v, $this->max) > 0) { return self::ABOVE; }
        return self::FINE;
    }

    /** What the input says of its fault, in the language of $texts (mygo's message). */
    public function message(L10n $texts): string
    {
        return match ($this->fault) {
            self::TOO_EXACT => $this->scale === 0 ? $texts->get('decimalWhole') : $texts->get('decimalPlaces', ['n' => $this->scale]),
            self::BELOW => $texts->get('decimalAtLeast', ['min' => self::formatDecimal((string)$this->min, $this->scale)]),
            self::ABOVE => $texts->get('decimalAtMost', ['max' => self::formatDecimal((string)$this->max, $this->scale)]),
            default => $texts->get('decimalInvalid'),
        };
    }

    /**
     * $s, a decimal as "-1,234.50", in units of 10^-$scale, with what is
     * wrong with it (mygo's parseDecimal): digits, with '.' before the
     * decimals and ',' between groups of three digits before it, after a
     * sign. Decimals past $scale must be zeros, which it never rounds.
     * @return array{0: ?string, 1: int}
     */
    public static function parseDecimal(string $s, int $scale): array
    {
        $s = trim($s);
        $neg = str_starts_with($s, '-');
        if ($neg || str_starts_with($s, '+')) { $s = substr($s, 1); }
        [$whole, $frac] = explode('.', $s, 2) + [1 => ''];
        if ($whole === '' && $frac === '') { return [null, self::INVALID]; }
        if (str_contains($whole, ',')) {
            foreach (explode(',', $whole) as $i => $g) {
                if ($g === '' || strlen($g) > 3 || ($i > 0 && strlen($g) !== 3)) { return [null, self::INVALID]; }
            }
            $whole = str_replace(',', '', $whole);
        }
        if (preg_match('/^[0-9]*$/', $whole . $frac) !== 1) { return [null, self::INVALID]; }
        if (strlen($frac) > $scale) {
            if (rtrim(substr($frac, $scale), '0') !== '') { return [null, self::TOO_EXACT]; }
            $frac = substr($frac, 0, $scale);
        }
        $v = ltrim($whole . $frac . str_repeat('0', $scale - strlen($frac)), '0');
        return [$v === '' ? '0' : ($neg ? "-$v" : $v), self::FINE];
    }

    /** $v, in units of 10^-$scale, written with $scale decimals (mygo's formatDecimal). */
    public static function formatDecimal(string $v, int $scale): string
    {
        [$sign, $s] = str_starts_with($v, '-') ? ['-', substr($v, 1)] : ['', $v];
        if ($scale === 0) { return $sign . $s; }
        if (strlen($s) <= $scale) { $s = str_repeat('0', $scale - strlen($s) + 1) . $s; }
        return $sign . substr($s, 0, -$scale) . '.' . substr($s, -$scale);
    }

    /** $v, in units of 10^-$scale, as the nearest float (mygo's decimalFloat), for assistive technology alone. */
    public static function decimalFloat(string $v, int $scale): float
    {
        return (float)self::formatDecimal($v, $scale);
    }

    /** $a + $b, integers in decimal digits after an optional '-' (math/big's Add). */
    public static function add(string $a, string $b): string
    {
        [$na, $ma] = [str_starts_with($a, '-'), ltrim($a, '-')];
        [$nb, $mb] = [str_starts_with($b, '-'), ltrim($b, '-')];
        if ($na === $nb) { return self::signed($na, self::addDigits($ma, $mb)); }
        $c = self::cmpDigits($ma, $mb);
        return $c >= 0 ? self::signed($na, self::subDigits($ma, $mb)) : self::signed($nb, self::subDigits($mb, $ma));
    }

    /** -$a (math/big's Neg). */
    public static function neg(string $a): string
    {
        return $a === '0' ? '0' : (str_starts_with($a, '-') ? substr($a, 1) : "-$a");
    }

    /** -1, 0 or 1 as $a is less than, equal to or greater than $b (math/big's Cmp). */
    public static function cmp(string $a, string $b): int
    {
        [$na, $nb] = [str_starts_with($a, '-'), str_starts_with($b, '-')];
        if ($na !== $nb) { return $na ? -1 : 1; }
        $c = self::cmpDigits(ltrim($a, '-'), ltrim($b, '-'));
        return $na ? -$c : $c;
    }

    /** Compares two runs of digits without leading zeros. */
    private static function cmpDigits(string $a, string $b): int
    {
        return strlen($a) <=> strlen($b) ?: ($a <=> $b);
    }

    private static function addDigits(string $a, string $b): string
    {
        $out = ''; $carry = 0;
        for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0 || $j >= 0 || $carry > 0; $i--, $j--) {
            $d = ($i >= 0 ? (int)$a[$i] : 0) + ($j >= 0 ? (int)$b[$j] : 0) + $carry;
            $out = ($d % 10) . $out; $carry = intdiv($d, 10);
        }
        return $out;
    }

    /** $a - $b, $a not less than $b. */
    private static function subDigits(string $a, string $b): string
    {
        $out = ''; $borrow = 0;
        for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0; $i--, $j--) {
            $d = (int)$a[$i] - ($j >= 0 ? (int)$b[$j] : 0) - $borrow;
            $borrow = $d < 0 ? 1 : 0;
            $out = ($d + 10 * $borrow) . $out;
        }
        $out = ltrim($out, '0');
        return $out === '' ? '0' : $out;
    }

    private static function signed(bool $neg, string $digits): string
    {
        return $neg && $digits !== '0' ? "-$digits" : $digits;
    }

    /** $s quoted, as Go's strconv.Quote writes it in mygo's messages. */
    private static function quote(string $s): string
    {
        return json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
