<?php
declare(strict_types=1);

namespace MyPHP\Native\Docs;

use IntlChar;
use MyPHP\{App, L10n};

/**
 * The names of untitled documents, in the language the app shows:
 * "Untitled", "Untitled 2" (mygo's untitledName and untitledNumber).
 *
 * @internal
 */
final class Untitled
{
    public static function name(int $n): string
    {
        $t = L10n::in(App::locale());
        return $n === 1 ? $t->get('untitled') : $t->get('untitledNumbered', ['n' => $n]);
    }

    /** The number of an untitled document by its name, in the language the app shows or in English (the app's language may have changed since it was named), or 0. */
    public static function number(string $name): int
    {
        return self::numberIn($name, App::locale(), 'en');
    }

    /** number() with the names of languages, whose digits it reads: "Untitled ۲" in Persian is 2. */
    public static function numberIn(string $name, string ...$langs): int
    {
        foreach ($langs as $lang) {
            $t = L10n::in($lang);
            if ($name === $t->get('untitled')) {
                return 1;
            }
            // The message around the number: what two numbers' names share.
            [$two, $three] = [$t->get('untitledNumbered', ['n' => 2]), $t->get('untitledNumbered', ['n' => 3])];
            $before = self::common($two, $three);
            $after = strrev(self::common(strrev($two), strrev($three)));
            if (!str_starts_with($name, $before) || !str_ends_with($name, $after) || strlen($name) <= strlen($before) + strlen($after)) {
                continue;
            }
            $n = self::decimal(substr($name, strlen($before), strlen($name) - strlen($before) - strlen($after)));
            if ($n !== null && $n > 1) {
                return $n;
            }
        }
        return 0;
    }

    /** The longest prefix of $a and $b, whole characters. */
    private static function common(string $a, string $b): string
    {
        $n = strspn($a ^ $b, "\0");
        while ($n > 0 && (ord($a[$n] ?? "\0") & 0xC0) === 0x80) {
            $n--; // not within a character
        }
        return substr($a, 0, $n);
    }

    /** A number written in the decimal digits of any script, as "12" or "۱۲", or null. */
    public static function decimal(string $s): ?int
    {
        if ($s === '' || !mb_check_encoding($s, 'UTF-8')) {
            return null;
        }
        $n = 0;
        foreach (mb_str_split($s) as $ch) {
            $d = IntlChar::charType($ch) === IntlChar::CHAR_CATEGORY_DECIMAL_DIGIT_NUMBER ? IntlChar::charDigitValue($ch) : -1;
            if ($d < 0 || $n > intdiv(1 << 31, 10)) {
                return null;
            }
            $n = $n * 10 + $d;
        }
        return $n;
    }
}
