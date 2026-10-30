<?php
declare(strict_types=1);

namespace MyPHP\Media;

use MyPHP\App;
use MyPHP\PathName;
use RuntimeException;

/**
 * The equalizer's profiles the user saved, as IINA's userEQPresets: the
 * gains of Player::EQUALIZER_BANDS by name, in a JSON file (by default
 * equalizer-profiles.json in App::path(UserData)), written through a
 * temporary one. They are in the order they were saved, a renamed one
 * the last (IINA's are a Swift dictionary's, of no order).
 *
 * @internal UI\MoviePlayerState
 */
final class EqualizerProfiles
{
    private static ?self $default = null;
    /** @var ?array<string, list<float>> by name; read on first use */
    private ?array $profiles = null;

    public function __construct(private readonly string $file) {}

    /** The app's: equalizer-profiles.json in its user data. */
    public static function default(): self
    {
        return self::$default ??= new self(App::path(PathName::UserData) . '/equalizer-profiles.json');
    }

    /** @return array<string, list<float>> the profiles' gains by their names */
    public function all(): array
    {
        if ($this->profiles === null) {
            $profiles = [];
            if (is_file($this->file)) {
                $json = json_decode((string)file_get_contents($this->file), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($json)) {
                    throw new RuntimeException("{$this->file} is no object");
                }
                foreach ($json as $name => $gains) {
                    if (!is_array($gains) || !array_is_list($gains) || count($gains) !== count(Player::EQUALIZER_BANDS) || array_filter($gains, fn($g) => !is_int($g) && !is_float($g)) !== []) {
                        throw new RuntimeException("{$this->file}: the profile \"$name\" is not " . count(Player::EQUALIZER_BANDS) . ' gains');
                    }
                    $profiles[(string)$name] = array_map('floatval', $gains);
                }
            }
            $this->profiles = $profiles;
        }
        return $this->profiles;
    }

    /** Saves $gains as the profile $name, the last. @param list<float> $gains */
    public function set(string $name, array $gains): void
    {
        $all = $this->all();
        unset($all[$name]);
        $all[$name] = array_map('floatval', array_values($gains));
        $this->save($all);
    }

    /** Names the profile $from $to, the last (IINA's removes it and adds it). */
    public function rename(string $from, string $to): void
    {
        $all = $this->all();
        if (!isset($all[$from])) {
            throw new RuntimeException("myphp: no equalizer profile \"$from\"");
        }
        $gains = $all[$from];
        unset($all[$from], $all[$to]);
        $all[$to] = $gains;
        $this->save($all);
    }

    public function remove(string $name): void
    {
        $all = $this->all();
        unset($all[$name]);
        $this->save($all);
    }

    /** @param array<string, list<float>> $all */
    private function save(array $all): void
    {
        $tmp = "{$this->file}.tmp";
        if (file_put_contents($tmp, json_encode((object)$all, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)) === false || !rename($tmp, $this->file)) {
            throw new RuntimeException("cannot write {$this->file}");
        }
        $this->profiles = $all;
    }
}
