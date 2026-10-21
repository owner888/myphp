<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * A change in a watched file or directory (mygo's FileEvent). A watch
 * compares what it sees with what it saw before, so events say what is
 * different, not every step that made it so: a file written twice in a row
 * is one write, and one created and removed in between is nothing. Treat
 * them as hints to read the files again.
 */
final class FileEvent implements \JsonSerializable
{
    public function __construct(
        public readonly FileOp $op,
        /** The path relative to the watched directory, separated by slashes; "." is the watched directory or file itself. */
        public readonly string $path,
        /** Where a FileRename moved from. */
        public readonly string $oldPath = '',
        /** Whether it is a directory, as it was before a FileRemove. */
        public readonly bool $isDir = false,
    ) {}

    /** As mygo's JSON: {op, path, oldPath (only for a rename), isDir}. @return array<string, string|bool> */
    public function jsonSerialize(): array
    {
        $e = ['op' => $this->op->value, 'path' => $this->path];
        if ($this->oldPath !== '') {
            $e['oldPath'] = $this->oldPath;
        }
        return $e + ['isDir' => $this->isDir];
    }
}
