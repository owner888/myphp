<?php
declare(strict_types=1);

namespace MyPHP\Transfer;

/** The names of representations (mygo's transfer.Format): a MIME type, as for custom serialized data. */
final class Format
{
    public const TEXT = 'text/plain', HTML = 'text/html', URI_LIST = 'text/uri-list', PNG = 'image/png';
    /** A URI list known to hold local files, offered as the system's file list as well as a URI list. */
    public const FILE_LIST = 'application/x-mygo-file-list';
    /** Markdown's text (RFC 7763), net.daringfireball.markdown on macOS. */
    public const MARKDOWN = 'text/markdown';
    /** The key of a drag of this process, which no other reads (mygo's DragSessionFormat): never a value's own data. */
    public const DRAG_SESSION = 'application/x-mygo-drag-session';

    public static function validate(string $format): void
    {
        if ($format === '' || strpbrk($format, "\0\r\n") !== false) {
            throw new \InvalidArgumentException('transfer: invalid format');
        }
    }
}
