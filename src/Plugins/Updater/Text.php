<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Updater;

/**
 * The language the window shows, resolved once (mygo's text): its Strings,
 * the language tag, and whether it writes numbers with a decimal comma and
 * from right to left.
 *
 * @internal
 */
final class Text
{
    /** The languages written from right to left, for which the window is mirrored: the plugin has none, but apps may add them. */
    private const RIGHT_TO_LEFT = ['ar', 'fa', 'he', 'ps', 'ur', 'yi'];

    private function __construct(public readonly Strings $s, public readonly string $lang, private readonly bool $comma, public readonly bool $rtl) {}

    /**
     * The strings of the language the plugin has, or the app gives, that
     * best matches $locale, completed with the plugin's own and English.
     *
     * @param array<string, Strings> $extra
     */
    public static function for(string $locale, array $extra): self
    {
        $available = [...array_map('strval', array_keys(Translations::LANGUAGES)), ...array_map('strval', array_keys($extra))];
        sort($available, SORT_STRING);
        $lang = self::match($locale, $available);
        $s = new Strings;
        foreach ($extra as $l => $x) {
            if (strcasecmp((string)$l, $lang) === 0) {
                $s = $x;
            }
        }
        $s = $s->merged(Translations::LANGUAGES[$lang] ?? [])->merged(Translations::ENGLISH);
        return new self($s, $lang, in_array($lang, Translations::DECIMAL_COMMA, true), in_array(self::base($lang), self::RIGHT_TO_LEFT, true));
    }

    /**
     * The language among $available, tags such as "de" or "zh-Hant", that
     * best matches $locale, a language tag or a POSIX locale such as
     * "zh_TW.UTF-8": the same language, script and region, else the same
     * language and script, else the same language; "en" without a match.
     *
     * @param list<string> $available
     */
    public static function match(string $locale, array $available): string
    {
        $locale = explode('.', $locale, 2)[0];
        $locale = explode('@', $locale, 2)[0];
        $parts = preg_split('/[-_]/', $locale, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return 'en';
        }
        $lang = strtolower($parts[0]);
        $script = $region = '';
        foreach (array_slice($parts, 1) as $p) {
            if (strlen($p) === 4) {
                $script = strtoupper($p[0]) . strtolower(substr($p, 1));
            } elseif (strlen($p) === 2 || strlen($p) === 3 && ctype_digit($p[0])) {
                $region = strtoupper($p);
            }
        }
        if ($lang === 'zh' && $script === '') {
            $script = in_array($region, ['TW', 'HK', 'MO'], true) ? 'Hant' : 'Hans';
        }
        $candidates = $script !== '' ? ["$lang-$script-$region", "$lang-$script"] : [];
        array_push($candidates, "$lang-$region", $lang);
        foreach ($candidates as $c) {
            foreach ($available as $a) {
                if (strcasecmp($a, $c) === 0) {
                    return $a;
                }
            }
        }
        // Another variant of the language: pt-PT gets pt-BR. Chinese scripts are not variants of each other.
        if ($lang !== 'zh') {
            foreach ($available as $a) {
                if (self::base($a) === $lang) {
                    return $a;
                }
            }
        }
        return 'en';
    }

    private static function base(string $tag): string
    {
        return strtolower(explode('-', $tag, 2)[0]);
    }

    /** A size in decimal megabytes, as Finder shows it. */
    public function megabytes(int $n): string
    {
        $s = number_format($n / 1e6, 1, '.', '');
        if ($this->comma) {
            $s = str_replace('.', ',', $s);
        }
        return sprintf($this->s->megabytes, $s);
    }
}
