<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * Writes numbers in a locale with options, for writing many (mygo's
 * i18n.NumberFormatter): the locale's decimal and grouping separators, its
 * grouping (the Indian 12,34,567 too, and none of four digits where it
 * groups from five, as Spanish and Polish do), its minus and percent signs,
 * and the digits of its numbering system ("-u-nu-latn" asks for Latin
 * ones). The symbols and patterns are ICU's, the writing mygo's, so that a
 * decimal string is exact however many digits it has.
 * I18n::formatNumber keeps one per locale and options.
 */
final class NumberFormatter
{
    /** @var list<string> the system's digits, 0 to 9 */
    private array $digits;
    private string $decimalSep, $group, $minus, $nan, $infinity;
    private int $minGrouping;
    private string $prefix, $suffix, $negPrefix, $negSuffix;
    private int $primary = 0, $secondary = 0, $minInt, $min, $max;

    public function __construct(string $locale, private readonly NumberOptions $o = new NumberOptions)
    {
        $locale = $locale === '' ? 'en' : $locale;
        $icu = new \NumberFormatter($locale, $o->percent ? \NumberFormatter::PERCENT : \NumberFormatter::DECIMAL);
        $plain = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
        $this->digits = array_map(fn(int $d) => (string)$plain->format($d), range(0, 9));
        $sym = fn(int $s) => (string)$icu->getSymbol($s);
        [$this->decimalSep, $this->group, $this->minus, $this->nan, $this->infinity] = [$sym(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL), $sym(\NumberFormatter::GROUPING_SEPARATOR_SYMBOL), $sym(\NumberFormatter::MINUS_SIGN_SYMBOL), $sym(\NumberFormatter::NAN_SYMBOL), $sym(\NumberFormatter::INFINITY_SYMBOL)];
        $this->minGrouping = self::minGrouping($locale);
        $this->parsePattern((string)$icu->getPattern(), $sym(\NumberFormatter::PERCENT_SYMBOL), $sym(\NumberFormatter::PLUS_SIGN_SYMBOL));
        [$min, $max] = [max(0, $o->minFractionDigits), $o->maxFractionDigits];
        if ($o->integer) {
            [$min, $max] = [0, 0];
        } elseif ($max <= 0 && $min === 0) {
            $max = $this->max;
        }
        [$this->min, $this->max] = [$min, max($max, $min)];
    }

    /**
     * Writes $n (mygo's Format): an int, a float (its fewest digits that
     * read back as it, then rounded: 1.005 is "1.01" with two fraction
     * digits, as Intl writes it), or a string of a decimal number, exact
     * however many digits it has. NaN and infinities are the locale's
     * symbols of them. Throws for a string that is no number.
     */
    public function format(int|float|string $n): string
    {
        if (is_float($n)) {
            if (is_nan($n)) { return $this->prefix . $this->nan . $this->suffix; }
            if (is_infinite($n)) { return $n > 0 ? $this->prefix . $this->infinity . $this->suffix : $this->negPrefix . $this->infinity . $this->negSuffix; }
        }
        return $this->formatDecimal(self::decimal($n));
    }

    /**
     * @internal $n as a decimal string, "-1.5": an int, a finite float by
     * its fewest digits, or a string of a decimal number; throws otherwise.
     */
    public static function decimal(int|float|string $n): string
    {
        if (is_int($n)) { return (string)$n; }
        if (is_float($n)) {
            if (!is_finite($n)) { throw new \InvalidArgumentException('i18n: not a finite number'); }
            return Operands::shortest($n);
        }
        $s = trim($n);
        if (!preg_match('/^-?\d+(\.\d+)?$/', $s)) { throw new \InvalidArgumentException("i18n: \"$n\" is not a decimal number"); }
        return $s;
    }

    /** @internal writes a decimal string, "-1234.5", as mygo's formatDecimal. */
    public function formatDecimal(string $decimal): string
    {
        $neg = str_starts_with($decimal, '-');
        [$int, $frac] = array_pad(explode('.', ltrim($decimal, '-+'), 2), 2, '');
        $int = ltrim($int, '0');
        if ($int === '') { $int = '0'; }
        if ($this->o->percent) {
            $int .= substr($frac . '00', 0, 2);
            $frac = (string)substr($frac, 2);
            $int = ltrim($int, '0');
            if ($int === '') { $int = '0'; }
        }
        [$int, $frac] = self::round($int, $frac, $this->max, $this->o->halfEven);
        while (strlen($frac) > $this->min && str_ends_with($frac, '0')) { $frac = substr($frac, 0, -1); }
        $frac = str_pad($frac, $this->min, '0');
        $out = $neg ? $this->negPrefix : $this->prefix;
        $out .= $this->integer($int);
        if ($frac !== '') { $out .= $this->decimalSep . $this->inDigits($frac); }
        return $out . ($neg ? $this->negSuffix : $this->suffix);
    }

