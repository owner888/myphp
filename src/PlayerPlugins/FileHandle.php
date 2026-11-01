<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

/** IINA's file handle of a plugin's (JavascriptFileHandle): its bytes read or written at its offset; bytes are strings. */
final class FileHandle
{
    /** @param resource $h */
    public function __construct(private $h, private readonly bool $reading) {}

    public function offset(): int
    {
        return (int)ftell($this->h);
    }

    public function seekTo(int $offset): void
    {
        fseek($this->h, $offset);
    }

    public function seekToEnd(): void
    {
        fseek($this->h, 0, SEEK_END);
    }

    /** At most $length bytes (null for a handle of writing). */
    public function read(int $length): ?string
    {
        return $this->reading ? (string)fread($this->h, max(1, $length)) : null;
    }

    public function readToEnd(): ?string
    {
        return $this->reading ? (string)stream_get_contents($this->h) : null;
    }

    /** Writes $data: a string's bytes, or a list of byte values. @param string|list<int> $data */
    public function write(string|array $data): void
    {
        if (is_array($data)) { $data = pack('C*', ...$data); }
        fwrite($this->h, $data);
    }

    public function close(): void
    {
        if (is_resource($this->h)) { fclose($this->h); }
    }
}
