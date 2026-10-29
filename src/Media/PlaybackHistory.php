<?php
declare(strict_types=1);

namespace MyPHP\Media;

use MyPHP\App;
use MyPHP\PathName;
use RuntimeException;

/**
 * The films played, as IINA's playback history (HistoryController): each
 * its path or URL, its name, title and length, and when it was played,
 * the latest first, a film once (played again, it goes first); in a JSON
 * file (default(): history.json in App::path(UserData)), written through
 * a temporary one. Where a film was left is PlaybackPositions'.
 *
 * @internal UI\VideoPlayerState, UI\VideoPlayerHistory, UI\VideoPlayerWelcome, UI\VideoPlayerSettingsPages
 */
final class PlaybackHistory
{
    private static ?self $default = null;
    /** @var ?list<array{path: string, name: string, title: string, duration: float, added: int}> read on first use */
    private ?array $entries = null;

    public function __construct(private readonly string $file) {}

    /** The app's: history.json in its user data. */
    public static function default(): self
    {
        return self::$default ??= new self(App::path(PathName::UserData) . '/history.json');
    }

    /** @return list<array{path: string, name: string, title: string, duration: float, added: int}> the films played, the latest first */
    public function all(): array
    {
        if ($this->entries === null) {
            $entries = [];
            if (is_file($this->file)) {
                $json = json_decode((string)file_get_contents($this->file), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($json) || !array_is_list($json)) {
                    throw new RuntimeException("{$this->file} is no playback history");
                }
                foreach ($json as $e) {
                    if (!is_array($e) || !is_string($e['path'] ?? null) || !is_string($e['name'] ?? null) || !is_string($e['title'] ?? null) || !is_numeric($e['duration'] ?? null) || !is_int($e['added'] ?? null)) {
                        throw new RuntimeException("{$this->file}: an entry is not a film played");
                    }
                    $entries[] = ['path' => $e['path'], 'name' => $e['name'], 'title' => $e['title'], 'duration' => (float)$e['duration'], 'added' => $e['added']];
                }
            }
            $this->entries = $entries;
        }
        return $this->entries;
    }

    /** Notes that $path was played now, named $name, titled $title, $duration long: first, its entry before gone. */
    public function add(string $path, string $name, string $title, float $duration): void
    {
        $this->save([['path' => $path, 'name' => $name, 'title' => $title, 'duration' => $duration, 'added' => time()], ...array_values(array_filter($this->all(), fn(array $e) => $e['path'] !== $path))]);
    }

    /** Forgets the films $paths. @param list<string> $paths */
    public function remove(array $paths): void
    {
        $this->save(array_values(array_filter($this->all(), fn(array $e) => !in_array($e['path'], $paths, true))));
    }

    /** Forgets them all (IINA's Clear Playback History). */
    public function clear(): void
    {
        $this->save([]);
    }

    /** @param list<array{path: string, name: string, title: string, duration: float, added: int}> $entries */
    private function save(array $entries): void
    {
        $tmp = "{$this->file}.tmp";
        if (file_put_contents($tmp, json_encode($entries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)) === false || !rename($tmp, $this->file)) {
            throw new RuntimeException("cannot write {$this->file}");
        }
        $this->entries = $entries;
    }
}
