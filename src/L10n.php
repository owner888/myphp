<?php
declare(strict_types=1);

namespace MyPHP;

use MessageFormatter;
use RuntimeException;

/**
 * MyPHP's own strings, as mygo's internal/l10n: those of the controls of
 * UI\Context and of the roles of menus, in the languages they are
 * translated to (src/l10n/<tag>.json, mygo's catalogs: English the
 * source, with the descriptions translators read). `L10n::in($lang)`
 * says them in a language, `get($key, $args)` formatting ICU messages
 * ({token}, plural, select, number). An app changes them, or translates
 * them to its other languages, with L10n::add($lang, $messages).
 */
final class L10n
{
    /** @var array<string, array<string, string>> by language, then key */
    private static array $catalogs = [];
    /** @var array<string, array<string, string>> what apps added, over the catalogs */
    private static array $added = [];
    /** @var array<string, L10n> */
    private static array $in = [];

    private function __construct(private readonly string $lang) {}

    /** The languages MyPHP's own strings are in, English first. @return list<string> */
    public static function languages(): array
    {
        $langs = ['en'];
        foreach (glob(__DIR__ . '/l10n/*.json') ?: [] as $f) { $l = basename($f, '.json'); if ($l !== 'en') { $langs[] = $l; } }
        foreach (array_keys(self::$added) as $l) { if (!in_array($l, $langs, true)) { $langs[] = $l; } }
        return $langs;
    }

    /** The strings in the language of $lang that they are translated to (the best match), else English. */
    public static function in(string $lang): self
    {
        return self::$in[$lang] ??= (function () use ($lang) {
            $m = I18n::match([$lang], self::languages());
            return new self($m === '' ? 'en' : $m);
        })();
    }

    /** Adds, or changes, strings in $lang: key => ICU message. @param array<string, string> $messages */
    public static function add(string $lang, array $messages): void
    {
        $tag = I18n::normalize($lang);
        if ($tag === '') { throw new RuntimeException("L10n: $lang is not a language tag"); }
        foreach ($messages as $k => $m) { self::$added[$tag][$k] = $m; }
        self::$in = [];
    }

    public function lang(): string { return $this->lang; }

    /** The string $key says in this language, with $args formatted into it; English where the language lacks it. */
    public function get(string $key, array $args = []): string
    {
        $msg = self::$added[$this->lang][$key] ?? self::catalog($this->lang)[$key] ?? self::$added['en'][$key] ?? self::catalog('en')[$key] ?? throw new RuntimeException("L10n: no string $key");
        if ($args === [] && !str_contains($msg, '{')) { return $msg; }
        $s = MessageFormatter::formatMessage($this->lang, $msg, $args);
        if ($s === false) { throw new RuntimeException("L10n: $key: " . intl_get_error_message()); }
        return $s;
    }

    /** @return array<string, string> */
    private static function catalog(string $lang): array
    {
        if (!isset(self::$catalogs[$lang])) {
            $path = __DIR__ . "/l10n/$lang.json";
            $all = is_file($path) ? json_decode(file_get_contents($path) ?: '{}', true, flags: JSON_THROW_ON_ERROR) : [];
            self::$catalogs[$lang] = array_filter($all, fn($v, $k) => $k[0] !== '@' && is_string($v), ARRAY_FILTER_USE_BOTH);
        }
        return self::$catalogs[$lang];
    }
}
