<?php
declare(strict_types=1);

namespace MyPHP\Media;

/**
 * The films a player goes through, as IINA's playlist: their paths in
 * order and the one playing; the films of a folder in the order Finder
 * sorts their names (numbers by their value: episode 2 before episode
 * 10), their durations read as they are first asked for. Shuffled
 * and sorted as IINA's (mpv's playlist-shuffle and playlist-unshuffle),
 * the one playing staying the one playing.
 */
final class Playlist
{
    /** The extensions of the films a folder's playlist takes. */
    public const FILMS = ['mkv', 'webm', 'mp4', 'm4v', 'mov', 'ts', 'm2ts', 'mts'];
    /** And of the audio files, after the films, as IINA's (its videos, then its audio): those AVFoundation or Matroska reads here. */
    public const AUDIO = ['mp3', 'm4a', 'm4b', 'aac', 'flac', 'wav', 'aif', 'aiff', 'caf', 'mka'];
    /** @var list<string> */
    private array $files = [];
    /** @var array<string, ?float> the durations read, by path; null for a file that is no film the player reads */
    private array $durations = [];
    private int $index = -1;
    /** @var ?list<string> the order before shuffle(), which unshuffle() goes back to; null while not shuffled */
    private ?array $unshuffled = null;

    /** @var array<string, ?array{title: string, artist: string, album: string}> the tags read, by path */
    private array $tags = [];

    /** @var array<string, string> the titles of the films that have them, by path (a web playlist's, yt-dlp's) */
    private array $titles = [];

    /** @param list<string> $files @param array<string, string> $titles the films' titles, by path, for those not named by their file (a web playlist's) */
    public function __construct(array $files = [], array $titles = [])
    {
        foreach ($files as $f) { $this->add($f); }
        $this->titles = $titles;
        $this->index = $this->files === [] ? -1 : 0;
    }

    /** The title of the film at $path: its own (a web playlist's), else its file's name (a URL's, decoded). */
    public function title(string $path): string
    {
        if (isset($this->titles[$path])) {
            return $this->titles[$path];
        }
        return preg_match('~^https?://~i', $path) === 1 ? rawurldecode(basename((string)parse_url($path, PHP_URL_PATH))) : basename($path);
    }

    /** The films of the folder of $path, by name as Finder sorts them, then its audio files so, as IINA's: $path the one playing. */
    public static function folder(string $path): self
    {
        $dir = dirname($path);
        $all = array_values(array_filter(scandir($dir) ?: [], fn(string $f) => $f[0] !== '.'));
        $of = function (array $exts) use ($all): array {
            $names = array_values(array_filter($all, fn(string $f) => in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $exts, true)));
            usort($names, 'strnatcasecmp');
            return $names;
        };
        $list = new self(array_map(fn(string $f) => "$dir/$f", [...$of(self::FILMS), ...$of(self::AUDIO)]));
        if (!$list->select($path)) { // a film of no film's extension: on its own
            $list = new self([$path]);
        }
        return $list;
    }

    /** @return list<string> */
    public function files(): array
    {
        return $this->files;
    }

    public function count(): int
    {
        return count($this->files);
    }

    /** The place of the film playing, -1 for none. */
    public function index(): int
    {
        return $this->index;
    }

    /** The film playing, null for none. */
    public function current(): ?string
    {
        return $this->files[$this->index] ?? null;
    }

    /** Makes $path the one playing, if it is in the list. */
    public function select(string $path): bool
    {
        $i = array_search($path, $this->files, true);
        if ($i === false) {
            return false;
        }
        $this->index = $i;
        return true;
    }

    /** The film after the one playing, made the one playing; null after the last. */
    public function next(): ?string
    {
        if (!isset($this->files[$this->index + 1])) {
            return null;
        }
        return $this->files[++$this->index];
    }

    /** The film before the one playing, made the one playing; null before the first. */
    public function previous(): ?string
    {
        if ($this->index <= 0) {
            return null;
        }
        return $this->files[--$this->index];
    }

    /** Adds $path at the end, unless it is in the list. */
    public function add(string $path): void
    {
        if (!in_array($path, $this->files, true)) {
            $this->files[] = $path;
        }
    }

    /** Takes the $i-th film out; the one playing stays the one playing, or none if it was it. */
    public function remove(int $i): void
    {
        if (!isset($this->files[$i])) {
            return;
        }
        array_splice($this->files, $i, 1);
        if ($i === $this->index) {
            $this->index = -1;
        } elseif ($i < $this->index) {
            $this->index--;
        }
    }

    /** Puts the films in an order of chance, the one playing still the one playing. */
    public function shuffle(): void
    {
        $this->unshuffled ??= $this->files;
        $this->reorder(function (array $files): array { shuffle($files); return $files; });
    }

    /** Goes back to the order before shuffle(), those added since after them; what was taken out stays out. */
    public function unshuffle(): void
    {
        if ($this->unshuffled === null) {
            return;
        }
        $before = $this->unshuffled;
        $this->unshuffled = null;
        $this->reorder(fn(array $files): array => [...array_values(array_intersect($before, $files)), ...array_values(array_diff($files, $before))]);
    }

    /** Whether the films are in the order shuffle() put them in. */
    public function shuffled(): bool
    {
        return $this->unshuffled !== null;
    }

    /** Sorts the films by their names, their titles (as IINA compares strings: by their bytes), up or down. */
    public function sortByName(bool $ascending = true): void
    {
        $this->reorder(function (array $files) use ($ascending): array {
            usort($files, fn(string $a, string $b): int => ($ascending ? 1 : -1) * strcmp($this->title($a), $this->title($b)));
            return $files;
        });
    }

    /** Sorts the films by their paths, up or down. */
    public function sortByPath(bool $ascending = true): void
    {
        $this->reorder(function (array $files) use ($ascending): array {
            usort($files, fn(string $a, string $b): int => ($ascending ? 1 : -1) * strcmp($a, $b));
            return $files;
        });
    }

    /** Puts the films in the order $order makes of them, the one playing still the one playing. @param \Closure(list<string>): list<string> $order */
    private function reorder(\Closure $order): void
    {
        $current = $this->current();
        $this->files = $order($this->files);
        $this->index = $current === null ? -1 : (int)array_search($current, $this->files, true);
    }

    /** How long the $i-th film lasts, in seconds, read once; null for one the player does not read. */
    /**
     * The tags of the audio file at $i (IINA's playlist's metadata: its
     * title and artist), read as first asked for; null for a film, a URL,
     * a file of none, or one the player reads not.
     * @return ?array{title: string, artist: string, album: string}
     */
    public function tags(int $i): ?array
    {
        $path = $this->files[$i] ?? null;
        if ($path === null || !in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::AUDIO, true) || preg_match('~^https?://~i', $path) === 1) {
            return null;
        }
        if (!array_key_exists($path, $this->tags)) {
            try {
                $t = Player::tagsOf($path);
                $this->tags[$path] = $t['title'] === '' && $t['artist'] === '' ? null : $t;
            } catch (\RuntimeException) {
                $this->tags[$path] = null; // no file the player reads: shown by its name
            }
        }
        return $this->tags[$path];
    }

    public function duration(int $i): ?float
    {
        $path = $this->files[$i] ?? null;
        if ($path === null) {
            return null;
        }
        if (!array_key_exists($path, $this->durations)) {
            try {
                $this->durations[$path] = Player::durationOf($path);
            } catch (\RuntimeException) {
                $this->durations[$path] = null; // no film the player reads: shown without a duration
            }
        }
        return $this->durations[$path];
    }
}
