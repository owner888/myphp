<?php
declare(strict_types=1);

namespace MyPHP;

use RuntimeException;

/**
 * MyPHP's own strings, as mygo's internal/l10n: those of the controls of
 * UI\Context, of the code editor and of the roles of menus, in the
 * languages they are translated to (src/l10n/<tag>.json, English the
 * source, with the descriptions translators read). They are a library's
 * catalog (Catalog::library) of the namespace "myphp": an app changes
 * them, or translates them to its other languages, with keys
 * "myphp.<key>" in its own catalogs, as "myphp.cancel" in its de.json.
 * `L10n::in($locale)` says them in a locale, `get($key, $args)` formatting
 * their messages.
 */
final class L10n
{
    /** The namespace of the keys with which apps change MyPHP's strings: "myphp.<key>". */
    public const NAMESPACE = 'myphp';
    private static ?Catalog $catalog = null;
    /** @var array<string, L10n> */
    private static array $in = [];

    private function __construct(private readonly string $locale) {}

    /** The catalog of MyPHP's strings, which `myphp generate` checks the keys "myphp.<key>" of apps against. */
    public static function catalog(): Catalog
    {
        return self::$catalog ??= Catalog::library(__DIR__ . '/l10n', 'en', self::NAMESPACE);
    }

    /** The languages MyPHP's own strings are in, English first. @return list<string> */
    public static function languages(): array
    {
        return self::catalog()->languages();
    }

    /** The strings in a locale: the language of theirs that suits it best, along its parents, else the app's source language, else English. */
    public static function in(string $locale): self
    {
        return self::$in[$locale] ??= new self($locale);
    }

    /** The string $key says in this locale, with $args formatted into it. Throws for a key MyPHP has no string of. */
    public function get(string $key, array $args = []): string
    {
        $c = self::catalog();
        $s = $c->format($this->locale, $key, $args);
        if ($s === $key && $c->message('en', $key) === null) {
            throw new RuntimeException("L10n: no string $key");
        }
        return $s;
    }
}
