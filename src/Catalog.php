<?php
declare(strict_types=1);

namespace MyPHP;

use InvalidArgumentException;

/**
 * The messages of an app, or of a library, in the languages it is
 * translated to (mygo's i18n.Catalog): a file of ARB-style JSON per
 * language, named after its BCP 47 tag, "en.json", "zh-Hant.json", whose
 * keys name messages of ICU MessageFormat (Message):
 *
 *     {
 *       "@@locale": "en",
 *       "filesSelected": "{count, plural, one {# file selected} other {# files selected}}",
 *       "@filesSelected": {
 *         "description": "The status bar, while files are selected.",
 *         "placeholders": {"count": {"type": "int"}}
 *       }
 *     }
 *
 * A key starting with @ holds metadata, which only the file of the source
 * language needs. `myphp generate` writes a class of typed methods for the
 * keys of an app's catalog, which registers it (I18n::register), so that
 * an app rarely uses a Catalog itself. A language's file is read when a
 * message is first formatted in it, and kept.
 */
final class Catalog
{
    /** @var list<Catalog> the catalogs apps registered, the first first */
    private static array $registered = [];
    /** @var list<string> the languages of the registered catalogs, as I18n::languages gives them */
    private static array $languages = [];
    /** Counts registrations and additions, for the caches. */
    private static int $version = 0;

    private string $namespace = '';
    /** @var list<string> source first, then the others sorted */
    private array $langs;
    /** @var array<string, string> the file of each language */
    private array $files;
    /** @var array<string, array<string, Message>> the messages of the languages read */
    private array $messages = [];
    /** @var array<string, array{int, list<string>}> the tags to look a key up in, by locale, as of a version */
    private array $candidates = [];
    /** @var array<string, string> messages formatted without arguments, by locale and key, as of $textsVersion: the controls of native UI say theirs every frame */
    private array $texts = [];
    private int $textsVersion = -1;
    private readonly string $source;

    /**
     * The catalog of the JSON files in $dir, "<tag>.json", whose source
     * language, the one its messages are written in first and the others
     * fall back to, is $source (mygo's NewCatalog). The files must be
     * named after BCP 47 tags in their canonical form; they are read as
     * they are needed. A message that does not parse, or a file that does
     * not, is left out, and its keys fall back as missing ones do:
     * `myphp generate` and `myphp i18n check` report them.
     */
    public function __construct(string $dir, string $source)
    {
        $src = I18n::normalize($source);
        if ($src === '' || I18n::isPseudo($src)) {
            throw new InvalidArgumentException("i18n: the source language \"$source\" is not a language");
        }
        $files = self::files($dir);
        if (!isset($files[$src])) {
            throw new InvalidArgumentException("i18n: the catalog has no $src.json, the messages of its source language");
        }
        $this->source = $src;
        $this->files = $files;
        $this->langs = self::sorted($src, array_keys($files));
    }

    /**
     * The catalog of a library, as the constructor's (mygo's
     * NewLibraryCatalog), whose messages an app changes, or translates to
     * other languages, with keys of its own catalogs named
     * "<namespace>.<key>": MyPHP's own strings (L10n) are a library's
     * catalog of the namespace "myphp", and an app's de.json that says
     * "myphp.cancel": "Abbrechen" translates the controls' "cancel" to
     * German. Its messages are said in the language the app shows, and
     * fall back along its parents, then to the source language of the app,
     * then to the library's. A library's catalog is not registered.
     */
    public static function library(string $dir, string $source, string $namespace): self
    {
        if ($namespace === '' || strpbrk($namespace, '.@ ') !== false) {
            throw new InvalidArgumentException("i18n: \"$namespace\" is not a namespace");
        }
        $c = new self($dir, $source);
        $c->namespace = $namespace;
        return $c;
    }

