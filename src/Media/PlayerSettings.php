<?php
declare(strict_types=1);

namespace MyPHP\Media;

use Closure;
use InvalidArgumentException;
use MyPHP\App;
use MyPHP\PathName;
use RuntimeException;

/**
 * The player's settings, those of IINA's Settings window, by IINA's
 * names of them (its Preference keys) and with IINA's defaults (DEFAULTS):
 * what Context::videoPlayerSettings shows and sets, what the player reads
 * as it plays, and what an app reads of those that are its own (what it
 * does at launch, whether it quits once its windows close…). In a JSON
 * file (default(): settings.json in App::path(UserData)) of those set,
 * written through a temporary one; onChange() tells of a change.
 *
 *     $s = PlayerSettings::default();
 *     if ($s->get('quitWhenNoOpenedWindow')) { … }
 *     $s->onChange('quitWhenNoOpenedWindow', fn(mixed $on) => …);
 */
final class PlayerSettings
{
    /**
     * The settings and their defaults, IINA's (Preference.defaultPreference;
     * subLang a list of BCP 47 tags here, not a list of ISO 639-2 codes in a
     * string). A value set is of its default's type, a float's an int too.
     */
    public const DEFAULTS = [
        // General: behavior
        'actionAfterLaunch' => 0, // 0 the welcome window, 1 the open panel, 2 nothing
        'pauseWhenOpen' => false,
        'fullScreenWhenOpen' => false,
        'pauseWhenMinimized' => false,
        'pauseWhenInactive' => false,
        'playWhenEnteringFullScreen' => false,
        'pauseWhenLeavingFullScreen' => false,
        'pauseWhenGoesToSleep' => true,
        'alwaysOpenInNewWindow' => true,
        'groupSimultaneousOpensInPlaylist' => false,
        'allowDuplicatePlayers' => false,
        'quitWhenNoOpenedWindow' => false,
        'keepOpenOnFileEnd' => true,
        'useLegacyFullScreen' => false,
        'blackOutMonitor' => false,
        'preventScreenSaver' => true,
        'allowScreenSaverForAudio' => false,
        'autoSwitchToMusicMode' => true,
        'checkForUpdates' => true, // Sparkle's automaticallyChecksForUpdates in IINA, its own setting
        'updateCheckInterval' => 86400, // and its updateCheckInterval: 3600, 86400, 604800 or 2629800 seconds
        // General: history
        'resumeLastPosition' => true,
        'recordPlaybackHistory' => true,
        'recordRecentFiles' => true,
        'trackAllFilesInRecentOpenMenu' => true,
        // General: playlist
        'playlistAutoAdd' => true,
        'playlistAutoPlayNext' => true,
        'playlistShowMetadata' => true,
        'playlistShowMetadataInMusicMode' => true,
        'autoRepeat' => false,
        'defaultRepeatMode' => 0, // 0 the playlist, 1 the file
        // General: screenshots
        'screenshotSaveToFile' => true,
        'screenShotFolder' => '~/Pictures/Screenshots',
        'screenShotFormat' => 0, // 0 png, 1 jpg, 2 jpeg, 3 webp, 4 jxl, 5 avif
        'screenshotCopyToClipboard' => false,
        'screenShotIncludeSubtitle' => true,
        'screenshotShowPreview' => true,
        // Video
        'hardwareDecoder' => 1, // 0 disabled, 1 auto
        // Subtitles
        'openSubUsername' => '',
        'subLang' => [],
    ];

    private static ?self $default = null;
    /** @var ?array<string, mixed> those set, by name; read on first use */
    private ?array $set = null;
    /** @var array<string, list<Closure(mixed): void>> */
    private array $listeners = [];

    public function __construct(private readonly string $file) {}

    /** The app's: settings.json in its user data. */
    public static function default(): self
    {
        return self::$default ??= new self(App::path(PathName::UserData) . '/settings.json');
    }

    /** The value of setting $key: the one set, else its default. Throws for a name that is no setting. */
    public function get(string $key): mixed
    {
        self::known($key);
        return $this->all()[$key] ?? self::DEFAULTS[$key];
    }

    /** Sets $key to $value, kept in the file, and tells those listening (onChange) if it changed. Throws for no setting, or a value of another type. */
    public function set(string $key, mixed $value): void
    {
        $value = self::checked($key, $value);
        if ($this->get($key) === $value && array_key_exists($key, $this->all())) {
            return;
        }
        $all = [$key => $value] + $this->all();
        $tmp = "{$this->file}.tmp";
        if (file_put_contents($tmp, json_encode($all, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)) === false || !rename($tmp, $this->file)) {
            throw new RuntimeException("cannot write {$this->file}");
        }
        $this->set = $all;
        foreach ($this->listeners[$key] ?? [] as $fn) { $fn($value); }
    }

    /** Calls $fn with the new value of $key each time set() changes it. */
    public function onChange(string $key, Closure $fn): void
    {
        self::known($key);
        $this->listeners[$key][] = $fn;
    }

    /** @internal UI\VideoPlayerState: whether $key was ever set (else the app's own choice holds, as Player::$hardwareDecoding's). */
    public function stored(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    private static function known(string $key): void
    {
        if (!array_key_exists($key, self::DEFAULTS)) {
            throw new InvalidArgumentException("PlayerSettings: no setting \"$key\"");
        }
    }

    /** $value as setting $key keeps it: of its default's type (an int as a float's), a list's as a list; else it throws. */
    private static function checked(string $key, mixed $value): mixed
    {
        self::known($key);
        $d = self::DEFAULTS[$key];
        return match (true) {
            is_float($d) && (is_int($value) || is_float($value)) => (float)$value,
            is_array($d) && is_array($value) && array_is_list($value) => $value,
            gettype($d) === gettype($value) && !is_array($d) => $value,
            default => throw new InvalidArgumentException("PlayerSettings: \"$key\" is " . get_debug_type($d) . ', not ' . get_debug_type($value)),
        };
    }

    /** @return array<string, mixed> those set, read from the file the first time; a setting no longer known is left out */
    private function all(): array
    {
        if ($this->set === null) {
            $set = [];
            if (is_file($this->file)) {
                $json = json_decode((string)file_get_contents($this->file), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($json) || ($json !== [] && array_is_list($json))) {
                    throw new RuntimeException("{$this->file} is not the player's settings");
                }
                foreach ($json as $key => $value) {
                    if (!array_key_exists($key, self::DEFAULTS)) { continue; }
                    try {
                        $set[$key] = self::checked($key, $value);
                    } catch (InvalidArgumentException $e) {
                        throw new RuntimeException("{$this->file}: {$e->getMessage()}", 0, $e);
                    }
                }
            }
            $this->set = $set;
        }
        return $this->set;
    }
}
