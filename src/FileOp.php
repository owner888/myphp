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
}