    /** @return array{string, string} the number rounded to $max fraction digits, half away from zero or half to even */
    private static function round(string $int, string $frac, int $max, bool $halfEven): array
    {
        if (strlen($frac) <= $max) { return [$int, $frac]; }
        [$keep, $drop] = [substr($frac, 0, $max), substr($frac, $max)];
        $up = $drop[0] > '5' || $drop[0] === '5' && (trim(substr($drop, 1), '0') !== '' || !$halfEven || (int)($keep !== '' ? $keep[-1] : $int[-1]) % 2 === 1);
        if (!$up) { return [$int, $keep]; }
        $d = $int . $keep;
        for ($i = strlen($d) - 1; $i >= 0; $i--) {
            if ($d[$i] === '9') { $d[$i] = '0'; continue; }
            $d[$i] = chr(ord($d[$i]) + 1);
            break;
        }
        if ($i < 0) { $d = '1' . $d; }
        return [substr($d, 0, strlen($d) - strlen($keep)), substr($d, strlen($d) - strlen($keep))];
    }

    /** The integer digits, padded and grouped as the pattern groups them. */
    private function integer(string $digits): string
    {
        $digits = str_pad($digits, max($this->minInt, $this->o->minIntegerDigits), '0', STR_PAD_LEFT);
        $n = strlen($digits);
        if ($this->o->noGrouping || $this->primary === 0 || $n < $this->primary + $this->minGrouping) {
            return $this->inDigits($digits);
        }
        $first = ($n - $this->primary) % $this->secondary;
        $size = $first === 0 ? $this->secondary : $first;
        $out = '';
        for ($pos = 0; $pos < $n;) {
            $out .= $this->inDigits(substr($digits, $pos, $size));
            $pos += $size;
            if ($pos < $n) { $out .= $this->group; }
            $size = $n - $pos === $this->primary ? $this->primary : $this->secondary;
        }
        return $out;
    }

    private function inDigits(string $ascii): string
    {
        return $this->digits === ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'] ? $ascii : strtr($ascii, array_combine(['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $this->digits));
    }

    /** Reads a pattern of CLDR's, "#,##,##0.###" or "#,##0 %": its affixes in the locale's symbols, its groups and its digits. */
    private function parsePattern(string $pattern, string $percent, string $plus): void
    {
        [$pos, $neg] = array_pad(explode(';', $pattern, 2), 2, null);
        [$this->prefix, $number, $this->suffix] = $this->affixes($pos, $percent, $plus);
        if ($neg !== null) {
            [$this->negPrefix, , $this->negSuffix] = $this->affixes($neg, $percent, $plus);
        } else {
            [$this->negPrefix, $this->negSuffix] = [$this->minus . $this->prefix, $this->suffix];
        }
        [$int, $frac] = array_pad(explode('.', $number, 2), 2, '');
        $this->max = strlen($frac);
        if (($i = strrpos($int, ',')) !== false) {
            $this->primary = $this->secondary = strlen($int) - $i - 1;
            if (($j = strrpos(substr($int, 0, $i), ',')) !== false) { $this->secondary = $i - $j - 1; }
        }
        $this->minInt = substr_count($int, '0');
    }

    /** @return array{string, string, string} a subpattern's prefix, number and suffix, the affixes in the locale's symbols and unquoted */
    private function affixes(string $sub, string $percent, string $plus): array
    {
        [$start, $end, $quoted] = [-1, -1, false];
        for ($i = 0; $i < strlen($sub); $i++) {
            if ($sub[$i] === "'") { $quoted = !$quoted; continue; }
            if (!$quoted && strpbrk($sub[$i], '#0,.@') !== false) {
                if ($start < 0) { $start = $i; }
                $end = $i + 1;
            }
        }
        $affix = function (string $a) use ($percent, $plus): string {
            $out = ''; $quoted = false;
            for ($i = 0; $i < strlen($a); $i++) {
                $c = $a[$i];
                if ($c === "'") {
                    if (($a[$i + 1] ?? '') === "'") { $out .= "'"; $i++; continue; }
                    $quoted = !$quoted;
                } elseif ($quoted) { $out .= $c; }
                else { $out .= match ($c) { '%' => $percent, '-' => $this->minus, '+' => $plus, default => $c }; }
            }
            return $out;
        };
        if ($start < 0) { return [$affix($sub), '', '']; }
        return [$affix(substr($sub, 0, $start)), substr($sub, $start, $end - $start), $affix(substr($sub, $end))];
    }

    /** CLDR's minimumGroupingDigits of a locale, along its parents: 2 where four digits go ungrouped. */
    private static function minGrouping(string $locale): int
    {
        for ($t = I18n::normalize($locale); $t !== ''; $t = I18n::parent($t)) {
            $b = \ResourceBundle::create(str_replace('-', '_', $t), null, false);
            $v = $b?->get('NumberElements')?->get('minimumGroupingDigits');
            if ($v !== null) { return max(1, (int)$v); }
        }
        return 1;
    }
}
