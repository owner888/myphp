<?php
declare(strict_types=1);

namespace MyPHP\Native;

use MyPHP\App;
use RuntimeException;

/**
 * The recent documents (mygo's recent_docs.go): those the app opened
 * lately, newest first, kept in recent-documents.json in the user data
 * directory, and noted with NSDocumentController too, whose list the
 * Dock menu shows.
 *
 * @internal
 */
final class RecentDocuments
{
    /** How many the list keeps, as NSDocumentController's maximumRecentDocumentCount. */
    public const MAX = 10;
    /** @var ?list<string> */
    private static ?array $paths = null;
    /** The file the list is kept in; tests set it. @internal */
    public static ?string $file = null;
    /** Whether the system is told: off in tests. @internal */
    public static bool $system = true;

    private static function file(): string
    {
        return self::$file ?? App::userDataPath() . '/recent-documents.json';
    }

    /** @return list<string> */
    private static function load(): array
    {
        if (self::$paths === null) {
            $json = @file_get_contents(self::file());
            $list = $json === false ? null : json_decode($json, true);
            self::$paths = is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
        }
        return self::$paths;
    }

    public static function add(string $path): void
    {
        $path !== '' || throw new RuntimeException('myphp: App::addRecentDocument needs a path');
        if (!str_starts_with($path, '/')) {
            $path = getcwd() . "/$path";
        }
        $paths = array_values(array_filter(self::load(), fn(string $p): bool => $p !== $path));
        array_unshift($paths, $path);
        self::$paths = array_slice($paths, 0, self::MAX);
        self::save();
        if (self::$system) {
            $url = ObjC::msg('p:p', ObjC::cls('NSURL'), 'fileURLWithPath:', ObjC::str($path));
            ObjC::msg('v:p', ObjC::msg('p:', ObjC::cls('NSDocumentController'), 'sharedDocumentController'), 'noteNewRecentDocumentURL:', $url);
        }
        App::relabelMenus(); // the Open Recent menus list it
    }

    public static function clear(): void
    {
        self::$paths = [];
        self::save();
        if (self::$system) {
            ObjC::msg('v:p', ObjC::msg('p:', ObjC::cls('NSDocumentController'), 'sharedDocumentController'), 'clearRecentDocuments:', null);
        }
        App::relabelMenus();
    }

    /** The documents, newest first, without those that no longer exist, which come back if they do again. @return list<string> */
    public static function list(): array
    {
        return array_values(array_filter(self::load(), fn(string $p): bool => file_exists($p)));
    }

    private static function save(): void
    {
        $file = self::file();
        $tmp = "$file.tmp";
        if (@file_put_contents($tmp, json_encode(self::$paths, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false || !@rename($tmp, $file)) {
            throw new RuntimeException("myphp: cannot write $file");
        }
    }

    /** Forgets the list read, for tests. @internal */
    public static function reset(): void
    {
        self::$paths = null;
    }
}
