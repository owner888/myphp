<?php
declare(strict_types=1);

namespace MyPHP\Transfer;

/**
 * Data exchanged through native drag and drop (mygo's transfer.Data): an
 * immutable list of items, each with representations, never PHP values.
 */
final class Data
{
    /** @var list<Item> */
    public readonly array $items;

    public function __construct(Item ...$items)
    {
        $this->items = array_values($items);
    }

    /** All formats, in item order, without calling providers. @return list<string> */
    public function formats(): array
    {
        $out = [];
        foreach ($this->items as $i) {
            foreach ($i->formats() as $f) {
                if (!in_array($f, $out, true)) { $out[] = $f; }
            }
        }
        return $out;
    }

    /** The first of $formats the data offers, without calling providers: the receiver's preference wins over the source's order. */
    public function preferred(string ...$formats): ?string
    {
        return self::preferredFormat($this->formats(), ...$formats);
    }

    /** @param list<string> $offered */
    public static function preferredFormat(array $offered, string ...$preferred): ?string
    {
        foreach ($preferred as $f) {
            if (in_array($f, $offered, true)) { return $f; }
        }
        return null;
    }

    /** A copy whose providers are called again, as each drag starts a new cache. */
    public function snapshot(): self
    {
        return new self(...array_map(fn(Item $i) => new Item(...array_map(fn(Representation $r) => $r->fresh(), $i->reps)), $this->items));
    }

    /** $format read as one: the texts of the items joined by newlines, URI lists by CRLF, other formats the first item's. */
    public function read(string $format): string
    {
        $parts = [];
        foreach ($this->items as $i) {
            if (!in_array($format, $i->formats(), true)) {
                continue;
            }
            $parts[] = $i->read($format);
            if ($format !== Format::TEXT && $format !== Format::URI_LIST && $format !== Format::FILE_LIST) {
                break;
            }
        }
        if ($parts === []) {
            throw new FormatUnavailable;
        }
        return implode($format === Format::URI_LIST || $format === Format::FILE_LIST ? "\r\n" : "\n", $parts);
    }

    /** The representations of $formats copied, with no providers left: one failing fails the transfer, none matching is FormatUnavailable. @param list<string> $formats */
    public function materialize(array $formats): self
    {
        $items = [];
        foreach ($this->items as $i) {
            $reps = [];
            foreach ($i->formats() as $f) {
                if (in_array($f, $formats, true)) {
                    $reps[] = Representation::bytes($f, $i->read($f));
                }
            }
            if ($reps !== []) { $items[] = new Item(...$reps); }
        }
        if ($items === []) {
            throw new FormatUnavailable;
        }
        return new self(...$items);
    }

    /** Plain text. */
    public static function text(string $text): self
    {
        return new self(new Item(Representation::bytes(Format::TEXT, $text)));
    }

    /** A URL, as a URI list and as plain text. */
    public static function url(string $url): self
    {
        if (preg_match('~^[a-zA-Z][a-zA-Z0-9+.-]*:~', $url) !== 1 || strpbrk($url, "\r\n\0") !== false) {
            throw new \InvalidArgumentException("transfer: invalid URL \"$url\"");
        }
        return new self(new Item(Representation::bytes(Format::URI_LIST, $url), Representation::bytes(Format::TEXT, $url)));
    }

    /** Existing files, as file URLs, one item each: absolute paths; it neither reads nor deletes them. */
    public static function files(string ...$paths): self
    {
        $items = [];
        foreach ($paths as $path) {
            if (!str_starts_with($path, '/') || str_contains($path, "\0")) {
                throw new \InvalidArgumentException("transfer: file path must be absolute: \"$path\"");
            }
            $u = 'file://' . implode('/', array_map(fn(string $s) => strtr(rawurlencode($s), ['%24' => '$', '%26' => '&', '%2B' => '+', '%2C' => ',', '%3A' => ':', '%3B' => ';', '%3D' => '=', '%40' => '@']), explode('/', $path)));
            $items[] = new Item(Representation::bytes(Format::FILE_LIST, $u), Representation::bytes(Format::URI_LIST, $u));
        }
        return new self(...$items);
    }

    /** The URLs of the URI lists, without comments and blank lines. @return list<string> */
    public function urls(): array
    {
        $out = [];
        foreach (explode("\n", str_replace("\r\n", "\n", $this->read(Format::URI_LIST))) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#') { $out[] = $line; }
        }
        return $out;
    }

    /** The local files' paths: other schemes and other hosts are left out, so that a network URL never becomes a path. @return list<string> */
    public function paths(): array
    {
        try {
            $urls = explode("\n", str_replace("\r\n", "\n", $this->read(Format::FILE_LIST)));
        } catch (FormatUnavailable) {
            $urls = $this->urls();
        }
        $paths = [];
        foreach ($urls as $raw) {
            $u = parse_url(trim($raw));
            if ($u === false || ($u['scheme'] ?? '') !== 'file' || !in_array($u['host'] ?? '', ['', 'localhost'], true)) {
                continue;
            }
            $p = rawurldecode($u['path'] ?? '');
            if (str_starts_with($p, '/') && !str_contains($p, "\0")) { $paths[] = $p; }
        }
        return $paths;
    }
}
