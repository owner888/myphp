<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;
use MyPHP\App;
use MyPHP\Logger;
use MyPHP\Media\EqualizerProfiles;
use MyPHP\Media\MetaVideo;
use MyPHP\Media\OpenSubtitles;
use MyPHP\Media\OpenSubtitlesQuota;
use MyPHP\Media\PlayerSettings;
use MyPHP\Media\Tmdb;
use MyPHP\SecretNotFound;
use MyPHP\Secrets;
use MyPHP\Media\PlaybackHistory;
use MyPHP\Media\PlaybackPositions;
use MyPHP\Media\Playlist;
use MyPHP\Media\Player;
use MyPHP\Media\ThumbnailCache;
use MyPHP\Media\CacheBudget;
use MyPHP\Media\HttpBytes;
use MyPHP\Media\Subtitles;
use MyPHP\Media\YtDlp;
use MyPHP\Media\Track;
use MyPHP\Native\NowPlaying;
use MyPHP\Native\PictureInPicture;
use RuntimeException;

/**
 * A video player's state, kept by the app across builds of
 * Context::moviePlayer: the title it shows, the volume (which a player
 * built next takes), whether the subtitles show, the sidebar's tab, the
 * message showing, what failed. Its methods do what the controls and the
 * keys do, and tell it as IINA's messages do, for an app's own menus and
 * keys too; they act on the player it was last built with.
 */
final class MoviePlayerState
{
    /** IINA's aspect ratios, the crop's too: the label (null for "default", or "none" of a crop) and the ratio, null for the picture's own. */
    public const ASPECTS = [[null, null], ['4:3', 4 / 3], ['16:9', 16 / 9], ['16:10', 16 / 10], ['21:9', 21 / 9], ['5:4', 5 / 4]];
    public const ROTATIONS = [0, 90, 180, 270];

