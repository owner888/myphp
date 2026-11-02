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
