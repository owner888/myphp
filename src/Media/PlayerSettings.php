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
     * subLang and audioLanguage lists of BCP 47 tags here, not lists of ISO 639-2 codes in
     * strings). A value set is of its default's type, a float's an int too.
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
        // UI: appearance
        'themeMaterial' => 0, // 0 dark, 2 light, 4 the system's
        'useLiquidGlassOSD' => true,
        'useLiquidGlassOSC' => true,
        'useLiquidGlassSidebar' => true,
        'compactUI' => false,
        // UI: window
        'initialWindowSizePosition' => '', // mpv's geometry: "1280", "x50%", "+20+20", "-10%-10%"
        'resizeWindowOption' => 2, // 0 fit the screen, 1 0.5x, 2 1x, 3 1.5x, 4 2x the video's size
        'resizeWindowTiming' => 1, // 0 always, 1 only when opened by hand, 2 never
        'usePhysicalResolution' => true,
        'alwaysFloatOnTop' => false,
        'alwaysShowOnTopIcon' => false,
        // UI: on screen controller
        'oscPosition' => 0, // 0 floating, 1 top, 2 bottom
        'controlBarToolbarButtons' => [7, 2, 1, 0], // IINA's ToolBarButton: 0 settings, 1 playlist, 2 pip, 3 full screen, 4 music mode, 5 subtitles, 6 screenshot, 7 plugins, 8 live text
        'controlBarPositionHorizontal' => 0.5,
        'controlBarPositionVertical' => 0.1,
        'arrowBtnAction' => 0, // 0 speed, 1 the playlist, 2 seek
        'enableControlBarAutoHide' => true,
        'controlBarAutoHideTimeout' => 2.5,
        'controlBarStickToCenter' => true,
        'showChapterPos' => false,
        'showRemainingTime' => false,
        'scaleRemainingTime' => false,
        'disablePlaySliderScrolling' => false,
        'disableVolumeSliderScrolling' => false,
        // UI: on screen display
        'enableOSD' => true,
        'disableOSDFileStartMsg' => false,
        'disableOSDPauseResumeMsgs' => false,
        'disableOSDSeekMsg' => false,
        'disableOSDSpeedMsg' => false,
        'osdAutoHideTimeout' => 1.0,
        'osdTextSize' => 20.0,
        'displayTimeAndBatteryInFullScreen' => false,
        // UI: thumbnails
        'enableThumbnailPreview' => true,
        'maxThumbnailPreviewCacheSize' => 500, // MB
        'enableThumbnailForRemoteFiles' => false,
        'thumbnailWidth' => 120,
        // UI: picture in picture
        'windowBehaviorWhenPip' => 1, // 0 nothing, 1 hide, 2 minimize
        'pauseWhenPip' => false,
        'togglePipByMinimizingWindow' => false,
        'togglePipByMinimizingWindowForVideoOnly' => false,
        // UI: accessibility
        'disableAnimations' => false,
        // Music mode's own, which its buttons set (IINA's)
        'musicModeShowPlaylist' => false,
        'musicModeShowAlbumArt' => true,
        // Video
        'videoThreads' => 0, // 0 as many as the cores
        'hardwareDecoder' => 1, // 0 disabled, 1 auto
        'enableLiveText' => false,
        'enableHdrSupport' => true,
        // Audio
        'audioDevice' => 'auto', // mpv's name of it: auto, or coreaudio/<its unique ID>
        'audioDeviceDesc' => 'Autoselect device', // and its description, shown where it is missing
        'enableInitialVolume' => false,
        'initialVolume' => 100, // 0 to 1000
        'maxVolume' => 100, // 100 to 1000
        'replayGain' => 0, // 0 none, 1 the track's, 2 the album's
        'replayGainPreamp' => 0, // dB, -150 to 150
        'replayGainClip' => false,
        'replayGainFallback' => 0, // dB, -200 to 60
        'gaplessAudio' => 1, // 0 off, 1 weak, 2 strong
        'audioLanguage' => [],
        // Control
        'pinchAction' => 0, // 0 the window's size, 1 full screen, 2 nothing
        'forceTouchAction' => 0, // as a click's: 0 nothing, 1 full screen, 2 pause, 3 hide the controls, 4 picture in picture, 5 the A-B loop, 6 the speed back to 1
        'singleClickAction' => 3,
        'doubleClickAction' => 1,
        'rightClickAction' => 2,
        'middleClickAction' => 0,
        'videoViewAcceptsFirstMouse' => false,
        'verticalScrollAction' => 0, // 0 the volume, 1 seek, 2 nothing, 4 the speed
        'horizontalScrollAction' => 1,
        'useExactSeek' => 0, // 0 to keyframes, 1 exact, 2 auto
        'relativeSeekAmount' => 3, // 1 to 4
        'volumeScrollAmount' => 3,
        'playbackSpeedScrollAmount' => 3,
        // Key bindings
        'useMediaKeys' => true,
        'currentInputConfigName' => 'IINA Default',
        'displayKeyBindingRawValues' => false,
        // Advanced
        'enableAdvancedSettings' => false,
        'enableLogging' => false,
        'logLevel' => 1, // 0 verbose, 1 debug, 2 warning, 3 error
        // Network
        'enableCache' => true,
        'cachePauseInitial' => false,
        'cachePauseWait' => 1.0, // seconds
        'secPrefech' => 36000, // seconds
        'defaultCacheSize' => 153600, // KiB
        'showBufferingThrobber' => true,
        'showSeekingThrobber' => true,
        'userAgent' => '',
        'httpProxy' => '', // host:port
        'ytdlEnabled' => true,
        'ytdlSearchPath' => '',
        'ytdlRawOptions' => '',
        // Subtitles
        'subAutoLoadIINA' => 2, // 0 off, 1 those containing the film's name (mpv's fuzzy), 2 IINA's matching of series too
        'subAutoLoadPriorityString' => '', // words, by commas: a file of more of them first
        'subAutoLoadSearchPath' => './*', // folders by colons, relative to the film's, "/*" their folders
        'subOverrideLevel' => 3, // mpv's sub-ass-override: 0 yes, 1 force, 2 strip, 3 scale, 4 no
        'secondarySubOverrideLevel' => 3,
        'subTextFont' => 'sans-serif',
        'subTextSize' => 55.0, // scaled pixels: of a height of 720
        'subTextColorString' => '1.0/1.0/1.0/1.0', // red/green/blue/alpha, 0 to 1 (IINA's mpvColorString)
        'subBold' => false,
        'subItalic' => false,
        'subBlur' => 0.0,
        'subSpacing' => 0.0,
        'subBorderSize' => 3.0,
        'subBorderColorString' => '0.0/0.0/0.0/1.0',
        'subShadowSize' => 0.0,
        'subShadowColorString' => '0.0/0.0/0.0/0.0',
        'subBorderStyle' => 1, // 1 the outline and the shadow, 3 an opaque box, 4 a box behind all
        'subAlignX' => 1, // 0 left, 1 center, 2 right
        'subAlignY' => 2, // 0 top, 1 center, 2 bottom
        'subMarginX' => 25.0,
        'subMarginY' => 22.0,
        'subPos' => 100.0, // % of the height: 100 at the foot, 0 at the top
        'displayInLetterBox' => true,
        'subScaleWithWindow' => true,
        'defaultEncoding' => 'auto',
        'autoSearchOnlineSub' => false,
        'autoSearchThreshold' => 20, // minutes: films no shorter
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
