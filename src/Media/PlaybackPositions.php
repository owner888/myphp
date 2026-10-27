<?php
declare(strict_types=1);

namespace MyPHP\Media;

use MyPHP\App;
use MyPHP\PathName;
use RuntimeException;

/**
 * Where films were left, to go on from there when they open again, as
 * IINA's "resume last playback position": by the file's path, in a JSON
 * file (by default playback-positions.json in App::path(UserData)),
 * written through a temporary one; the most recent KEEP kept.
 */
final class PlaybackPositions
{
    /** How many films it remembers, the least recent forgotten first. */
    public const KEEP = 500;
    private static ?self $default = null;
    /** @var ?array<string, array{0: float, 1: int}> seconds and when (Unix time), by path; read on first use */
    private ?array $positions = null;

    public function __construct(private readonly string $file) {}

    /** The app's: playback-positions.json in its user data. */
    public static function default(): self
    {
        return self::$default ??= new self(App::path(PathName::UserData) . '/playback-positions.json');
    }

    /** Where the film at $path was left, null if nowhere. */
    public function get(string $path): ?float
    {
        return $this->all()[$path][0] ?? null;
    }

    /** Notes that the film at $path was left at $seconds. */
    public function set(string $path, float $seconds): void
    {
        $all = $this->all();
        unset($all[$path]);
        $all[$path] = [round($seconds, 3), time()]; // the last, the most recent
        $this->positions = array_slice($all, -self::KEEP, null, true);
        $this->save();
    }

    /** Forgets the film at $path: watched to the end. */
    public function forget(string $path): void
    {
        $all = $this->all();
        if (!isset($all[$path])) {
            return;
        }
        unset($all[$path]);
        $this->positions = $all;
        $this->save();
    }

    /** @return array<string, array{0: float, 1: int}> */
    private function all(): array
    {
        if ($this->positions === null) {
            $this->positions = [];
            if (is_file($this->file)) {
                $json = json_decode((string)file_get_contents($this->file), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($json)) {
                    throw new RuntimeException("{$this->file} is no object");
                }
                foreach ($json as $path => $v) {
                    if (is_array($v) && is_numeric($v[0] ?? null) && is_int($v[1] ?? null)) { $this->positions[(string)$path] = [(float)$v[0], $v[1]]; }
                }
            }
        }
        return $this->positions;
    }

    private function save(): void
    {
        $tmp = "{$this->file}.tmp";
        if (file_put_contents($tmp, json_encode($this->positions, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false || !rename($tmp, $this->file)) {
            throw new RuntimeException("cannot write {$this->file}");
        }
    }
}
