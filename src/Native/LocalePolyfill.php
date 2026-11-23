<?php
declare(strict_types=1);

namespace {
    if (!class_exists('Locale', false)) {
        class Locale
        {
            public static function parseLocale(string $locale): ?array
            {
                if ($locale === '') {
                    return null;
                }
                $parts = preg_split('/[-_]/', $locale);
                $out = ['language' => strtolower($parts[0] ?? '')];
                for ($i = 1; $i < count($parts); $i++) {
                    $p = $parts[$i];
                    if (strlen($p) === 4 && !isset($out['script'])) {
                        $out['script'] = ucfirst(strtolower($p));
                    } elseif ((strlen($p) === 2 || (strlen($p) === 3 && ctype_digit($p))) && !isset($out['region'])) {
                        $out['region'] = strtoupper($p);
                    }
                }
                return $out;
            }

            public static function canonicalize(string $locale): ?string
            {
                $p = self::parseLocale($locale);
                if ($p === null) {
                    return null;
                }
                $res = $p['language'];
                if (isset($p['script'])) {
                    $res .= '_' . $p['script'];
                }
                if (isset($p['region'])) {
                    $res .= '_' . $p['region'];
                }
                return $res;
            }

            public static function composeLocale(array $subtags): string|false
            {
                if (!isset($subtags['language']) || $subtags['language'] === '') {
                    return false;
                }
                $parts = [strtolower((string)$subtags['language'])];
                if (isset($subtags['script']) && $subtags['script'] !== '') {
                    $parts[] = ucfirst(strtolower((string)$subtags['script']));
                }
                if (isset($subtags['region']) && $subtags['region'] !== '') {
                    $parts[] = strtoupper((string)$subtags['region']);
                }
                return implode('_', $parts);
            }

            public static function getKeywords(string $locale): array
            {
                $out = [];
                if (preg_match('/-u-(.*)$/i', str_replace('_', '-', $locale), $m)) {
                    $parts = explode('-', $m[1]);
                    for ($i = 0; $i + 1 < count($parts); $i += 2) {
                        $k = strtolower($parts[$i]);
                        $v = strtolower($parts[$i + 1]);
                        if ($k === 'hc') {
                            $out['hours'] = $v;
                        } else {
                            $out[$k] = $v;
                        }
                    }
                }
                return $out;
            }

            public static function getPrimaryLanguage(string $locale): ?string
            {
                return self::parseLocale($locale)['language'] ?? null;
            }

            public static function getScript(string $locale): ?string
            {
                return self::parseLocale($locale)['script'] ?? null;
            }

            public static function getRegion(string $locale): ?string
            {
                return self::parseLocale($locale)['region'] ?? null;
            }

            public static function getDisplayLanguage(string $locale, ?string $in = null): ?string
            {
                return self::getPrimaryLanguage($locale);
            }

            public static function getDisplayName(string $locale, ?string $in = null): ?string
            {
                return $locale;
            }
        }
    }

    if (!class_exists('IntlDatePatternGenerator', false)) {
        class IntlDatePatternGenerator
        {
            private string $lang;

            public function __construct(private string $locale = '')
            {
                $this->lang = strtolower((string)preg_split('/[-_]/', $locale)[0]);
            }

            public function getBestPattern(string $skeleton): string
            {
                $cjk = in_array($this->lang, ['zh', 'ja'], true);
                $ko = $this->lang === 'ko';
                return match ($skeleton) {
                    'yMMMM' => $cjk ? 'y年M月' : ($ko ? 'y년 M월' : 'MMMM y'),
                    'yMMMMd' => $cjk ? 'y年M月d日' : ($ko ? 'y년 M월 d일' : 'MMMM d, y'),
                    'yMd' => $cjk ? 'y/M/d' : ($ko ? 'y. M. d.' : 'M/d/y'),
                    'j' => in_array($this->lang, ['en'], true) && !str_contains($this->locale, 'GB') ? 'h a' : 'HH',
                    'jmm', 'hmm', 'Hmm' => str_contains($skeleton, 'h') ? ($cjk ? 'a h:mm' : 'h:mm a') : 'HH:mm',
                    default => $cjk ? 'y/M/d' : 'yyyy-MM-dd',
                };
            }
        }
    }

