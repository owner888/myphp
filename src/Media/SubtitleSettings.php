<?php
declare(strict_types=1);

namespace MyPHP\Media;

use MyPHP\App;
use MyPHP\PathName;
use RuntimeException;

/**
 * The settings of IINA's Subtitles pane the player has: the preferred
 * languages of subtitles (IINA's subLang: codes such as zh-Hans or en,
 * none for the system's), and the OpenSubtitles account logged in (its
 * user's name, IINA's openSubUsername; its password is the Keychain's,
 * Secrets, as IINA keeps it); in a JSON file (by default
 * subtitle-settings.json in App::path(UserData)), written through a
 * temporary one.
 *
 * @internal UI\VideoPlayerState
 */
final class SubtitleSettings
{
    private static ?self $default = null;
    /** @var ?array{languages: list<string>, user: string} read on first use */
    private ?array $all = null;

    public function __construct(private readonly string $file) {}

    /** The app's: subtitle-settings.json in its user data. */
    public static function default(): self
    {
        return self::$default ??= new self(App::path(PathName::UserData) . '/subtitle-settings.json');
    }

    /** @return list<string> the preferred languages, none for the system's */
    public function languages(): array
    {
        return $this->all()['languages'];
    }

    /** @param list<string> $languages */
    public function setLanguages(array $languages): void
    {
        $this->save(['languages' => array_values(array_unique(array_filter(array_map('trim', $languages), fn(string $l) => $l !== '')))] + $this->all());
    }

    /** The OpenSubtitles account's user, '' for none. */
    public function user(): string
    {
        return $this->all()['user'];
    }

    public function setUser(string $user): void
    {
        $this->save(['user' => $user] + $this->all());
    }

    /** @return array{languages: list<string>, user: string} */
    private function all(): array
    {
        if ($this->all === null) {
            $all = ['languages' => [], 'user' => ''];
            if (is_file($this->file)) {
                $json = json_decode((string)file_get_contents($this->file), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($json) || !is_array($json['languages'] ?? []) || !is_string($json['user'] ?? '')) {
                    throw new RuntimeException("{$this->file} is not the subtitles' settings");
                }
                $all = ['languages' => array_values(array_map('strval', $json['languages'] ?? [])), 'user' => (string)($json['user'] ?? '')];
            }
            $this->all = $all;
        }
        return $this->all;
    }

    /** @param array{languages: list<string>, user: string} $all */
    private function save(array $all): void
    {
        $tmp = "{$this->file}.tmp";
        if (file_put_contents($tmp, json_encode($all, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false || !rename($tmp, $this->file)) {
            throw new RuntimeException("cannot write {$this->file}");
        }
        $this->all = $all;
    }
}
