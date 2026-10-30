<?php
declare(strict_types=1);

namespace MyPHP;

/** The kind of a FileEvent (mygo's FileOp). */
enum FileOp: string
{
    /** A file or directory that appeared. */
    case FileCreate = 'create';
    /**
     * A file whose content may have changed: its size or modification time
     * changed, or the system said it was written. Changes of permissions
     * and other metadata are not reported, nor are directories written,
     * whose changes are their entries'.
     */
    case FileWrite = 'write';
    /** A file or directory that went away. */
    case FileRemove = 'remove';
    /** A file or directory that moved within the watched directory. */
    case FileRename = 'rename';
    /**
     * Only that something in or under the directory at the path changed:
     * which file, and how, is unknown. A watch with
     * FileWatchOptions::$hintsOnly reports nothing else, but a FileRemove
     * of "." when its directory goes away.
     */
    case FileHint = 'hint';
}