    if (!class_exists('IntlCalendar', false)) {
        class IntlCalendar
        {
            public function __construct(private string $locale = '') {}

            public static function createInstance($tz = null, string $locale = ''): self
            {
                return new self($locale);
            }

            public function getFirstDayOfWeek(): int
            {
                $p = Locale::parseLocale($this->locale) ?? [];
                $region = $p['region'] ?? '';
                $lang = $p['language'] ?? '';
                if (in_array($region, ['US', 'CA', 'JP', 'TW', 'HK', 'MO', 'KR', 'BR', 'MX', 'IL', 'PH', 'SG'], true)) {
                    return 1; // Sunday (1 in ICU)
                }
                if ($region === '' && in_array($lang, ['en', 'ja', 'ko'], true)) {
                    return 1;
                }
                return 2; // Monday (2 in ICU)
            }
        }
    }

    if (!class_exists('IntlDateFormatter', false)) {
        class IntlDateFormatter
        {
            public const NONE = -1;
            public const SHORT = 3;
            public const MEDIUM = 2;
            public const LONG = 1;
            public const FULL = 0;
            public const GREGORIAN = 1;

            private const MONTHS_EN = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
            private const MONTHS_SHORT_EN = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            private const WEEKDAYS_EN = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            private const WEEKDAYS_SHORT_EN = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            private const WEEKDAYS_MIN_EN = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
            private const WEEKDAYS_ZH = ['星期日', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六'];
            private const WEEKDAYS_SHORT_ZH = ['周日', '周一', '周二', '周三', '周四', '周五', '周六'];
            private const WEEKDAYS_MIN_ZH = ['日', '一', '二', '三', '四', '五', '六'];
            private const WEEKDAYS_JA = ['日曜日', '月曜日', '火曜日', '水曜日', '木曜日', '金曜日', '土曜日'];
            private const WEEKDAYS_MIN_JA = ['日', '月', '火', '水', '木', '金', '土'];

            private string $lang;
            private string $pattern;
            private ?\DateTimeZone $tz = null;

            public function __construct(private string $locale, private int $dateType, private int $timeType, $timezone = null, $calendar = null, string $pattern = '')
            {
                $this->lang = strtolower((string)preg_split('/[-_]/', $locale)[0]);
                if ($timezone instanceof \DateTimeZone) {
                    $this->tz = $timezone;
                } elseif (is_string($timezone) && $timezone !== '') {
                    try { $this->tz = new \DateTimeZone($timezone); } catch (\Throwable) {}
                }
                if ($pattern !== '') {
                    $this->pattern = $pattern;
                } else {
                    $cjk = in_array($this->lang, ['zh', 'ja'], true);
                    $dp = match ($dateType) {
                        self::NONE => '',
                        self::FULL, self::LONG => $cjk ? 'y年M月d日' : 'MMMM d, y',
                        default => $cjk ? 'y/M/d' : 'M/d/y',
                    };
                    $tp = match ($timeType) {
                        self::NONE => '',
                        default => 'HH:mm',
                    };
                    $this->pattern = trim("$dp $tp");
                }
            }

            public function setTimeZone($tz): void
            {
                if ($tz instanceof \DateTimeZone) {
                    $this->tz = $tz;
                } elseif (is_string($tz) && $tz !== '') {
                    try { $this->tz = new \DateTimeZone($tz); } catch (\Throwable) {}
                }
            }

            public function getPattern(): string
            {
                return $this->pattern;
            }

            public function format($datetime): string
            {
                if (is_int($datetime) || is_float($datetime)) {
                    $dt = (new \DateTimeImmutable('@' . (int)$datetime));
                } elseif ($datetime instanceof \DateTimeInterface) {
                    $dt = \DateTimeImmutable::createFromInterface($datetime);
                } else {
                    return (string)$datetime;
                }
                if ($this->tz !== null) {
                    $dt = $dt->setTimezone($this->tz);
                }
                $m = (int)$dt->format('n');
                $w = (int)$dt->format('w');
                $h = (int)$dt->format('G');
                $h12 = ($h + 11) % 12 + 1;
                $pm = $h >= 12;
                $p = $this->pattern;
                return match ($this->lang) {
                    'zh' => match ($p) {
                        'MMMM', 'LLLL', 'MMM', 'LLL' => "{$m}月",
                        'MMMMM', 'LLLLL' => (string)$m,
                        'EEEE', 'cccc' => self::WEEKDAYS_ZH[$w],
                        'EEE', 'ccc' => self::WEEKDAYS_SHORT_ZH[$w],
                        'EEEEEE', 'cccccc', 'EEEEE', 'ccccc' => self::WEEKDAYS_MIN_ZH[$w],
                        'aaaa', 'a', 'aaaaa' => $pm ? '下午' : '上午',
                        default => self::renderPattern($p, $dt, "{$m}月", "{$m}月", self::WEEKDAYS_ZH[$w], self::WEEKDAYS_MIN_ZH[$w], $pm ? '下午' : '上午'),
                    },
                    'ja' => match ($p) {
                        'MMMM', 'LLLL', 'MMM', 'LLL' => "{$m}月",
                        'MMMMM', 'LLLLL' => (string)$m,
                        'EEEE', 'cccc' => self::WEEKDAYS_JA[$w],
                        'EEE', 'ccc', 'EEEEEE', 'cccccc', 'EEEEE', 'ccccc' => self::WEEKDAYS_MIN_JA[$w],
                        'aaaa', 'a', 'aaaaa' => $pm ? '午後' : '午前',
                        default => self::renderPattern($p, $dt, "{$m}月", "{$m}月", self::WEEKDAYS_JA[$w], self::WEEKDAYS_MIN_JA[$w], $pm ? '午後' : '午前'),
                    },
                    default => match ($p) {
                        'MMMM', 'LLLL' => self::MONTHS_EN[$m],
                        'MMM', 'LLL' => self::MONTHS_SHORT_EN[$m],
                        'MMMMM', 'LLLLL' => substr(self::MONTHS_EN[$m], 0, 1),
                        'EEEE', 'cccc' => self::WEEKDAYS_EN[$w],
                        'EEE', 'ccc' => self::WEEKDAYS_SHORT_EN[$w],
                        'EEEEEE', 'cccccc' => self::WEEKDAYS_MIN_EN[$w],
                        'EEEEE', 'ccccc' => substr(self::WEEKDAYS_EN[$w], 0, 1),
                        'aaaa', 'a' => $pm ? 'PM' : 'AM',
                        'aaaaa' => $pm ? 'p' : 'a',
                        default => self::renderPattern($p, $dt, self::MONTHS_EN[$m], self::MONTHS_SHORT_EN[$m], self::WEEKDAYS_EN[$w], self::WEEKDAYS_SHORT_EN[$w], $pm ? 'PM' : 'AM'),
                    },
                };
            }

            private static function renderPattern(string $pattern, \DateTimeImmutable $dt, string $monthFull, string $monthShort, string $dayFull, string $dayShort, string $ampm): string
            {
                $y = $dt->format('Y');
                $m = (int)$dt->format('n');
                $mm = $dt->format('m');
                $d = (int)$dt->format('j');
                $dd = $dt->format('d');
                $H = (int)$dt->format('G');
                $HH = $dt->format('H');
                $h = ($H + 11) % 12 + 1;
                $hh = sprintf('%02d', $h);
                $min = $dt->format('i');
                $sec = $dt->format('s');
                return preg_replace_callback("/'[^']*'|([A-Za-z])\\1*/", static function (array $match) use ($y, $m, $mm, $d, $dd, $H, $HH, $h, $hh, $min, $sec, $monthFull, $monthShort, $dayFull, $dayShort, $ampm): string {
                    $tok = $match[0];
                    if ($tok[0] === "'") {
                        return $tok === "''" ? "'" : substr($tok, 1, -1);
                    }
                    return match ($tok) {
                        'y', 'yyyy', 'Y' => $y,
                        'yy' => substr($y, -2),
                        'MMMM', 'LLLL' => $monthFull,
                        'MMM', 'LLL' => $monthShort,
                        'MM', 'LL' => $mm,
                        'M', 'L' => (string)$m,
                        'dd' => $dd,
                        'd' => (string)$d,
                        'EEEE', 'cccc' => $dayFull,
                        'E', 'EE', 'EEE', 'c', 'cc', 'ccc', 'EEEEEE', 'cccccc' => $dayShort,
                        'HH' => $HH,
                        'H' => (string)$H,
                        'hh' => $hh,
                        'h' => (string)$h,
                        'mm' => $min,
                        'm' => (string)(int)$min,
                        'ss' => $sec,
                        's' => (string)(int)$sec,
                        'a', 'aa', 'aaa', 'aaaa' => $ampm,
                        default => $tok,
                    };
                }, $pattern) ?? $dt->format('Y-m-d');
            }
        }
    }

    // What MyPHP\NumberFormatter (and through it a message's plural #) asks of intl, which the phones' PHP lacks: the
    // Latin digits, the separators of the common languages, and CLDR's plain patterns.
    if (!class_exists('NumberFormatter', false)) {
        class NumberFormatter
        {
            public const DECIMAL = 1;
            public const PERCENT = 3;
            public const DECIMAL_SEPARATOR_SYMBOL = 0;
            public const GROUPING_SEPARATOR_SYMBOL = 1;
            public const PERCENT_SYMBOL = 3;
            public const MINUS_SIGN_SYMBOL = 6;
            public const PLUS_SIGN_SYMBOL = 7;
            public const INFINITY_SYMBOL = 14;
            public const NAN_SYMBOL = 15;

            /** The decimal and grouping separators of the languages that differ from English's. @var array<string, array{0: string, 1: string}> */
            private const SEPARATORS = [
                'de' => [',', '.'], 'es' => [',', '.'], 'it' => [',', '.'], 'pt' => [',', '.'], 'nl' => [',', '.'], 'id' => [',', '.'], 'tr' => [',', '.'], 'da' => [',', '.'],
                'fr' => [',', "\u{202F}"], 'ru' => [',', "\u{00A0}"], 'pl' => [',', "\u{00A0}"], 'uk' => [',', "\u{00A0}"], 'cs' => [',', "\u{00A0}"], 'sv' => [',', "\u{00A0}"], 'fi' => [',', "\u{00A0}"], 'nb' => [',', "\u{00A0}"],
            ];

            private string $language;

            public function __construct(string $locale, private int $style = self::DECIMAL)
            {
                $this->language = strtolower((string)preg_split('/[-_]/', $locale)[0]);
            }

            public function format(int|float $n): string
            {
                return (string)$n;
            }

            public function getSymbol(int $symbol): string
            {
                [$decimal, $group] = self::SEPARATORS[$this->language] ?? ['.', ','];
                return match ($symbol) {
                    self::DECIMAL_SEPARATOR_SYMBOL => $decimal, self::GROUPING_SEPARATOR_SYMBOL => $group, self::PERCENT_SYMBOL => '%',
                    self::MINUS_SIGN_SYMBOL => '-', self::PLUS_SIGN_SYMBOL => '+', self::INFINITY_SYMBOL => '∞', self::NAN_SYMBOL => 'NaN',
                    default => '',
                };
            }

            public function getPattern(): string
            {
                return $this->style === self::PERCENT ? '#,##0%' : '#,##0.###';
            }
        }
    }

    // ICU's data, which the phones' PHP has none of: no bundle, so that what reads one takes its defaults.
    if (!class_exists('ResourceBundle', false)) {
        class ResourceBundle
        {
            public static function create(?string $locale, ?string $bundle, bool $fallback = true): ?self
            {
                return null;
            }

            /** @return list<string> */
            public static function getLocales(string $bundle): array
            {
                return [];
            }

            public function get(string|int $index, bool $fallback = true): mixed
            {
                return null;
            }
        }
    }

    if (!class_exists('IntlChar', false)) {
        class IntlChar
        {
            public const PROPERTY_SCRIPT = 0;
            public const SHORT_PROPERTY_NAME = 1;
            public const CHAR_CATEGORY_UNASSIGNED = 0;
            public const CHAR_CATEGORY_UPPERCASE_LETTER = 1;
            public const CHAR_CATEGORY_LOWERCASE_LETTER = 2;
            public const CHAR_CATEGORY_DECIMAL_DIGIT_NUMBER = 9;
            public const CHAR_CATEGORY_SPACE_SEPARATOR = 12;
            public const CHAR_CATEGORY_LINE_SEPARATOR = 13;
            public const CHAR_CATEGORY_PARAGRAPH_SEPARATOR = 14;
            public const CHAR_CATEGORY_CONTROL_CHAR = 15;
            public const CHAR_CATEGORY_FORMAT_CHAR = 16;
            public const CHAR_CATEGORY_PRIVATE_USE_CHAR = 17;
            public const CHAR_CATEGORY_SURROGATE = 18;
            public const CHAR_CATEGORY_OTHER_PUNCTUATION = 23;

            private static function cp(int|string $codepoint): ?int
            {
                if (is_int($codepoint)) {
                    return $codepoint >= 0 && $codepoint <= 0x10FFFF ? $codepoint : null;
                }
                return $codepoint !== '' ? mb_ord($codepoint, 'UTF-8') : null;
            }

            public static function isUWhiteSpace(int|string $codepoint): ?bool
            {
                $cp = self::cp($codepoint);
                if ($cp === null) {
                    return null;
                }
                return ($cp >= 0x09 && $cp <= 0x0D)
                    || $cp === 0x20 || $cp === 0x85 || $cp === 0xA0 || $cp === 0x1680
                    || ($cp >= 0x2000 && $cp <= 0x200A)
                    || $cp === 0x2028 || $cp === 0x2029 || $cp === 0x202F || $cp === 0x205F || $cp === 0x3000;
            }

            public static function iswhitespace(int|string $codepoint): ?bool
            {
                return self::isUWhiteSpace($codepoint);
            }

            public static function isupper(int|string $codepoint): ?bool
            {
                $cp = self::cp($codepoint);
                if ($cp === null) {
                    return null;
                }
                $ch = mb_chr($cp, 'UTF-8');
                return $ch !== false && preg_match('/^\p{Lu}$/u', $ch) === 1;
            }

            public static function charType(int|string $codepoint): ?int
            {
                $cp = self::cp($codepoint);
                if ($cp === null) {
                    return null;
                }
                if ($cp < 0x20 || ($cp >= 0x7F && $cp <= 0x9F)) { return self::CHAR_CATEGORY_CONTROL_CHAR; }
                if ($cp >= 0xD800 && $cp <= 0xDFFF) { return self::CHAR_CATEGORY_SURROGATE; }
                if (($cp >= 0xE000 && $cp <= 0xF8FF) || $cp >= 0xF0000) { return self::CHAR_CATEGORY_PRIVATE_USE_CHAR; }
                if ($cp === 0x2028) { return self::CHAR_CATEGORY_LINE_SEPARATOR; }
                if ($cp === 0x2029) { return self::CHAR_CATEGORY_PARAGRAPH_SEPARATOR; }
                if (self::isUWhiteSpace($cp)) { return self::CHAR_CATEGORY_SPACE_SEPARATOR; }
                $ch = mb_chr($cp, 'UTF-8');
                if ($ch === false) { return self::CHAR_CATEGORY_UNASSIGNED; }
                if (preg_match('/^\p{Nd}$/u', $ch) === 1) { return self::CHAR_CATEGORY_DECIMAL_DIGIT_NUMBER; }
                if (preg_match('/^\p{Lu}$/u', $ch) === 1) { return self::CHAR_CATEGORY_UPPERCASE_LETTER; }
                if (preg_match('/^\p{Cf}$/u', $ch) === 1) { return self::CHAR_CATEGORY_FORMAT_CHAR; }
                return self::CHAR_CATEGORY_LOWERCASE_LETTER;
            }

            public static function charDigitValue(int|string $codepoint): ?int
            {
                $cp = self::cp($codepoint);
                if ($cp === null) { return null; }
                if ($cp >= 0x30 && $cp <= 0x39) { return $cp - 0x30; }
                if ($cp >= 0xFF10 && $cp <= 0xFF19) { return $cp - 0xFF10; }
                return -1;
            }

            public static function getIntPropertyValue(int|string $char, int $property): int
            {
                return 0;
            }

            public static function getPropertyValueName(int $property, int $value, int $nameChoice = self::SHORT_PROPERTY_NAME): string
            {
                return 'Latn';
            }
        }
    }
}