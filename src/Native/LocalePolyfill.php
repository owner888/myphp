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
            public function __construct(string $locale = '') {}
            public function getBestPattern(string $skeleton): string
            {
                return 'yyyy-MM-dd HH:mm:ss';
            }
        }
    }

    if (!class_exists('IntlCalendar', false)) {
        class IntlCalendar
        {
            public static function createInstance($tz = null, string $locale = ''): self
            {
                return new self();
            }
            public function getFirstDayOfWeek(): int
            {
                return 1;
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
            public function __construct(string $locale, int $dateType, int $timeType, $timezone = null, $calendar = null, string $pattern = '') {}
            public function format($datetime): string
            {
                return (string)$datetime;
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
            public static function getIntPropertyValue(string $char, int $property): int
            {
                return 0;
            }
            public static function getPropertyValueName(int $property, int $value, int $nameChoice): string
            {
                return 'Latn';
            }
        }
    }
}
