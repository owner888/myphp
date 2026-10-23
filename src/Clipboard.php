<?php
declare(strict_types=1);

namespace MyPHP;

use MyPHP\Native\Pasteboard;
use MyPHP\Transfer\{Data, Format};

/**
 * The system's clipboard (mygo's Clipboard): data in several formats, as
 * a drag carries (Transfer\Data), whose providers give each format when
 * an app pastes it. Call it once the app runs.
 */
final class Clipboard
{
    /**
     * Replaces the clipboard with $data's items, each offering its formats
     * (mygo's Write). Providers run on the main thread at most once, when
     * an app asks for their format, as long as the data stays on the
     * clipboard, even after the window that copied it closed: keep what
     * they read until $opts->onRelease. Data of no formats clears it.
     */
    public static function write(Data $data, ClipboardOptions $opts = new ClipboardOptions): void
    {
        self::guard();
        if (in_array(Format::DRAG_SESSION, $data->formats(), true)) {
            throw new \InvalidArgumentException('myphp: the clipboard cannot hold a drag session of the app');
        }
        Pasteboard::writeData($data->snapshot(), $opts->onRelease);
    }

    /** The clipboard's representations of $formats, all it offers without any (mygo's Read): data of no items when it is empty. The data is the app's to keep. */
    public static function read(string ...$formats): Data
    {
        self::guard();
        return Pasteboard::readData(...($formats === [] ? Pasteboard::formats() : $formats));
    }

    /** The formats on the clipboard, without reading them (mygo's Formats). @return list<string> */
    public static function formats(): array
    {
        self::guard();
        return Pasteboard::formats();
    }

    /** One representation, of the items coalesced as Data::read does (mygo's ReadFormat); Transfer\FormatUnavailable when there is none. */
    public static function readFormat(string $format): string
    {
        return self::read($format)->read($format);
    }

    /** The local files on the clipboard, from its file or URI list (mygo's ReadFiles): remote URLs are left out. @return list<string> */
    public static function readFiles(): array
    {
        $d = self::read(Format::FILE_LIST, Format::URI_LIST);
        return $d->formats() === [] ? [] : $d->paths();
    }

    /** Puts files on the clipboard, as file URLs, from absolute paths (mygo's WriteFiles). */
    public static function writeFiles(string ...$paths): void
    {
        self::write(Data::files(...$paths));
    }

    /** Gives the system every representation of the app's data on the clipboard, so that it stays once the app quits, and lets go of its providers (mygo's Flush); nothing for another app's. */
    public static function flush(): void
    {
        self::guard();
        Pasteboard::flush();
    }

    private static function guard(): void
    {
        if (Pasteboard::$providing) {
            throw new ClipboardReentrant('myphp: clipboard access from a clipboard provider');
        }
    }
}