    /** @return array<string, string> the languages of the JSON files in $dir, by tag */
    private static function files(string $dir): array
    {
        if (!is_dir($dir)) {
            throw new InvalidArgumentException("i18n: $dir is not a directory");
        }
        $files = [];
        foreach (glob(rtrim($dir, '/') . '/*.json') ?: [] as $path) {
            $name = basename($path);
            $tag = substr($name, 0, -5);
            $norm = I18n::normalize($tag);
            if ($norm === '' || $norm !== $tag) {
                throw new InvalidArgumentException("i18n: $name is not named after a BCP 47 tag, as en.json or zh-Hant.json");
            }
            if (I18n::isPseudo($norm)) {
                throw new InvalidArgumentException("i18n: $name: the pseudo locales come from the source language and have no files");
            }
            $files[$norm] = $path;
        }
        return $files;
    }

    /** @param list<string> $tags @return list<string> */
    private static function sorted(string $source, array $tags): array
    {
        $others = array_values(array_filter($tags, fn(string $t) => $t !== $source));
        sort($others, SORT_STRING);
        return [$source, ...$others];
    }

    /**
     * Adds the languages of the JSON files in $dir, named as the
     * constructor's, to the catalog (mygo's Add): translations kept apart
     * from it. Throws for a language the catalog has already, and for
     * files not named after a language.
     */
    public function add(string $dir): void
    {
        $files = self::files($dir);
        foreach ($files as $tag => $_) {
            if (isset($this->files[$tag])) {
                throw new InvalidArgumentException("i18n: the catalog has $tag already");
            }
        }
        $this->files += $files;
        $this->langs = self::sorted($this->source, array_keys($this->files));
        self::$version++; // the languages to look messages up in changed
        if (in_array($this, self::$registered, true)) {
            $added = array_keys($files);
            sort($added, SORT_STRING);
            self::declare($this->source, $added);
            Locales::catalogsChanged();
        }
    }

    /** The source language of the catalog. */
    public function source(): string
    {
        return $this->source;
    }

    /** The languages of the catalog: its source language, then the others, sorted. @return list<string> */
    public function languages(): array
    {
        return $this->langs;
    }

    /** The namespace under which apps change the messages of a library's catalog, or '' for an app's. */
    public function namespace(): string
    {
        return $this->namespace;
    }

    /**
     * Formats the message of $key in $locale with $args (Message::format),
     * or returns $key when the catalog has no such message. The message is
     * the one of the catalog's language that best suits the locale
     * (I18n::match), as "zh-Hant" for "zh-TW", or, when that language
     * lacks it, of the language's parents (I18n::parent), as "en-001" then
     * "en" for "en-AU", else of the source language. Its cases follow the
     * plural rules of the language it is in; numbers and dates, the
     * conventions of the locale. '' is the source language; the pseudo
     * locales transform the source language's messages. A message
     * formatted without arguments is kept.
     */
    public function format(string $locale, string $key, array $args = []): string
    {
        if ($args === []) {
            if ($this->textsVersion !== self::$version || count($this->texts) >= 4096) {
                [$this->texts, $this->textsVersion] = [[], self::$version];
            }
            return $this->texts["$locale\0$key"] ??= $this->formatNow($locale, $key, []);
        }
        return $this->formatNow($locale, $key, $args);
    }

    private function formatNow(string $locale, string $key, array $args): string
    {
        $found = $this->message($locale, $key);
        if ($found === null) {
            return $key;
        }
        [$m, $lang] = $found;
        return $m->formatIn($locale === '' ? $lang : $locale, $lang, $args);
    }

    /** @return ?array{Message, string} the message of $key that format() formats in $locale, and the language it is in */
    public function message(string $locale, string $key): ?array
    {
        $sources = $this->sources();
        foreach ($this->candidates($locale, $sources) as $tag) {
            foreach ($sources as [$c, $prefix]) {
                $m = $c->messageIn($tag, $prefix . $key);
                if ($m !== null) {
                    return [$m, $tag];
                }
            }
        }
        return null;
    }

