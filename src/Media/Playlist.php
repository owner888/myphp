<?php
declare(strict_types=1);

namespace MyPHP\Media;

/**
 * The films a player goes through, as IINA's playlist: their paths in
 * order and the one playing; the films of a folder in the order Finder
 * sorts their names (numbers by their value: episode 2 before episode
 * 10), their durations read as they are first asked for.
 */
final class Playlist
{
    /** The extensions of the films a folder's playlist takes. */
    public const FILMS = ['mkv', 'webm', 'mp4', 'm4v', 'mov'];
    /** @var list<string> */
    private array $files = [];
    /** @var array<string, ?float> the durations read, by path; null for a file that is no film the player reads */
    private array $durations = [];
    private int $index = -1;

    /** @param list<string> $files */
    public function __construct(array $files = [])
    {
        foreach ($files as $f) { $this->add($f); }
        $this->index = $this->files === [] ? -1 : 0;
    }

    /** The films of the folder of $path, by name as Finder sorts them, $path the one playing. */
    public static function folder(string $path): self
    {
        $dir = dirname($path);
        $names = array_values(array_filter(scandir($dir) ?: [], fn(string $f) => $f[0] !== '.' && in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), self::FILMS, true)));
        usort($names, 'strnatcasecmp');
        $list = new self(array_map(fn(string $f) => "$dir/$f", $names));
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

    /** How long the $i-th film lasts, in seconds, read once; null for one the player does not read. */
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
