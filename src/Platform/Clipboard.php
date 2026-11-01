<?php
declare(strict_types=1);

namespace MyPHP\Platform;

use Closure;
use MyPHP\Transfer\Data;

/** The system clipboard (mygo's platform.Clipboard). */
interface Clipboard
{
    /** Copies the representations of $formats, all for none; the data outlives the clipboard's. @param list<string> $formats */
    public function readData(array $formats): Data;

    /** Replaces the clipboard with an application-owned snapshot; $released runs once its providers are no longer needed, never after a failed write. */
    public function writeData(Data $data, ?Closure $released): void;

    /** @return list<string> */
    public function formats(): array;

    /** Gives the system every representation of the app's data, so that it stays once the app quits. */
    public function flush(): void;

    public function readText(): string;

    public function writeText(string $text): void;

    public function readHTML(): string;

    /** The image as PNG, '' without one. */
    public function readImage(): string;

    /** Whether $bytes are an image the clipboard takes. */
    public function isImage(string $bytes): bool;

    public function clear(): void;

    /** The types on the clipboard as the system names them. @return list<string> */
    public function availableFormats(): array;
}