    /** What the title bar shows: the film's title (a page's, else its file's name). */
    public string $title = '';
    /** The volume, 0 to 1, of this player and the next. */
    public float $volume = 1.0;
    public bool $subtitlesVisible = true;
    /** IINA's presets of the equalizer (Equalizations.swift), their gains of Player::EQUALIZER_BANDS, by the name of their strings (videoEq…). */
    public const EQUALIZER_PRESETS = [
        'Flat' => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0], 'Acoustic' => [5.0, 4.9, 3.95, 1.05, 2.15, 1.75, 3.5, 4.1, 3.55, 2.15],
        'Classical' => [4.75, 3.75, 3, 2.5, -1.5, -1.5, 0, 2.25, 3.25, 3.75], 'Dance' => [3.57, 6.55, 4.99, 0, 1.92, 3.65, 5.15, 4.54, 3.59, 0],
        'Deep' => [4.95, 3.55, 1.75, 1.00, 2.85, 2.50, 1.45, -2.15, -3.55, -4.60], 'Electronic' => [4.25, 3.8, 1.2, 0, -2.15, 2.25, 0.85, 1.25, 3.95, 4.8],
        'HipHop' => [5, 4.25, 1.5, 3, -1, -1, 1.5, -0.5, 2, 3], 'IncreaseBass' => [5.5, 4.25, 3.5, 2.5, 1.25, 0, 0, 0, 0, 0],
        'IncreaseTreble' => [0, 0, 0, 0, 0, 1.25, 2.5, 3.5, 4.25, 5.5], 'IncreaseVocal' => [-1.50, -3.00, -3.00, 1.50, 3.75, 3.75, 3.00, 1.50, 0, -1.50],
        'Jazz' => [4, 3, 1.5, 2.25, -1.5, -1.5, 0, 1.5, 3, 3.75], 'Latin' => [4.5, 3, 0, 0, -1.5, -1.5, -1.5, 0, 3, 4.5],
        'Loudness' => [6, 4, 0, 0, -2, 0, -1, -5, 5, 1], 'Lounge' => [-3.00, -1.50, -0.50, 1.50, 4.00, 2.50, 0, -1.50, 2.00, 1.00],
        'Piano' => [3, 2, 0, 2.5, 3, 1.5, 3.5, 4.5, 3, 3.5], 'Pop' => [-1.5, -1, 0, 2, 4, 4, 2, 0, -1, -1.5],
        'Rnb' => [2.62, 6.92, 5.65, 1.33, -2.19, -1.50, 2.32, 2.65, 3.0, 3.75], 'ReduceBass' => [-5.5, -4.25, -3.5, -2.5, -1.25, 0, 0, 0, 0, 0],
        'ReduceTreble' => [0, 0, 0, 0, 0, -1.25, -2.5, -3.5, -4.25, -5.5], 'Rock' => [5, 4, 3, 1.5, -0.5, -1, 0.5, 2.5, 3.5, 4.5],
        'SmallSpeaker' => [5.5, 4.25, 3.5, 2.5, 1.25, 0, -1.25, -2.5, -3.5, -4.25], 'SpokenWord' => [-3.46, -0.47, 0, 0.69, 3.46, 4.61, 4.84, 4.28, 2.54, 0],
    ];
    /** @var list<float> the equalizer's gains, of this player and the next (mpv's af stays as films change); the preset they are (EQUALIZER_PRESETS), '' for Manual */
    public array $equalizer = [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0];
    public string $equalizerPreset = '';
    /** @var ?list<float> the gains of the sliders while one is dragged, set as it is let go @internal MoviePlayer */
    public ?array $equalizerDraft = null;
    /** OpenSubtitles, of the options' key (MoviePlayerOptions::$openSubtitlesKey), null without one @internal */
    public ?OpenSubtitles $openSubtitles = null;
    /** TMDB, to look a film up by its name before OpenSubtitles (searchOnlineSubtitles); made as one is first looked up. */
    private ?Tmdb $tmdb = null;
    /** The settings of IINA's Settings window the player has: the app's, PlayerSettings::default(), unless set @internal */
    public ?PlayerSettings $playerSettings = null;
    /** Whether an account logs in to OpenSubtitles from the settings (IINA's indicator by its Login) @internal MoviePlayerSettings */
    public bool $loggingIn = false;
    /** The Keychain's key of an OpenSubtitles account's password (Secrets), by its user. */
    private const PASSWORD = 'opensubtitles:';
    /**
     * The subtitles found online to choose from (OpenSubtitles::search's),
     * null while none are shown; the ones chosen, by their place; whether a
     * search or its downloads go on (IINA's isSearchingOnlineSubtitle).
     * @var ?list<array{file: int, name: string, language: string, year: int, fps: float, downloads: int, uploaded: string}>
     * @internal MoviePlayer
     */
    public ?array $onlineSubtitles = null;
    /** @var list<int> @internal MoviePlayer */
    public array $onlineChosen = [];
    /** @internal MoviePlayer */
    public bool $searchingOnline = false;
    /** Whether the video decodes on the system's decoders, as the quick setting set it (null: as Player::$hardwareDecoding has each film open) @internal MoviePlayer */
    public ?bool $hardwareDecoding = null;
    /** The profile of the user's the gains are (EqualizerProfiles), '' for none @internal MoviePlayer */
    public string $equalizerProfile = '';
    /** The profiles the user saved: the app's, EqualizerProfiles::default(), unless set @internal */
    public ?EqualizerProfiles $profiles = null;

    /** Whether the secondary subtitles show (mpv's secondary-sub-visibility): their track read all the same. */
    public bool $secondarySubtitlesVisible = true;
    /** The sidebar's tab ('layout', 'video', 'audio', 'subtitles'), null while it is closed; the tab it opens at. */
    public ?string $sidebar = null;
    public string $tab = 'video';
    /** What failed: a renderer's error, or a subtitle file that would not load. */
    public string $error = '';

    /** @internal what the builds keep: the player, the window's runtime, the message, the pointer and the controls */
    public ?Player $player = null;
    /** @internal */
    public array $message = ['', '', 0.0];
    /** @internal */
    public array $pointer = [0.0, 0.0];
    /** @internal */
    public float $moved = 0.0, $at = 0.0, $speedAt = 1 / 3;
    /** @internal */
    public bool $shown = true;
    /** @internal seconds the wheel moved across and not sought yet, and when it last sought */
    public float $wheelSeek = 0.0, $wheelSeekAt = 0.0;
    /** @internal */
    public string $drawn = '';
    private ?\WeakReference $runtime = null;
    /** Whether the player built last goes on from where it was left (MoviePlayerOptions::$resume), and shows in Now Playing ($nowPlaying). */
    private bool $resume = false, $nowPlaying = false;
    /** The chapter Now Playing shows, to show the next as it comes. */
    private int $shownChapter = -1;
    /** @var list<Closure> the player's listeners, removed for another player */
    private array $off = [];

    /** Where films were left (MoviePlayerOptions::$resume): the app's, PlaybackPositions::default(), unless set. */
    public ?PlaybackPositions $positions = null;
    /** Seconds between two notes of where a film playing is. */
    public const NOTE_EVERY = 10.0;

    /** The films it goes through (open() makes it of the film's folder), and the one chosen in the playlist's sidebar. */
    public ?Playlist $playlist = null;
    /** @internal */
    public int $chosen = -1;
    /**
     * What plays again, as IINA's loop button sets it (nextLoop): 'off', the
     * next film at an end and none after the last; 'file', the film again
     * from its start; 'playlist', the first after the last (and the last
     * before the first).
     */
    public string $loop = 'off';
    /** The playlist sidebar's tab: 'playlist' or 'chapters'. */
    public string $playlistTab = 'playlist';
    /** @internal whether the player taken last has not been built with yet: where it was left is gone back to then */
    private bool $fresh = false;
    /**
     * The seek bar's thumbnails of the player's film, as IINA's: of the
     * keyframes at each hundredth of it (101 at most), 240 pixels wide,
     * made a few at a time after it opens and here once all are.
     * @var list<\MyPHP\Media\Thumbnail>
     */
    public array $thumbnails = [];
    /** The A-B loop's points, in seconds (abLoop): A alone set, nothing loops; both, it plays from A again at B. Null for none. */
    public ?float $loopA = null, $loopB = null;
    /** The player's boundary at B, removed when the loop goes. */
    private ?Closure $loopOff = null;
    /** The last screenshot's preview (IINA's ScreenshootOSDView, screenshotShowPreview): its image, its file (null, not kept), shown until when. @internal MoviePlayer */
    public ?array $shot = null;
    /** Whether the film plays in picture in picture (togglePictureInPicture), the system's floating window. */
    public bool $pictureInPicture = false;
    /** The picture in picture of the player, where this Mac has it. */
    private ?PictureInPicture $pip = null;

    /** The URL open() opens (a film over the network, read in a fiber), null when none: the player shows IINA's "Opening stream…" meanwhile. */
    public ?string $opening = null;
    /** While it opens, when the ad before a web page's video is over (microtime), which the indicator counts down to; null without one. @internal MoviePlayer */
    public ?float $adOver = null;

    /**
     * Opens the film at $path (or an http(s) URL) and plays it: a player of
     * it, the title its (Player::title), the playlist the films of its folder
     * (a URL's itself, a web playlist's its videos) unless it is in the one
     * there is. A URL opens in a fiber of
     * its own (App::go) while the app runs, the window going on meanwhile
     * ($opening). What fails to open shows as the error, the film playing
     * going on. Returns whether it opened (a URL: began to). As the
     * settings say (IINA's): the playlist the film alone without
     * playlistAutoAdd; looping (autoRepeat) as defaultRepeatMode says;
     * noted in the recent documents and the playback history; paused
     * (pauseWhenOpen), full screen (fullScreenWhenOpen).
     */
    public function open(string $path): bool
    {
        return $this->openFile($path, true);
    }

    /** Opens $path, as the user asked ($manual: a file opened, not the next of the playlist). */
    private function openFile(string $path, bool $manual): bool
    {
        $url = preg_match('~^https?://~i', $path) === 1;
        if ($url && App::running() && \Fiber::getCurrent() === null) {
            $this->opening = $path;
            $this->error = '';
            $this->changed();
            App::go(function () use ($path, $manual): void { $this->openFile($path, $manual); });
            return true;
        }
        $set = $this->settings();
        try {
            if ($this->settings()->stored('hardwareDecoder')) { Player::$hardwareDecoding = $this->settings()->get('hardwareDecoder') !== 0; } // the settings' hardware decoder, once set, as mpv's hwdec is IINA's
            Player::$videoThreads = $this->settings()->get('videoThreads'); // and its threads of decoding in software (IINA's videoThreads)
            [Player::$audioLanguages, Player::$subtitleLanguages] = [$set->get('audioLanguage'), $set->get('subLang')]; // the tracks of the preferred languages first (mpv's alang and slang)
            Player::$autoLoad = [$set->get('subAutoLoadIINA'), $set->get('subAutoLoadPriorityString'), $set->get('subAutoLoadSearchPath'), $set->get('playlistAutoAdd')]; // its subtitle files as IINA's auto load finds them
            Subtitles::$encoding = $set->get('defaultEncoding');
            [Player::$cache, Player::$cachePauseInitial, Player::$cacheWait] = [$set->get('enableCache'), $set->get('cachePauseInitial'), $set->get('cachePauseWait')]; // the network's cache, as mpv's options
            [CacheBudget::$max, CacheBudget::$seconds] = [$set->get('defaultCacheSize') << 10, $set->get('secPrefech')];
            HttpBytes::$userAgent = $set->get('userAgent');
            [YtDlp::$enabled, YtDlp::$searchPath, YtDlp::$rawOptions] = [$set->get('ytdlEnabled'), $set->get('ytdlSearchPath'), $set->get('ytdlRawOptions')];
            if ($set->get('httpProxy') !== '') { putenv('http_proxy=http://' . $set->get('httpProxy')); } // as IINA sets it, for curl and yt-dlp
            [$ahead, $this->ahead] = [$this->ahead, null];
            $this->log("Opening $path" . ($manual ? ' (by hand)' : ''));
            $p = ($ahead[0] ?? null) === $path && $ahead[1] !== null ? $ahead[1] // opened already, as its gapless audio
                : new Player($path, function (float $seconds): void { $this->adOver = microtime(true) + $seconds; $this->changed(); });
        } catch (RuntimeException $e) {
            $this->adOver = null;
            $this->opening = null;
            $this->error = $e->getMessage();
            $this->log("Cannot open $path: {$e->getMessage()}", Logger::ERROR);
            $this->changed();
            return false;
        }
        $this->opening = null; $this->adOver = null;
        $this->log(sprintf('Opened %s: %s, video %s, audio %s, %d subtitle tracks', $p->title(), self::clock($p->duration()), $p->videoTrack()?->codec ?? 'none', $p->audioTrack()?->codec ?? 'none', count($p->subtitleTracks())));
        if ($p->playlist() !== null) { // a web page's (yt-dlp's): its videos, the one playing chosen
            $this->playlist = $p->playlist();
        } elseif ($this->playlist === null || !$this->playlist->select($path)) {
            $this->playlist = $url || !$set->get('playlistAutoAdd') ? new Playlist([$path]) : Playlist::folder($path);
        }
        $this->chosen = $this->playlist->index();
        $this->title = $p->title();
        if ($manual && $set->get('autoRepeat')) { $this->loop = $set->get('defaultRepeatMode') === 1 ? 'file' : 'playlist'; } // IINA's "Loop when a file is opened manually"
        if ($set->get('recordRecentFiles') && ($manual || $set->get('trackAllFilesInRecentOpenMenu')) && App::running()) { App::addRecentDocument($path); }
        if ($set->get('recordPlaybackHistory')) { $this->history()->add($path, $url ? $path : basename($path), $p->title(), $p->duration()); }
        $this->setPlayer($p);
        $this->say($p->title(), '', 'fileStart'); // IINA's message of the file opened
        if ($p->videoTrack() !== null) { // the window to the film's size, as IINA's settings say: on each film, those opened by hand, or never; its initial size and place the first time
            $timing = $set->get('resizeWindowTiming');
            [$w, $h] = $p->size();
            $this->ask('fitVideo', ['w' => $w, 'h' => $h, 'resize' => $timing === 0 || ($timing === 1 && $manual), 'ratio' => [-1.0, 0.5, 1.0, 1.5, 2.0][$set->get('resizeWindowOption')] ?? 1.0,
                'physical' => $set->get('usePhysicalResolution'), 'geometry' => $this->placed ? null : $set->get('initialWindowSizePosition'), 'animate' => !$set->get('disableAnimations')]);
            $this->placed = true;
        }
        if (!$set->get('pauseWhenOpen')) { $p->play(); }
        if ($set->get('autoSearchOnlineSub') && !$url && $p->subtitleTracks() === [] && $p->duration() >= $set->get('autoSearchThreshold') * 60 && $this->openSubtitles !== null) { // IINA's: a film of no subtitles, long enough, searched for online
            $this->searchOnlineSubtitles();
        }
        if ($set->get('fullScreenWhenOpen')) { $this->ask('fullScreen', true); }
        return true;
    }

    /** The films played (IINA's playback history): the app's, PlaybackHistory::default(), unless set. @internal */
    public ?PlaybackHistory $playbackHistory = null;

    /** @var array<string, mixed> what to ask of the window at the next frame (Runtime::askWindow), asked between frames */
    private array $asks = [];

    /** Asks the window for $what at the next frame (one between frames would be forgotten at the next's start). */
    private function ask(string $what, mixed $value): void
    {
        $this->asks[$what] = $value;
        $this->changed();
    }

    private function history(): PlaybackHistory
    {
        return $this->playbackHistory ??= PlaybackHistory::default();
    }

    /** Seconds before an audio file's end the next is opened, for gapless audio. */
    private const AHEAD = 5.0;
    /** @var ?array{0: string, 1: ?Player} the next file of the playlist opened before its turn (its path, and its player, null where it failed: it fails again in its turn, told then) */
    private ?array $ahead = null;

    /**
     * IINA's gapless audio, as near as there is: AHEAD seconds before the
     * end of an audio file that the next follows, the next audio file
     * opened (read, its first sound decoded and queued), so that it plays
     * the moment this one ends.
     */
    private function openAhead(): void
    {
        $p = $this->player; $l = $this->playlist; $set = $this->settings();
        if ($this->ahead !== null || $p === null || $l === null || $p->videoTrack() !== null || $set->get('gaplessAudio') === 0 || $this->loop === 'file'
            || !($set->get('playlistAutoPlayNext') || $this->loop === 'playlist') || $p->duration() - $p->time() > self::AHEAD) {
            return;
        }
        $files = $l->files();
        $next = $files[$l->index() + 1] ?? ($this->loop === 'playlist' ? ($files[0] ?? null) : null);
        if ($next === null || preg_match('~^https?://~i', $next) === 1 || !in_array(strtolower(pathinfo($next, PATHINFO_EXTENSION)), Playlist::AUDIO, true)) {
            return;
        }
        try {
            $this->ahead = [$next, new Player($next)];
        } catch (RuntimeException) {
            $this->ahead = [$next, null];
        }
    }

    /** Plays the next film of the playlist; false after the last, unless the playlist loops, which goes to the first. */
    public function next(): bool
    {
        $l = $this->playlist;
        $path = $l?->next() ?? ($this->loop === 'playlist' && $l !== null ? ($l->files()[0] ?? null) : null);
        return $path !== null && $this->openFile($path, false);
    }

    /** Plays the film before; false before the first, unless the playlist loops, which goes to the last. */
    public function previous(): bool
    {
        $l = $this->playlist;
        $path = $l?->previous() ?? ($this->loop === 'playlist' && $l !== null ? ($l->files()[$l->count() - 1] ?? null) : null);
        return $path !== null && $this->openFile($path, false);
    }

    /** The loop after the one there is, as IINA's button goes: off, the file, the playlist, off. */
    public function nextLoop(): void
    {
        $this->loop = match ($this->loop) { 'off' => 'file', 'file' => 'playlist', default => 'off' };
        $this->changed();
    }

    /**
     * Takes player $p, of a film the app opened itself (open() opens one):
     * the one playing paused and its place noted; its volume this state's,
     * a frame asked for when what shows of its time changes (look), at its
     * end (where the next film of the playlist plays) and when it fails.
     * Where it is is noted every NOTE_EVERY seconds, at a pause and when
     * another player comes, and forgotten at its end, with
     * MoviePlayerOptions::$resume.
     */
    public function setPlayer(Player $p): void
    {
        if ($this->player === $p) {
            return;
        }
        foreach ($this->off as $off) { $off(); }
        $first = $this->player === null;
        if ($this->player !== null) {
            if ($this->resume) { $this->note(); }
            $this->player->pause();
        }
        $this->pip = null; // the last one's stops with it
        $this->clearLoop();
        $this->pictureInPicture = false;
        $this->player = $p;
        $this->fresh = true;
        if (PictureInPicture::supported()) {
            $this->pip = new PictureInPicture($p, function (bool $on): void { // the window hides, or goes to the Dock, or stays, while the film plays in the system's, as IINA's settings say, and comes back with it
                if ($on === $this->pictureInPicture) { return; }
                $this->pictureInPicture = $on;
                $set = $this->settings();
                $rt = $this->runtime?->get();
                match ($set->get('windowBehaviorWhenPip')) {
                    1 => ($rt?->showWindow)?->__invoke(!$on),
                    2 => ($rt?->minimizeWindow)?->__invoke($on),
                    default => null,
                };
                if ($on && $set->get('pauseWhenPip') && $this->player?->playing()) { $this->player->pause(); $this->played(); } // IINA's "Automatically pause playback"
                $this->changed();
            });
        }
        $this->error = '';
        $this->autoMusicMode($p);
        $this->speedAt = log($p->rate() / 0.25, 64);
        if ($first && $this->settings()->get('enableInitialVolume')) { $this->volume = min($this->maxVolume(), $this->settings()->get('initialVolume') / 100); } // IINA's initial volume, of a new window's player
        $p->setVolume($this->volume);
        $this->applyAudio($p);
        $p->setEqualizer($this->equalizer);
        $p->setHdr($this->settings()->get('enableHdrSupport')); // IINA's HDR mode: HDR as HDR, else tone mapped
        if ($this->hardwareDecoding !== null) { $p->setHardwareDecoding($this->hardwareDecoding); } // the quick setting's, of this player and the next (mpv's hwdec stays as films change)
        $this->moved = microtime(true);
        $noted = 0.0;
        $this->off = [
            $p->on('time', function () use (&$noted) { // a frame only when what shows changed: none while the controls are hidden and the subtitles stay
                $look = $this->look();
                if ($look !== $this->drawn) { $this->drawn = $look; $this->changed(); }
                if ($this->resume && abs($this->player->time() - $noted) >= self::NOTE_EVERY) { $noted = $this->player->time(); $this->note(); }
                if ($this->nowPlaying && $this->player->chapter() !== $this->shownChapter) { $this->showNowPlaying(); }
                $this->openAhead();
            }),
            ...array_map(fn(string $e) => $p->on($e, function (): void { if ($this->nowPlaying) { $this->showNowPlaying(); } }), ['play', 'pause', 'rate', 'seek']),
            ...array_map(fn(string $e) => $p->on($e, function () use ($e): void { // IINA's "Always float on top while playing": on top as it plays, not as it pauses
                if ($this->settings()->get('alwaysFloatOnTop')) { $this->onTop = $e === 'play'; $this->changed(); }
            }), ['play', 'pause']),
            $p->on('ended', function () {
                if ($this->resume) { $this->positions()->forget($this->player->path()); } // watched to the end: from the start next time
                if ($this->loop === 'file') { $this->player->play(); $this->played(); return; } // from its start again
                if (($this->settings()->get('playlistAutoPlayNext') || $this->loop === 'playlist') && $this->next()) { return; } // the next film plays (IINA's "Play next item automatically")
                NowPlaying::end($this);
                if (!$this->settings()->get('keepOpenOnFileEnd')) { $this->ask('close', true); } // IINA's: the window goes, unless kept open
                $this->changed();
            }),
            $p->on('error', function (string $why) { $this->error = $why; $this->log("Error: $why", Logger::ERROR); $this->changed(); }),
            $p->on('buffering', function (bool $on): void { $this->log($on ? 'Paused for cache' : 'Cache ready', Logger::DEBUG); $this->changed(); }), // the indicator shows, and goes
        ];
        if ($this->nowPlaying) { $this->showNowPlaying(); }
        $this->makeThumbnails($p);
        $this->changed();
    }

    /** IINA's count of the seek bar's thumbnails less one, and the seconds at most between two made. */
    private const THUMBNAILS = 100, THUMBNAIL_EVERY = 0.02;

    /** The settings the thumbnails were made by (shown, their width, of remote drives), to make them again as they change. */
    private ?array $thumbsAsSet = null;

    /** Where the seek bar's thumbnails of films seen are kept: the app's, ThumbnailCache::default(), unless set. */
    public ?ThumbnailCache $thumbnailCache = null;

    /**
     * The thumbnails of $p's film: from the cache when it has them, else
     * made one at a time (a keyframe read and decoded, some 5 ms of the
     * main thread, as IINA's FFmpegController does on a thread of its own)
     * right after the window presents a frame, where the next has the most
     * time, or THUMBNAIL_EVERY seconds on when it draws none: at each
     * hundredth, the keyframe at or before it, one kept of each keyframe.
     * They are the state's once all are made, for the player still its,
     * and kept in the cache.
     */
    private function makeThumbnails(Player $p): void
    {
        $this->thumbnails = [];
        $set = $this->settings();
        if ($p->videoTrack() === null || $p->duration() <= 0 || $p->remote() || !$set->get('enableThumbnailPreview')) { return; } // none of a film over the network, as IINA's, nor where the settings say none
        if (!$set->get('enableThumbnailForRemoteFiles') && PHP_OS_FAMILY === 'Darwin' && !\MyPHP\Shell::isLocal($p->path())) { return; } // nor of one on a mounted remote drive, unless the settings say so
        $cache = ($this->thumbnailCache ?? ThumbnailCache::default())->withMax((int)$set->get('maxThumbnailPreviewCacheSize') << 20);
        $width = self::thumbnailWidth($set);
        if (($kept = $cache->get($p->path(), $width)) !== null) {
            $this->thumbnails = $kept;
            return;
        }
        $made = []; $jpegs = []; $i = 0;
        $next = null;
        $step = function () use ($p, $cache, $width, &$made, &$jpegs, &$i, &$next): void {
            if ($this->player !== $p) { $next = null; return; } // another film took its place
            $t = $p->thumbnail($p->duration() * $i / self::THUMBNAILS, $width);
            if ($t !== null && ($made === [] || $made[count($made) - 1]->time !== $t->time)) { $made[] = $t; $jpegs[] = ThumbnailCache::jpeg($t); }
            if (++$i <= self::THUMBNAILS) { $next(); return; }
            $next = null; // the closures let themselves go
            $this->thumbnails = $made;
            $cache->put($p->path(), $width, $made, $jpegs);
            $this->changed();
        };
        $next = function () use (&$step): void { // after the next frame presented, or the timer, whichever comes first
            $done = false;
            $once = function () use (&$done, &$step): void { if (!$done) { $done = true; $step(); } };
            $this->runtime?->get()?->afterFrame($once);
            App::after(self::THUMBNAIL_EVERY, $once);
        };
        $next();
    }

    /** The thumbnails' width in pixels: twice the settings' points (thumbnailWidth), for a screen of two pixels a point. */
    public static function thumbnailWidth(PlayerSettings $set): int
    {
        return 2 * (int)$set->get('thumbnailWidth');
    }

    /** The thumbnail for $seconds, as IINA's getThumbnail: the one before the first at or after it (the first for none before), else the last; null before they are made. */
    public function thumbnailAt(float $seconds): ?\MyPHP\Media\Thumbnail
    {
        $all = $this->thumbnails;
        if ($all === []) { return null; }
        foreach ($all as $i => $t) {
            if ($t->time >= $seconds) { return $all[max(0, $i - 1)]; }
        }
        return $all[count($all) - 1];
    }

    /**
     * Notes the window's runtime it is built in, to ask it for frames, and
     * whether it goes on from where films were left: the first build with
     * a player taken goes back there (more than 5 s in and before its last
     * 5), telling it; and whether the player keeps the display awake.
     * @internal Context::moviePlayer
     */
    public function attach(Runtime $rt, MoviePlayerOptions $o): void
    {
        $this->runtime = \WeakReference::create($rt);
        foreach ($this->asks as $what => $value) { $rt->askWindow($what, $value); }
        $this->asks = [];
        $set = $this->settings();
        $this->resume = $resume = $o->resume && $set->get('resumeLastPosition');
        if ($o->openSubtitlesKey !== '' && $this->openSubtitles === null) { $this->openSubtitles = new OpenSubtitles($o->openSubtitlesKey); }
        $p = $this->player;
        $p?->setAwakeDisplay(!$set->get('allowScreenSaverForAudio') || ($p->videoTrack() !== null && !$this->musicMode)); // IINA's: the screen saver kept off, or only sleep for audio (SleepPreventer)
        $p?->setKeepAwake($o->keepAwake && $set->get('preventScreenSaver'));
        $rt->windowListeners['video ' . spl_object_id($this)] ??= $this->windowEvent(...);
        $thumbs = [$set->get('enableThumbnailPreview'), self::thumbnailWidth($set), $set->get('enableThumbnailForRemoteFiles')];
        if ($p !== null && $this->thumbsAsSet !== null && $thumbs !== $this->thumbsAsSet) { $this->makeThumbnails($p); } // the settings of the thumbnails changed: made again
        $this->thumbsAsSet = $thumbs;
        $this->sleeping ??= \MyPHP\Power::onSuspend(function (): void { // IINA's "Pause when machine goes to sleep"
            if ($this->player?->playing() && $this->settings()->get('pauseWhenGoesToSleep')) { $this->player->pause(); $this->played(); $this->changed(); }
        });
        if (!self::$logSet) { self::$logSet = true; self::applyLog($set); } // the app's log as the settings say, once the first player shows
        $nowPlaying = $o->nowPlaying && $set->get('useMediaKeys'); // and as the settings say: IINA's "Use system media control"
        if ($nowPlaying !== $this->nowPlaying) {
            $this->nowPlaying = $nowPlaying;
            $nowPlaying ? $this->showNowPlaying() : NowPlaying::end($this);
        }
        if (!$this->fresh || $p === null) {
            return;
        }
        $this->fresh = false;
        if ($resume && ($at = $this->positions()->get($p->path())) !== null && $at > 5 && $at < $p->duration() - 5) {
            $p->seek($at);
            $this->say($this->t('videoResumedAt', ['time' => self::clock($at)]));
        }
    }

    /** Whether the player is in music mode (IINA's mini player, MoviePlayerMusic): toggleMusicMode(), or an audio file opened (autoSwitchToMusicMode). */
    public bool $musicMode = false;
    /** Whether music mode was switched by hand (IINA's overrideAutoSwitchToMusicMode): the films opened then leave it as it is. */
    private bool $musicByHand = false;
    /** Music mode's: whether its playlist and its picture (the album's art) show, and how high the playlist is, IINA's musicModeShowPlaylist and musicModeShowAlbumArt. @internal MoviePlayerMusic */
    public float $musicPlaylistHeight = 300;

    /** IINA's speeds of the control bar's arrows (AppData.availableSpeedValues), 1x in the middle. */
    public const SPEEDS = [0.03125, 0.0625, 0.125, 0.25, 0.5, 1, 2, 4, 8, 16, 32];
    /** @internal MoviePlayer: the control bar's arrows held (by node), and its bars of the time and of the volume as last built, which the wheel over them moves. */
    public array $arrowHeld = [];
    /** @internal MoviePlayer: the floating bar's drag: where it began, and how far it went, [x, y, dx, dy]. */
    public ?array $oscDrag = null;
    public ?Node $seekBar = null, $volumeBar = null;
    /** @internal MoviePlayer: when a single click on the picture acts, a double click's time after it (null for none waiting). */
    public ?float $singleClickAt = null;
    /** Whether the window was placed by the settings' initial size and position (once, as its first film opens). */
    private bool $placed = false;
    /** Whether the window floats on top (IINA's: its title bar's pin, or as it plays where the settings say alwaysFloatOnTop). */
    public bool $onTop = false;
    /** An arrow of the control bar held for speed: [right, when it was pressed, the speed's index before]. */
    private ?array $arrow = null;

    /**
     * An arrow of the control bar pressed ($right, else the left), as the
     * settings say it acts (IINA's arrowBtnAction): the speed a step faster,
     * or slower, from 1x if it went the other way (playing if paused); the
     * next film, or the one before; ten seconds on, or back.
     */
    public function arrowDown(bool $right): void
    {
        $p = $this->player;
        if ($p === null) { return; }
        match ($this->settings()->get('arrowBtnAction')) {
            1 => $right ? $this->next() : $this->previous(),
            2 => $this->seekBy($right ? 10 : -10),
            default => (function () use ($p, $right): void {
                $mid = intdiv(count(self::SPEEDS), 2);
                $at = $this->speedIndex();
                if ($right ? $at < $mid : $at > $mid) { $at = $mid; } // the other way: from 1x
                $this->arrow = [$right, microtime(true), $at];
                $this->setSpeed(self::SPEEDS[max(0, min(count(self::SPEEDS) - 1, $at + ($right ? 1 : -1)))]);
                if (!$p->playing()) { $p->play(); $this->played(); }
            })(),
        };
    }

    /** The arrow pressed for speed let go: held long (half a second, IINA's) from 1x, the speed goes back to 1x, as a press to look ahead; a click keeps it. */
    public function arrowUp(): void
    {
        if ($this->arrow === null) { return; }
        [, $at] = $this->arrow;
        $this->arrow = null;
        if (microtime(true) - $at >= 0.5 && abs($this->speedIndex() - intdiv(count(self::SPEEDS), 2)) === 1) { $this->setSpeed(1.0); }
    }

    /** The index in SPEEDS of the speed it plays at, or of the nearest. */
    private function speedIndex(): int
    {
        $rate = $this->player?->rate() ?? 1.0;
        $best = 0;
        foreach (self::SPEEDS as $i => $v) { if (abs(log($v) - log($rate)) < abs(log(self::SPEEDS[$best]) - log($rate))) { $best = $i; } }
        return $best;
    }

    /** Seconds the controls stay after the pointer last moved: the settings' (controlBarAutoHideTimeout), for ever where they do not hide (enableControlBarAutoHide). */
    public function linger(): float
    {
        $set = $this->settings();
        return $set->get('enableControlBarAutoHide') ? (float)$set->get('controlBarAutoHideTimeout') : INF;
    }

    /** Goes into music mode, or out of it (IINA's Enter/Exit Music Mode): by hand, so the next films opened leave it so. */
    public function toggleMusicMode(): void
    {
        $this->musicByHand = !$this->musicByHand;
        $this->musicMode = !$this->musicMode;
        if ($this->musicMode) { $this->sidebar = null; } // IINA's: the sidebars go
        $this->changed();
    }

    /** IINA's automatic switch, as a file opens: into music mode for audio (not over the network, not in full screen), out of it for video; not while switched by hand. */
    private function autoMusicMode(Player $p): void
    {
        if (!$this->settings()->get('autoSwitchToMusicMode') || $this->musicByHand) {
            return;
        }
        $audio = $p->videoTrack() === null && !$p->remote();
        if ($audio && !$this->musicMode && !($this->runtime?->get()?->fullScreen ?? false)) {
            $this->musicMode = true;
            $this->sidebar = null;
        } elseif (!$audio && $this->musicMode) {
            $this->musicMode = false;
        }
    }
    /** What it paused for (minimized, inactive), to play again when that ends, as IINA's; what stops listening to the system's sleep. */
    private string $pausedFor = '';
    private ?Closure $sleeping = null;

    /**
     * Its window's event $e, as IINA's settings have the player do: pause
     * as it is minimized or inactive (pauseWhenMinimized,
     * pauseWhenInactive), playing again as it comes back if it paused it;
     * play as it goes full screen, pause as it leaves (playWhenEnteringFullScreen,
     * pauseWhenLeavingFullScreen).
     */
    private function windowEvent(string $e): void
    {
        $p = $this->player;
        if ($p === null) { return; }
        $set = $this->settings();
        $pause = function (string $for) use ($p): void { if ($p->playing()) { $p->pause(); $this->pausedFor = $for; } };
        $resume = function (string $for) use ($p): void { if ($this->pausedFor === $for) { $p->play(); } $this->pausedFor = ''; };
        $pipOnMinimize = $set->get('togglePipByMinimizingWindow') && (!$set->get('togglePipByMinimizingWindowForVideoOnly') || $p->videoTrack() !== null);
        if ($e === 'minimize' && $pipOnMinimize && !$this->pictureInPicture && $this->pip?->possible()) { $this->pip->start(); } // IINA's: picture in picture as the window goes to the Dock
        if ($e === 'restore' && $pipOnMinimize && $this->pip?->active()) { $this->pip->stop(); } // and back as it comes back
        match ($e) {
            'minimize' => $set->get('pauseWhenMinimized') && !$pipOnMinimize ? $pause('minimize') : null,
            'restore' => $resume('minimize'),
            'blur' => $set->get('pauseWhenInactive') ? $pause('inactive') : null,
            'focus' => $resume('inactive'),
            'enterFullScreen' => $set->get('playWhenEnteringFullScreen') ? $p->play() : null,
            'leaveFullScreen' => $set->get('pauseWhenLeavingFullScreen') ? $p->pause() : null,
            default => null,
        };
        $this->played();
        $this->changed();
    }

    private function positions(): PlaybackPositions
    {
        return $this->positions ??= PlaybackPositions::default();
    }

    /** Notes where the film is, to go on from there next time. */
    public function note(): void
    {
        $p = $this->player;
        if ($p !== null && !$p->ended()) { $this->positions()->set($p->path(), $p->time()); }
    }

    /**
     * What the frame shows of the time: the second and the knob's point
     * while the controls or a message with the time show, and the
     * subtitles; the same look needs no frame.
     * @internal
     */
    public function look(): string
    {
        $p = $this->player;
        if ($p === null) { return ''; }
        $t = $p->time();
        $clock = $this->shown || microtime(true) < $this->message[2] ? (int)$t . '/' . (int)round($t / max($p->duration(), 0.001) * 344) : '';
        $chapter = $this->sidebar === 'playlist' ? (string)$p->chapter() : ''; // the chapters' tab marks the one playing
        $pictures = $this->subtitlesVisible ? implode(',', array_map('spl_object_id', $p->subtitlePictures())) : '';
        $secondary = $this->secondarySubtitlesVisible ? implode("\n", $p->secondarySubtitles()) : '';
        return $clock . "\0" . $chapter . "\0" . implode("\n", $this->subtitlesVisible ? $p->subtitles() : []) . "\0" . $pictures . "\0" . $secondary;
    }

    /**
     * Shows the player's film in the system's Now Playing, taking its
     * commands as IINA does: play, pause and the two, stop, the next and
     * the film before, 15 s on and back (to the time itself), the loop, a
     * rate, a place in the film.
     */
    private function showNowPlaying(): void
    {
        $p = $this->player;
        if ($p === null) { return; }
        $this->shownChapter = $p->chapter();
        $l = $this->playlist;
        NowPlaying::show($this, [ // of the state given, not this one held
            'play' => static function (self $s): void { if (!$s->player?->playing()) { $s->togglePause(); } },
            'pause' => static function (self $s): void { if ($s->player?->playing()) { $s->togglePause(); } },
            'toggle' => static fn(self $s) => $s->togglePause(),
            'stop' => static fn(self $s) => $s->stop(),
            'next' => static fn(self $s) => $s->next(),
            'previous' => static fn(self $s) => $s->previous(),
            'skipForward' => static fn(self $s, float $by) => $s->seekBy($by, true),
            'skipBackward' => static fn(self $s, float $by) => $s->seekBy(-$by, true),
            'loop' => static fn(self $s) => $s->nextLoop(),
            'rate' => static fn(self $s, float $rate) => $s->setSpeed($rate),
            'position' => static function (self $s, float $at): void { $s->player?->seek($at); $s->played(); $s->changed(); },
        ], $p->path(), $p->duration(), $p->time(), $p->rate(), !$p->playing(), $l?->index() ?? -1, $l?->count() ?? 0, $this->shownChapter, count($p->chapters()));
    }

    public function __destruct()
    {
        NowPlaying::end($this);
    }

    /** Asks the window for a frame. */
    private function changed(): void
    {
        $this->runtime?->get()?->changed();
    }

    /**
     * Shows $text at the top left, with the time ('time') or the volume
     * ('volume') under it, as IINA's messages, for the settings' time
     * (osdAutoHideTimeout); none while the settings turn the messages off
     * (enableOSD), or those of $kind ('fileStart', 'pause', 'seek', 'speed':
     * IINA's disableOSDFileStartMsg and the others).
     */
    public function say(string $text, string $under = '', string $kind = ''): void
    {
        $set = $this->settings();
        $off = ['fileStart' => 'disableOSDFileStartMsg', 'pause' => 'disableOSDPauseResumeMsgs', 'seek' => 'disableOSDSeekMsg', 'speed' => 'disableOSDSpeedMsg'][$kind] ?? null;
        if ($off !== null && $set->get($off)) {
            return;
        }
        $this->tell($text, $under, (float)$set->get('osdAutoHideTimeout'));
    }

    /** $text shown so for $seconds (INF: until another, as IINA's OSD of no auto-hide); none while the messages are off (enableOSD). */
    private function tell(string $text, string $under, float $seconds): void
    {
        if (!$this->settings()->get('enableOSD')) {
            return;
        }
        $this->message = [$text, $under, microtime(true) + $seconds];
        $this->changed();
    }

    /**
     * @internal MoviePlayer: IINA's Find Online Subtitles, of OpenSubtitles:
     * the film's subtitles in the settings' languages, else the system's
     * (those it has; English where none), found by its TMDB id as
     * agc_player finds them (tmdbSubtitles), else (none found, or no id) by
     * its file's hash and name, IINA's way (a stream's, by its title);
     * told as it goes; one is downloaded and loaded at once, more are shown
     * to choose from (onlineSubtitles). In a fiber of its own, the window
     * going on; nothing while one goes on.
     */
    public function searchOnlineSubtitles(): void
    {
        $p = $this->player;
        $os = $this->openSubtitles;
        if ($p === null || $os === null || $this->searchingOnline) {
            return;
        }
        if (App::running() && \Fiber::getCurrent() === null) {
            $this->searchingOnline = true;
            App::go(function (): void { $this->searchingOnline = false; $this->searchOnlineSubtitles(); });
            return;
        }
        $this->searchingOnline = true;
        $this->onlineSubtitles = null; $this->onlineChosen = [];
        $this->tell($this->t('videoFindingOnline'), '', INF);
        try {
            $this->logInFromSettings($os);
            $preferred = $this->settings()->get('subLang');
            $languages = OpenSubtitles::languagesOf($preferred !== [] ? $preferred : App::preferredLanguages(), $os->languages()); // the settings', else the system's
            $local = !$p->remote();
            $found = $this->tmdbSubtitles($os, $languages, $local ? new MetaVideo(basename($p->path()), basename(dirname($p->path()))) : new MetaVideo($p->title()));
            if ($found === []) {
                $found = $os->search($languages, $local ? OpenSubtitles::hash($p->path()) : null, $local ? pathinfo($p->path(), PATHINFO_FILENAME) : $p->title());
            }
            if ($found === []) {
                $this->say($this->t('videoSubtitlesNotFound'));
            } elseif (count($found) === 1) {
                $this->searchingOnline = false;
                $this->onlineSubtitles = $found;
                $this->downloadOnlineSubtitles([0]);
                return;
            } else {
                $this->onlineSubtitles = $found;
                $this->tell($this->t('videoSubtitlesFound', ['count' => count($found)]), '', INF); // over the list to choose from
            }
        } catch (RuntimeException $e) {
            $this->failedOnline($e);
        }
        $this->searchingOnline = false;
        $this->changed();
    }

    /**
     * The subtitles in $languages of the film (or the show's episode) $meta
     * tells of (its file's name), as agc_player looks them up: its TMDB id,
     * written in the name, else TMDB's first of its name (the Chinese one,
     * then the English) and year, then OpenSubtitles' of that id (a show's,
     * of its season and episode); none for a name TMDB finds nothing of.
     * @param list<string> $languages
     * @return list<array{file: int, name: string, language: string, year: int, fps: float, downloads: int, uploaded: string}>
     */
    private function tmdbSubtitles(OpenSubtitles $os, array $languages, MetaVideo $meta): array
    {
        $id = $meta->tmdbId;
        foreach (array_unique(array_filter([trim($meta->cnName ?? ''), trim($meta->enName ?? '')], fn($n) => $n !== '')) as $name) {
            $id ??= ($this->tmdb ??= new Tmdb())->find($name, $meta->year, $meta->tv); // the next name only while none is found
        }
        return $id === null ? [] : $os->searchTmdb($languages, $id, $meta->tv, $meta->season, $meta->episode);
    }

    /** @internal MoviePlayer: the subtitles found online at places $chosen downloaded and loaded, told (IINA's Download of its list); in a fiber of its own. @param list<int> $chosen */
    public function downloadOnlineSubtitles(array $chosen): void
    {
        $found = $this->onlineSubtitles;
        $os = $this->openSubtitles;
        if ($found === null || $os === null || $chosen === [] || $this->searchingOnline) {
            return;
        }
        if (App::running() && \Fiber::getCurrent() === null) {
            $this->searchingOnline = true;
            App::go(function () use ($chosen): void { $this->searchingOnline = false; $this->downloadOnlineSubtitles($chosen); });
            return;
        }
        $this->searchingOnline = true;
        $this->onlineSubtitles = null; $this->onlineChosen = [];
        $this->tell($this->t('videoSubtitlesDownloading', ['count' => count($chosen)]), '', INF);
        try {
            $dir = sys_get_temp_dir() . '/myphp-subtitles';
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new RuntimeException("cannot make $dir");
            }
            $names = [];
            foreach ($chosen as $i) {
                [$name, $data] = $os->download($found[$i]['file']);
                $path = "$dir/[$i]" . basename($name); // IINA's: "[index]name", in the temporary folder
                if (file_put_contents($path, $data) === false) {
                    throw new RuntimeException("cannot write $path");
                }
                $this->loadSubtitles($path);
                $names[] = basename($name);
            }
            $this->say($this->t('videoSubtitlesDownloaded'), implode("\n", $names));
        } catch (RuntimeException $e) {
            $this->failedOnline($e);
        }
        $this->searchingOnline = false;
        $this->changed();
    }

    /** The account of the settings logged in, once (IINA's login() before a search): its password the Keychain's; none stored, none. */
    private function logInFromSettings(OpenSubtitles $os): void
    {
        $user = $this->settings()->get('openSubUsername');
        if ($user === '' || $os->loggedIn()) {
            return;
        }
        try {
            $password = Secrets::get(self::PASSWORD . $user);
        } catch (SecretNotFound) {
            return; // as IINA's: an account of no password stored searches as none
        }
        try {
            $os->login($user, $password);
        } catch (OpenSubtitlesQuota $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException($this->t('videoCannotLogin') . ': ' . $e->getMessage(), 0, $e);
        }
    }

    /** Whether the settings were given the app's log. */
    private static bool $logSet = false;

    /** The app's log (Logger) as IINA's settings say, with the advanced settings on (which hold them): their log level, else the app's kind's; written to its file with logging on. */
    private static function applyLog(PlayerSettings $set): void
    {
        Logger::setLevel($set->get('enableAdvancedSettings') ? $set->get('logLevel') : Logger::defaultLevel());
        Logger::logToFile($set->get('enableAdvancedSettings') && $set->get('enableLogging'));
    }

    /** The number of the players made, and this one's: its log's subsystem, "player0" and on, as IINA's. */
    private static int $players = 0;
    private ?int $number = null;

    /** Logs $message as this player's (Logger, its subsystem IINA's "player<n>"). */
    private function log(string $message, int $level = Logger::DEBUG): void
    {
        $this->number ??= self::$players++;
        Logger::log($level, $message, "player{$this->number}");
    }

    /** @internal MoviePlayerSettingsPages: forgets where all films were left (IINA's Clear Saved Playback Progress). */
    public function clearPositions(): void
    {
        $this->positions()->clear();
    }

    /** @internal MoviePlayerSettingsPages: forgets the films played and the recent ones (IINA's Clear Playback History). */
    public function clearHistory(): void
    {
        $this->history()->clear();
        if (App::running()) { App::clearRecentDocuments(); }
    }

    /** @internal MoviePlayerSettingsPages: the thumbnails kept, their bytes; forgotten where $clear (IINA's Clear Thumbnail Cache). */
    public function thumbnailBytes(bool $clear = false): int
    {
        $cache = $this->thumbnailCache ?? ThumbnailCache::default();
        if ($clear) { $cache->clear(); }
        return $cache->size();
    }

    /** @internal MoviePlayerSettings: the settings of IINA's Settings window the player has. */
    public function settings(): PlayerSettings
    {
        return $this->playerSettings ??= PlayerSettings::default();
    }

    /**
     * @internal MoviePlayerSettings: logs in to OpenSubtitles as $user (IINA's
     * legacy Login of its Subtitles pane): the account tried, then its user kept in the
     * settings and its password in the Keychain; what fails told by
     * $failed. In a fiber of its own ($loggingIn meanwhile).
     * @param \Closure(string): void $failed
     */
    public function logInOpenSubtitles(string $user, string $password, \Closure $failed): void
    {
        $os = $this->openSubtitles;
        if ($os === null || $this->loggingIn) {
            return;
        }
        if (App::running() && \Fiber::getCurrent() === null) {
            $this->loggingIn = true;
            App::go(function () use ($user, $password, $failed): void { $this->loggingIn = false; $this->logInOpenSubtitles($user, $password, $failed); });
            return;
        }
        $this->loggingIn = true;
        $this->changed();
        try {
            $os->logout(); // another account's session, if one is
            $os->login($user, $password);
            Secrets::set(self::PASSWORD . $user, $password);
            $this->settings()->set('openSubUsername', $user);
        } catch (RuntimeException $e) {
            $failed($e->getMessage());
        } finally {
            $this->loggingIn = false;
            $this->changed();
        }
    }

    /** @internal MoviePlayerSettings: the account logged out (IINA's Logout: its user forgotten; here its password too), its session ended. */
    public function logOutOpenSubtitles(): void
    {
        $user = $this->settings()->get('openSubUsername');
        if ($user === '') {
            return;
        }
        $this->settings()->set('openSubUsername', '');
        try { Secrets::delete(self::PASSWORD . $user); } catch (SecretNotFound) {}
        $os = $this->openSubtitles;
        if ($os !== null && $os->loggedIn()) {
            if (App::running() && \Fiber::getCurrent() === null) {
                App::go(function () use ($os): void { $this->endSession($os); });
            } else {
                $this->endSession($os);
            }
        }
        $this->changed();
    }

    /** OpenSubtitles' session ended; what fails is not the user's (the session lapses), only the log's. */
    private function endSession(OpenSubtitles $os): void
    {
        try {
            $os->logout();
        } catch (RuntimeException $e) {
            Logger::warn("logout: {$e->getMessage()}", 'opensub');
        }
    }

    /**
     * @internal MoviePlayerSettingsPages: sets setting $key of the player's
     * settings to $value (PlayerSettings::set), what fails to be written
     * shown as the error; the hardware decoder's that of the films opened
     * next (Player::$hardwareDecoding), the quick setting of those playing
     * staying.
     */
    public function setSetting(string $key, mixed $value): void
    {
        try {
            $this->settings()->set($key, $value);
            if ($key === 'hardwareDecoder') { Player::$hardwareDecoding = $value !== 0; }
            if (in_array($key, ['enableAdvancedSettings', 'enableLogging', 'logLevel'], true)) { self::applyLog($this->settings()); }
            if ($key === 'maxVolume' && $this->volume > $this->maxVolume()) { $this->setVolume($this->maxVolume()); } // as mpv's volume-max lowers it
            if (in_array($key, ['audioDevice', 'replayGain', 'replayGainPreamp', 'replayGainClip', 'replayGainFallback'], true) && $this->player !== null) { $this->applyAudio($this->player); } // as mpv's options set, at once
            if ($key === 'thumbnailWidth') { ($this->thumbnailCache ?? ThumbnailCache::default())->clear(); } // IINA's: the thumbnails kept go, the players make theirs again (attach)
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
        }
        $this->changed();
    }

    /** @internal MoviePlayer: the list of subtitles found online gone, none chosen (IINA's Cancel). */
    public function cancelOnlineSubtitles(): void
    {
        $this->onlineSubtitles = null; $this->onlineChosen = [];
        $this->say($this->t('videoCanceled'));
    }

    /** What failed finding or downloading subtitles online, told: the downloads spent as IINA tells it, the rest as the error. */
    private function failedOnline(RuntimeException $e): void
    {
        if ($e instanceof OpenSubtitlesQuota) {
            $this->tell($this->t('videoSubtitlesQuota'), $e->reset === null ? $this->t('videoSubtitlesQuotaLater') : $this->t('videoSubtitlesQuotaAfter', ['time' => date('H:i', $e->reset)]), 5.0);
            return;
        }
        $this->message = ['', '', 0.0];
        $this->error = $e->getMessage();
    }

    /** The strings of the player's controls and messages, in the app's locale. */
    private function t(string $key, array $args = []): string
    {
        return \MyPHP\L10n::in(\MyPHP\App::locale())->get($key, $args);
    }

    public function togglePause(): void
    {
        $p = $this->player;
        if ($p === null) { return; }
        $p->playing() ? $p->pause() : $p->play();
        $this->played();
        if ($this->resume && !$p->playing()) { $this->note(); }
        $this->say($this->t($p->playing() ? 'videoResume' : 'videoPause'), 'time', 'pause');
    }

    public function stop(): void
    {
        $this->player?->pause();
        $this->player?->seek(0);
        $this->played();
        $this->say($this->t('videoStop'));
    }

    /** Goes $s seconds on (back when negative): to the keyframe nearest that way, as IINA's arrow keys, buttons and wheel; $exact to the time itself. */
    public function seekBy(float $s, bool $exact = false): void
    {
        $p = $this->player;
        if ($p === null) { return; }
        $this->log(sprintf('Seek %+.2fs%s', $s, $exact ? ', exact' : ''), Logger::VERBOSE);
        $this->seekedFrom = $p->time();
        $p->seek($p->time() + $s, $exact);
        $this->played();
        $this->say(self::clock($p->time()) . ' / ' . self::clock($p->duration()), '', 'seek');
    }

    /**
     * The A-B loop as IINA's (mpv's ab-loop): A where it plays, then B
     * there, then none, told as it goes. With both it plays from A again
     * each time it plays past B (at once when B is where it is): A and B
     * the earlier and the later of the two.
     */
    public function abLoop(): void
    {
        $p = $this->player;
        if ($p === null) { return; }
        if ($this->loopA === null) {
            $this->loopA = $p->time();
            $this->say($this->t('videoAbLoopA'), 'time');
        } elseif ($this->loopB === null) {
            $this->setLoop($this->loopA, $p->time());
            $this->say($this->t('videoAbLoopB'), 'time');
        } else {
            $this->clearLoop();
            $this->say($this->t('videoAbLoopClear'));
        }
    }

    /**
     * Moves a point of the loop (a knob of the bar dragged, IINA's): 'a' or
     * 'b' to $seconds, told with where it is; B as the loop's end as ever.
     */
    public function moveLoopPoint(string $which, float $seconds): void
    {
        $p = $this->player;
        if ($p === null || $this->loopA === null || ($which === 'b' && $this->loopB === null)) { return; }
        $seconds = max(0.0, min($seconds, $p->duration()));
        if ($which === 'a') {
            $this->loopB === null ? $this->loopA = $seconds : $this->setLoop($seconds, $this->loopB, false);
        } else {
            $this->setLoop($this->loopA, $seconds, false);
        }
        $this->say($this->t($which === 'a' ? 'videoAbLoopA' : 'videoAbLoopB'), self::clock($seconds) . ' / ' . self::clock($p->duration()));
    }

    /** Loops from $a to $b (the earlier first), from A again now when $jump and it is at B or past it. */
    private function setLoop(float $a, float $b, bool $jump = true): void
    {
        $p = $this->player;
        ($this->loopOff)?->__invoke();
        [$this->loopA, $this->loopB] = [min($a, $b), max($a, $b)];
        $this->loopOff = $p->at($this->loopB, function (): void {
            $this->player?->seek($this->loopA ?? 0.0);
            $this->played();
        });
        if ($jump && $p->time() >= $this->loopB - 1e-3) { $p->seek($this->loopA); $this->played(); }
        $this->changed();
    }

    private function clearLoop(): void
    {
        ($this->loopOff)?->__invoke();
        $this->loopOff = null;
        $this->loopA = $this->loopB = null;
        $this->changed();
    }

    /** @internal MoviePlayer, MoviePlayerCommands: the subtitles' scale (mpv's sub-scale, 1 the settings' size), their vertical position (sub-pos, null the settings'), the ASS override level asked (sub-ass-override, null the settings'). */
    public float $subScale = 1.0;
    /** @internal MoviePlayerLiveText: the analysis of the frame paused at (its time, the job, the words, the choice, all shown, where a drag began, the words' boxes), null for none. @var ?array<string, mixed> */
    public ?array $liveText = null;
    public ?float $subPos = null;
    public ?int $assOverride = null;
    /** Where the last seek went from, for revert-seek (mpv's), null for none. */
    private ?float $seekedFrom = null;

    /** Goes back to where the last seek went from, and the seek before it undone the same way (mpv's revert-seek). */
    public function revertSeek(): void
    {
        $p = $this->player;
        if ($p === null || $this->seekedFrom === null) { return; }
        $this->seekBy($this->seekedFrom - $p->time(), true);
    }

    /** Goes to the subtitle line $skip lines on (back when negative), as mpv's sub-seek; or, where $delay (sub-step), the subtitles' delay changed so that it shows now. */
    public function subSeek(int $skip, bool $delay = false): void
    {
        $p = $this->player;
        $to = $p?->subtitleStart($skip);
        if ($to === null) { return; }
        $delay ? $this->setSubtitleDelay($p->subtitleDelay() + $p->time() - $to) : $this->seekBy($to - $p->time(), true);
    }

    /** The subtitles' scale (mpv's sub-scale, 0 to 100), told as IINA's OSD tells it. */
    public function setSubScale(float $k): void
    {
        $this->subScale = max(0.0, min(100.0, round($k, 2)));
        $this->say($this->t('videoSubScaleTo', ['scale' => self::number($this->subScale)]));
    }

    /** The subtitles' vertical position (mpv's sub-pos, 0 to 150), told. */
    public function setSubPos(float $pos): void
    {
        $this->subPos = max(0.0, min(150.0, round($pos)));
        $this->say($this->t('videoSubPosTo', ['pos' => self::number($this->subPos)]));
    }

    /** Loops the file, the playlist or nothing ('file', 'playlist', 'off'), told as IINA's. */
    public function setLooping(string $loop): void
    {
        $this->loop = $loop;
        $this->say($this->t(['file' => 'videoFileLoop', 'playlist' => 'videoPlaylistLoop'][$loop] ?? 'videoNoLoop'));
    }

    /** The window to $k times the film's size (mpv's window-scale); to the screen's for a negative $k (IINA's fit-to-screen). */
    public function windowScale(float $k): void
    {
        $p = $this->player;
        if ($p === null || $p->videoTrack() === null) { return; }
        [$w, $h] = $p->size();
        $this->ask('fitVideo', ['w' => $w, 'h' => $h, 'resize' => true, 'ratio' => $k, 'physical' => false, 'geometry' => null, 'animate' => !$this->settings()->get('disableAnimations')]);
    }

    /** The player's film gone (mpv's quit: the window closes), where it was noted first ($watchLater, quit-watch-later). */
    public function quit(bool $watchLater = false): void
    {
        if ($watchLater || $this->resume) { $this->note(); }
        $this->ask('close', true);
    }

    /** Takes all but the film playing out of the playlist (mpv's playlist-clear). */
    public function clearPlaylist(): void
    {
        $l = $this->playlist;
        if ($l === null) { return; }
        for ($i = $l->count() - 1; $i >= 0; $i--) { if ($i !== $l->index()) { $l->remove($i); } }
        $this->changed();
    }

    /** Takes the film playing out of the playlist and plays the next (mpv's playlist-remove current). */
    public function removeFromPlaylist(): void
    {
        $l = $this->playlist;
        if ($l === null || $l->index() < 0) { return; }
        $i = $l->index();
        $next = $l->files()[$i + 1] ?? null;
        $l->remove($i);
        $next !== null ? $this->openFile($next, false) : $this->changed();
    }

    /** Moves the film playing to the Trash, or deletes it ($hard), as IINA's, asked first; the next plays. */
    public function deleteCurrentFile(bool $hard): void
    {
        $p = $this->player;
        if ($p === null || $p->remote()) { return; }
        $path = $p->path();
        $r = \MyPHP\Dialog::message(new \MyPHP\MessageOptions(parent: \MyPHP\Window::focused(), type: 'warning', message: $this->t('iinaAlertDeleteFileTitle'),
            detail: $this->t('iinaAlertDeleteFileMessage'), buttons: [$this->t('ok'), $this->t('cancel')]));
        if ($r->response !== 0) { return; }
        $this->removeFromPlaylist();
        $hard ? @unlink($path) : \MyPHP\Shell::trashItem($path);
    }

    /** Saves the playlist as an M3U8 where the user says (IINA's save-playlist). */
    public function savePlaylist(): void
    {
        $l = $this->playlist;
        if ($l === null) { return; }
        $to = \MyPHP\Dialog::save(new \MyPHP\SaveDialogOptions(parent: \MyPHP\Window::focused(), defaultPath: 'playlist.m3u8'));
        if ($to === '') { return; }
        if (@file_put_contents($to, "#EXTM3U\n" . implode("\n", $l->files()) . "\n") === false) { $this->error = "cannot write $to"; $this->changed(); }
    }

    /** Pauses and shows the next frame, or the one before ($back), as IINA's frame-step and frame-back-step. */
    public function frameStep(bool $back = false): void
    {
        $this->player?->step($back ? -1 : 1);
        $this->played();
    }

    /**
     * Takes a screenshot of the frame showing (Player::snapshot), as IINA's
     * settings say: saved (screenshotSaveToFile) in their folder
     * (screenShotFolder), made if it is not there, in their format
     * (screenShotFormat), named as IINA's (mpv's %F-%n): the film's name and
     * the first number of four digits not taken, movie-0001.png; copied to
     * the clipboard (screenshotCopyToClipboard); its preview shown
     * (screenshotShowPreview), else "Screenshot captured" said. Neither
     * saved nor copied, nothing is taken, as IINA's. Returns the file's
     * path, null before a frame shows or where it is not saved.
     */
    public function screenshot(): ?string
    {
        $set = $this->settings();
        [$save, $copy] = [$set->get('screenshotSaveToFile'), $set->get('screenshotCopyToClipboard')];
        $p = $this->player;
        $png = $save || $copy ? $p?->snapshot() : null;
        if ($png === null) { return null; }
        if ($set->get('screenShotIncludeSubtitle')) { $png = $this->withSubtitles($png); }
        $path = null;
        if ($save) {
            $folder = $set->get('screenShotFolder');
            $dir = str_starts_with($folder, '~/') ? getenv('HOME') . substr($folder, 1) : $folder;
            if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new RuntimeException("myphp: no folder $dir for screenshots");
            }
            $format = $set->get('screenShotFormat');
            $ext = \MyPHP\Media\Screenshot::FORMATS[$format][0] ?? 'png';
            $name = pathinfo($p->path(), PATHINFO_FILENAME);
            for ($n = 1; file_exists($path = sprintf('%s/%s-%04d.%s', $dir, $name, $n, $ext)); $n++) {}
            if (file_put_contents($path, \MyPHP\Media\Screenshot::encode($png, $format)) === false) {
                throw new RuntimeException("myphp: the screenshot could not be written to $path");
            }
        }
        if ($copy) { \MyPHP\Clipboard::writeImage($png); }
        if ($set->get('screenshotShowPreview')) {
            $this->shot = ['image' => Image::fromBytes($png, 600), 'path' => $path];
            $this->tell($this->t('videoScreenshot'), '', 5.0); // IINA's: its preview under the message, 5 s
        } else {
            $this->say($this->t('videoScreenshot'));
        }
        return $path;
    }

    /**
     * The frame $png with the subtitles over it as the window shows them
     * (IINA's "Include subtitles", mpv's screenshot of subtitles): the
     * window's drawing of them, of the picture's place, scaled to the
     * frame; the frame as it is where none show (none on, or no window).
     */
    private function withSubtitles(string $png): string
    {
        $rt = $this->runtime?->get();
        $under = $rt?->underFrame === null ? null : ($rt->underFrame)();
        if ($under === null) {
            return $png; // nothing drawn over the picture
        }
        [$bgra, $bw, $bh, $k] = $under;
        foreach ($rt->videoPlaces as $place) {
            if ($place['view'] !== $this->player) { continue; }
            [$fx, $fy, $fw, $fh] = $place['frame'];
            [$vw, $vh] = $this->player->size();
            if ($vw <= 0 || $vh <= 0) { break; }
            $s = min($fw / $vw, $fh / $vh); // the picture, fit in its place as the layer shows it
            [$pw, $ph] = [$vw * $s, $vh * $s];
            $crop = [(int)round(($fx + ($fw - $pw) / 2) * $k), (int)round(($fy + ($fh - $ph) / 2) * $k), (int)round($pw * $k), (int)round($ph * $k)];
            return \MyPHP\Native\ImageIO::overlay($png, $bgra, $bw, $bh, $crop);
        }
        return $png;
    }

    /** @internal MoviePlayer: the screenshot previewed deleted (IINA's DELETE), or opened (EDIT), or shown in the Finder (REVEAL); the preview gone. */
    public function shotAction(string $action): void
    {
        $path = $this->shot['path'] ?? null;
        if ($path === null) { return; }
        match ($action) {
            'delete' => @unlink($path),
            'edit' => \MyPHP\Shell::openPath($path),
            'reveal' => \MyPHP\Shell::showItemInFolder($path),
        };
        $this->shot = null;
        $this->message[2] = 0.0;
        $this->changed();
    }

    /** Goes to the start of the $i-th chapter of the player's and plays, as IINA's playChapter. */
    public function playChapter(int $i): void
    {
        $p = $this->player;
        $ch = $p?->chapters()[$i] ?? null;
        if ($ch === null) { return; }
        $p->seek($ch->start);
        $p->play();
        $this->played();
    }

    /** Shows where it plays, as IINA's show-progress. */
    public function showProgress(): void
    {
        $p = $this->player;
        if ($p !== null) { $this->say(self::clock($p->time()) . ' / ' . self::clock($p->duration())); }
    }

    /** The volume's most, 1 to 10: IINA's maximum volume (100% to 1000%), the sliders' end. */
    public function maxVolume(): float
    {
        return max(1.0, min(10.0, $this->settings()->get('maxVolume') / 100));
    }

    /** The settings' sound, of player $p: the output device (IINA's preferred), and ReplayGain's gain. */
    private function applyAudio(Player $p): void
    {
        $set = $this->settings();
        $device = $set->get('audioDevice');
        $p->setAudioDevice($device === 'auto' ? null : preg_replace('~^coreaudio/~', '', $device)); // mpv's names of IINA's setting, the system's unique ID after its driver
        $p->setReplayGain($set->get('replayGain'), (float)$set->get('replayGainPreamp'), $set->get('replayGainClip'), (float)$set->get('replayGainFallback'));
    }

    public function setVolume(float $v): void
    {
        $this->volume = max(0.0, min($this->maxVolume(), $v));
        $this->player?->setVolume($this->volume);
        if ($this->player?->muted()) { $this->player->setMuted(false); }
        $this->say($this->t('videoVolume', ['volume' => (int)round($this->volume * 100)]), 'volume');
    }

    public function toggleMute(): void
    {
        $p = $this->player;
        if ($p === null) { return; }
        $p->setMuted(!$p->muted());
        $this->say($this->t($p->muted() ? 'videoMute' : 'videoUnmute'));
    }

    /** Plays at $rate, 1/32x to 32x as IINA's arrows go (its slider 0.25x to 16x). */
    public function setSpeed(float $rate): void
    {
        $rate = max(self::SPEEDS[0], min(self::SPEEDS[count(self::SPEEDS) - 1], $rate < 0.25 ? $rate : round($rate, 2)));
        $this->player?->setRate($rate);
        $this->played();
        $this->speedAt = max(0.0, min(1.0, log($rate / 0.25, 64)));
        $this->say($this->t('videoSpeedTo', ['speed' => self::number($rate)]), '', 'speed');
    }

    public function setSubtitleDelay(float $s): void
    {
        $d = max(-5.0, min(5.0, round($s, 1)));
        $this->player?->setSubtitleDelay($d);
        $this->say($this->delay('videoSubDelay', $d));
    }

    public function setAudioDelay(float $s): void
    {
        $d = max(-5.0, min(5.0, round($s, 1)));
        $this->player?->setAudioDelay($d);
        $this->say($this->delay('videoAudioDelay', $d));
    }

    private function delay(string $key, float $d): string
    {
        return $d == 0 ? $this->t("{$key}None") : $this->t($d > 0 ? "{$key}Later" : "{$key}Earlier", ['seconds' => self::number(abs($d))]);
    }

    /** The picture's aspect ratio: the $i-th of ASPECTS. */
    public function setAspect(int $i): void
    {
        $this->player?->setAspectRatio(self::ASPECTS[$i][1]);
        $this->say($this->t('videoAspectTo', ['ratio' => self::ASPECTS[$i][0] ?? $this->t('videoDefault')]));
    }

    /** The crop: the $i-th of ASPECTS, the first none. */
    public function setCrop(int $i): void
    {
        $this->player?->setCrop(self::ASPECTS[$i][1]);
        $this->say($this->t('videoCropTo', ['ratio' => self::ASPECTS[$i][0] ?? $this->t('videoNone')]));
    }

    /** The rotation: the $i-th of ROTATIONS. */
    public function setRotation(int $i): void
    {
        $this->player?->setRotation(self::ROTATIONS[$i]);
        $this->say($this->t('videoRotateTo', ['degrees' => self::ROTATIONS[$i]]));
    }

    public function toggleSubtitles(): void
    {
        $this->subtitlesVisible = !$this->subtitlesVisible;
        $this->say($this->t($this->subtitlesVisible ? 'videoSubVisible' : 'videoSubHidden'));
    }

    /** Shows or hides the secondary subtitles (IINA's switch of the second subtitle), quietly as IINA's quick settings do. */
    public function toggleSecondarySubtitles(): void
    {
        $this->secondarySubtitlesVisible = !$this->secondarySubtitlesVisible;
        $this->changed();
    }

    /** Shows the next subtitle track, after the last none. */
    public function cycleSubtitles(): void
    {
        $p = $this->player;
        $p?->setSubtitleTrack(self::nextTrack($p->subtitleTracks(), $p->subtitleTrack()));
    }

    /** Plays the next audio track the system decodes, round to the first. */
    public function cycleAudio(): void
    {
        $p = $this->player;
        if ($p === null) { return; }
        $tracks = array_values(array_filter($p->audioTracks(), [Player::class, 'playable']));
        $next = self::nextTrack($tracks, $p->audioTrack()) ?? $tracks[0] ?? null;
        if ($next !== null) { $p->setAudioTrack($next); }
    }

    /** Shows the film in the system's floating window, or back in this one; nothing where this Mac has no picture in picture, or before it can start (a moment after the film shows). */
    public function togglePictureInPicture(): void
    {
        if ($this->pip === null) { return; }
        if ($this->pip->active()) { $this->pip->stop(); } elseif ($this->pip->possible()) { $this->pip->start(); }
    }

    /** Whether picture in picture can start now. */
    public function pictureInPicturePossible(): bool
    {
        return $this->pip?->possible() ?? false;
    }

    /** Tells the picture in picture's window that the player played, paused or sought. */
    private function played(): void
    {
        $this->pip?->invalidate();
    }

    /** Loads subtitle file $path over the film and shows it; what fails shows as the error. */
    public function loadSubtitles(string $path): void
    {
        try {
            $this->player?->setSubtitleTrack($this->player->loadSubtitles($path));
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
        }
        $this->changed();
    }

    /**
     * Sets the equalizer's gains (Player::setEqualizer), of this player and
     * the next, as preset $preset names them ('' for Manual, IINA's when a
     * slider moves); what fails shows as the error.
     * @param list<float> $gains
     */
    public function setEqualizer(array $gains, string $preset = ''): void
    {
        $this->applyEqualizer($gains, $preset, '');
    }

    /** @internal MoviePlayer: decodes the video on the system's decoders or not, as IINA's quick setting, of this player and the next (Player::setHardwareDecoding). */
    public function setHardwareDecoding(bool $on): void
    {
        $this->hardwareDecoding = $on;
        $this->player?->setHardwareDecoding($on);
        $this->changed();
    }

    /** @internal MoviePlayer: the names of the profiles the user saved; what fails to read shows as the error @return list<string> */
    public function profileNames(): array
    {
        try {
            return array_map('strval', array_keys($this->profiles()->all()));
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
            return [];
        }
    }

    /** @internal MoviePlayer: the profile $name's gains, it the one chosen (IINA's pop-up, a profile of the user's chosen) */
    public function chooseProfile(string $name): void
    {
        $gains = null;
        $this->profiled(function () use ($name, &$gains): void { // by reference: read after
            $gains = $this->profiles()->all()[$name] ?? throw new RuntimeException("myphp: no equalizer profile \"$name\"");
        });
        if ($gains !== null) { $this->applyEqualizer($gains, '', $name); }
    }

    /** @internal MoviePlayer: the gains saved as the profile $name, it the one chosen (IINA's "Save the current EQ as a preset…") */
    public function saveProfile(string $name): void
    {
        $this->profiled(function () use ($name): void {
            $this->profiles()->set($name, $this->equalizer);
            [$this->equalizerPreset, $this->equalizerProfile] = ['', $name];
        });
    }

    /** @internal MoviePlayer: the profile chosen named $name (IINA's "Rename the current EQ preset…") */
    public function renameProfile(string $name): void
    {
        $this->profiled(function () use ($name): void {
            $this->profiles()->rename($this->equalizerProfile, $name);
            $this->equalizerProfile = $name;
        });
    }

    /** @internal MoviePlayer: the profile chosen forgotten, the gains as they are, Manual (IINA's "Remove the current EQ preset") */
    public function removeProfile(): void
    {
        $this->profiled(function (): void {
            $this->profiles()->remove($this->equalizerProfile);
            $this->equalizerProfile = '';
        });
    }

    /** Does $fn to the profiles; what fails to write shows as the error. */
    private function profiled(Closure $fn): void
    {
        try {
            $fn();
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
        }
        $this->changed();
    }

    private function profiles(): EqualizerProfiles
    {
        return $this->profiles ??= EqualizerProfiles::default();
    }

    /** @param list<float> $gains */
    private function applyEqualizer(array $gains, string $preset, string $profile): void
    {
        $this->equalizerDraft = null;
        try {
            $this->player?->setEqualizer($gains);
            $this->equalizer = array_map('floatval', array_values($gains));
            [$this->equalizerPreset, $this->equalizerProfile] = [$preset, $profile];
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
        }
        $this->changed();
    }

    /** Loads audio file $path beside the film (Player::loadAudio, IINA's Load External Audio), its first track playing; what fails shows as the error. */
    public function loadAudio(string $path): void
    {
        try {
            $this->player?->loadAudio($path);
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
        }
        $this->changed();
    }

    /** The track after $current in $tracks, null after the last. @param list<Track> $tracks */
    private static function nextTrack(array $tracks, ?Track $current): ?Track
    {
        $i = $current === null ? -1 : (int)array_search($current, $tracks, true);
        return $tracks[$i + 1] ?? null;
    }

    /** $s seconds as players show a time: 07:09, 1:58:37. */
    public static function clock(float $s): string
    {
        $s = (int)$s;
        return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s, 60) % 60, $s % 60) : sprintf('%02d:%02d', intdiv($s, 60), $s % 60);
    }

    /** $bytes as IINA's FloatingPointByteCountFormatter writes them: of the largest prefix they reach, binary (KiB, MiB) or decimal (KB, MB), two digits at most after the point: "12.5 Mi". */
    public static function byteCount(float $bytes, bool $binary): string
    {
        $prefixes = $binary ? ['' => 1, 'Ki' => 1024, 'Mi' => 1024 ** 2, 'Gi' => 1024 ** 3] : ['' => 1, 'K' => 1000, 'M' => 1000 ** 2, 'G' => 1000 ** 3];
        foreach (array_reverse($prefixes, true) as $p => $f) {
            if ($bytes >= $f) { return self::number($bytes / $f) . " $p"; }
        }
        return '0 ';
    }

    /** $v as IINA writes a number: no trailing zeros. */
    public static function number(float $v): string
    {
        return rtrim(rtrim(sprintf('%.2f', $v), '0'), '.');
    }
}