    /** @return list<array{Catalog, string}> the catalogs a key of this one is looked up in, with the prefix of their keys: this one, after the registered catalogs for a library's */
    private function sources(): array
    {
        if ($this->namespace === '') {
            return [[$this, '']];
        }
        $out = [];
        foreach (self::$registered as $r) {
            $out[] = [$r, "$this->namespace."];
        }
        $out[] = [$this, ''];
        return $out;
    }

    /** @return list<string> the tags to look a key up in for $locale, in order */
    private function candidates(string $locale, array $sources): array
    {
        if (($this->candidates[$locale][0] ?? -1) === self::$version) {
            return $this->candidates[$locale][1];
        }
        $tags = [];
        $add = function (array $list) use (&$tags): void {
            foreach ($list as $t) {
                if (!in_array($t, $tags, true)) { $tags[] = $t; }
            }
        };
        $tag = I18n::stripExtensions(I18n::normalize($locale));
        if ($tag !== '' && !I18n::isPseudo($tag)) {
            $add(I18n::chain($tag));
            // The language each catalog has that suits the locale best, as zh-Hant for zh-TW, whose chain (zh-TW, zh) has none of it.
            foreach ($sources as [$c]) {
                if (in_array($tag, $c->langs, true)) { continue; }
                $m = I18n::match([$tag], $c->langs);
                if ($m !== '') { $add(I18n::chain($m)); }
            }
        }
        // The source language of the app, then the library's.
        if (count($sources) > 1) {
            $add(I18n::chain($sources[0][0]->source));
        }
        $add(I18n::chain($this->source));
        if (count($this->candidates) > 64) {
            $this->candidates = []; // locales are few; keep the cache from growing
        }
        $this->candidates[$locale] = [self::$version, $tags];
        return $tags;
    }

    /** The message of $key in the language $tag, read once, or null. */
    private function messageIn(string $tag, string $key): ?Message
    {
        if (!isset($this->files[$tag])) {
            return null;
        }
        return ($this->messages[$tag] ??= self::load($this->files[$tag]))[$key] ?? null;
    }

    /** @return array<string, Message> the messages of a language's file; one that does not parse is left out, and its key falls back */
    private static function load(string $path): array
    {
        $raw = json_decode((string)@file_get_contents($path), true);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $key => $v) {
            if (str_starts_with((string)$key, '@') || !is_string($v)) { continue; }
            try {
                $out[(string)$key] = Message::parse($v);
            } catch (SyntaxError) {
            }
        }
        return $out;
    }

    /**
     * @internal I18n::register: adds an app's catalog to those the app
     * ships; its languages become the app's (I18n::languages), and its keys
     * "<namespace>.<key>" change the messages of libraries.
     */
    public static function register(self $c): void
    {
        if ($c->namespace !== '') {
            throw new InvalidArgumentException("i18n: register of the catalog of a library, namespace $c->namespace");
        }
        if (in_array($c, self::$registered, true)) {
            return;
        }
        self::$registered[] = $c;
        self::$version++;
        self::declare($c->source, array_slice($c->langs, 1));
        Locales::catalogsChanged();
    }

    /** @internal the languages of the registered catalogs, the source language of the first first @return list<string> */
    public static function registeredLanguages(): array
    {
        return self::$languages;
    }

    /** @internal forgets the registered catalogs (the tests) */
    public static function resetRegistry(): void
    {
        [self::$registered, self::$languages] = [[], []];
        self::$version++;
        Locales::catalogsChanged();
    }

    /** Adds the languages of a catalog to those the app ships, its source language first when it is the first catalog. @param list<string> $others */
    private static function declare(string $source, array $others): void
    {
        foreach ([$source, ...$others] as $tag) {
            if ($tag !== '' && !in_array($tag, self::$languages, true)) {
                self::$languages[] = $tag;
            }
        }
    }
}
