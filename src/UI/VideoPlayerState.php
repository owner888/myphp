<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;
use MyPHP\App;
use MyPHP\Media\PlaybackPositions;
use MyPHP\Media\Playlist;
use MyPHP\Media\Player;
use MyPHP\Media\ThumbnailCache;
use MyPHP\Media\Track;
use MyPHP\Native\NowPlaying;
use MyPHP\Native\PictureInPicture;
use RuntimeException;

/**
 * A video player's state, kept by the app across builds of
 * Context::videoPlayer: the title it shows, the volume (which a player
 * built next takes), whether the subtitles show, the sidebar's tab, the
 * message showing, what failed. Its methods do what the controls and the
 * keys do, and tell it as IINA's messages do, for an app's own menus and
 * keys too; they act on the player it was last built with.
 */
final class VideoPlayerState
{
    /** Seconds the controls stay after the pointer last moved, and a message shows (IINA's). */
    public const LINGER = 2.5, MESSAGE = 1.0;
    /** IINA's aspect ratios, the crop's too: the label (null for "default", or "none" of a crop) and the ratio, null for the picture's own. */
    public const ASPECTS = [[null, null], ['4:3', 4 / 3], ['16:9', 16 / 9], ['16:10', 16 / 10], ['21:9', 21 / 9], ['5:4', 5 / 4]];
    public const ROTATIONS = [0, 90, 180, 270];

    /** What the title bar shows, a file's name. */
    public string $title = '';
    /** The volume, 0 to 1, of this player and the next. */
    public float $volume = 1.0;
    public bool $subtitlesVisible = true;
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
    /** Whether the player built last goes on from where it was left (VideoPlayerOptions::$resume), and shows in Now Playing ($nowPlaying). */
    private bool $resume = false, $nowPlaying = false;
    /** The chapter Now Playing shows, to show the next as it comes. */
    private int $shownChapter = -1;
    /** @var list<Closure> the player's listeners, removed for another player */
    private array $off = [];

    /** Where films were left (VideoPlayerOptions::$resume): the app's, PlaybackPositions::default(), unless set. */
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
    /** Where screenshots go (IINA's default), unless set. */
    public string $screenshots = '~/Pictures/Screenshots';
    /** Whether the film plays in picture in picture (togglePictureInPicture), the system's floating window. */
    public bool $pictureInPicture = false;
    /** The picture in picture of the player, where this Mac has it. */
    private ?PictureInPicture $pip = null;

    /**
     * Opens the film at $path and plays it: a player of it, the title its
     * name, the playlist the films of its folder unless it is in the one
     * there is. What fails to open shows as the error, the film playing
     * going on. Returns whether it opened.
     */
    public function open(string $path): bool
    {
        try {
            $p = new Player($path);
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
            $this->changed();
            return false;
        }
        if ($this->playlist === null || !$this->playlist->select($path)) {
            $this->playlist = Playlist::folder($path);
        }
        $this->chosen = $this->playlist->index();
        $this->title = basename($path);
        $this->setPlayer($p);
        $p->play();
        return true;
    }

    /** Plays the next film of the playlist; false after the last, unless the playlist loops, which goes to the first. */
    public function next(): bool
    {
        $l = $this->playlist;
        $path = $l?->next() ?? ($this->loop === 'playlist' && $l !== null ? ($l->files()[0] ?? null) : null);
        return $path !== null && $this->open($path);
    }

    /** Plays the film before; false before the first, unless the playlist loops, which goes to the last. */
    public function previous(): bool
    {
        $l = $this->playlist;
        $path = $l?->previous() ?? ($this->loop === 'playlist' && $l !== null ? ($l->files()[$l->count() - 1] ?? null) : null);
        return $path !== null && $this->open($path);
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
     * VideoPlayerOptions::$resume.
     */
    public function setPlayer(Player $p): void
    {
        if ($this->player === $p) {
            return;
        }
        foreach ($this->off as $off) { $off(); }
        if ($this->player !== null) {
            if ($this->resume) { $this->note(); }
            $this->player->pause();
        }
        $this->pip = null; // the last one's stops with it
        $this->pictureInPicture = false;
        $this->player = $p;
        $this->fresh = true;
        if (PictureInPicture::supported()) {
            $this->pip = new PictureInPicture($p, function (bool $on): void { // the window goes while the film plays in the system's, as IINA's, and comes back with it
                if ($on === $this->pictureInPicture) { return; }
                $this->pictureInPicture = $on;
                ($this->runtime?->get()?->showWindow)?->__invoke(!$on);
                $this->changed();
            });
        }
        $this->error = '';
        $this->speedAt = log($p->rate() / 0.25, 64);
        $p->setVolume($this->volume);
        $this->moved = microtime(true);
        $noted = 0.0;
        $this->off = [
            $p->on('time', function () use (&$noted) { // a frame only when what shows changed: none while the controls are hidden and the subtitles stay
                $look = $this->look();
                if ($look !== $this->drawn) { $this->drawn = $look; $this->changed(); }
                if ($this->resume && abs($this->player->time() - $noted) >= self::NOTE_EVERY) { $noted = $this->player->time(); $this->note(); }
                if ($this->nowPlaying && $this->player->chapter() !== $this->shownChapter) { $this->showNowPlaying(); }
            }),
            ...array_map(fn(string $e) => $p->on($e, function (): void { if ($this->nowPlaying) { $this->showNowPlaying(); } }), ['play', 'pause', 'rate', 'seek']),
            $p->on('ended', function () {
                if ($this->resume) { $this->positions()->forget($this->player->path()); } // watched to the end: from the start next time
                if ($this->loop === 'file') { $this->player->play(); $this->played(); return; } // from its start again
                if (!$this->next()) { NowPlaying::end($this); $this->changed(); } // the next film plays, else nothing does
            }),
            $p->on('error', function (string $why) { $this->error = $why; $this->changed(); }),
        ];
        if ($this->nowPlaying) { $this->showNowPlaying(); }
        $this->makeThumbnails($p);
        $this->changed();
    }

    /** IINA's count of the seek bar's thumbnails less one, their width in pixels (twice the 120 points they show at), and the seconds at most between two made. */
    private const THUMBNAILS = 100, THUMBNAIL_WIDTH = 240, THUMBNAIL_EVERY = 0.02;

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
        if ($p->videoTrack() === null || $p->duration() <= 0) { return; }
        $cache = $this->thumbnailCache ??= ThumbnailCache::default();
        if (($kept = $cache->get($p->path(), self::THUMBNAIL_WIDTH)) !== null) {
            $this->thumbnails = $kept;
            return;
        }
        $made = []; $jpegs = []; $i = 0;
        $next = null;
        $step = function () use ($p, $cache, &$made, &$jpegs, &$i, &$next): void {
            if ($this->player !== $p) { $next = null; return; } // another film took its place
            $t = $p->thumbnail($p->duration() * $i / self::THUMBNAILS, self::THUMBNAIL_WIDTH);
            if ($t !== null && ($made === [] || $made[count($made) - 1]->time !== $t->time)) { $made[] = $t; $jpegs[] = ThumbnailCache::jpeg($t); }
            if (++$i <= self::THUMBNAILS) { $next(); return; }
            $next = null; // the closures let themselves go
            $this->thumbnails = $made;
            $cache->put($p->path(), self::THUMBNAIL_WIDTH, $made, $jpegs);
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
     * @internal Context::videoPlayer
     */
    public function attach(Runtime $rt, VideoPlayerOptions $o): void
    {
        $this->runtime = \WeakReference::create($rt);
        $this->resume = $resume = $o->resume;
        $p = $this->player;
        $p?->setKeepAwake($o->keepAwake);
        if ($o->nowPlaying !== $this->nowPlaying) {
            $this->nowPlaying = $o->nowPlaying;
            $o->nowPlaying ? $this->showNowPlaying() : NowPlaying::end($this);
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
        return $clock . "\0" . $chapter . "\0" . implode("\n", $this->subtitlesVisible ? $p->subtitles() : []) . "\0" . $pictures;
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

    /** Shows $text at the top left, with the time ('time') or the volume ('volume') under it, as IINA's messages. */
    public function say(string $text, string $under = ''): void
    {
        $this->message = [$text, $under, microtime(true) + self::MESSAGE];
        $this->changed();
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
        $this->say($this->t($p->playing() ? 'videoResume' : 'videoPause'), 'time');
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
        $p->seek($p->time() + $s, $exact);
        $this->played();
        $this->say(self::clock($p->time()) . ' / ' . self::clock($p->duration()));
    }

    /** Pauses and shows the next frame, or the one before ($back), as IINA's frame-step and frame-back-step. */
    public function frameStep(bool $back = false): void
    {
        $this->player?->step($back ? -1 : 1);
        $this->played();
    }

    /**
     * Saves the frame showing (Player::snapshot) in $screenshots, made if
     * it is not there, named as IINA's (mpv's %F-%n): the film's name and
     * the first number of four digits not taken, movie-0001.png; tells it.
     * Returns the file's path, null before a frame shows.
     */
    public function screenshot(): ?string
    {
        $p = $this->player;
        $png = $p?->snapshot();
        if ($png === null) { return null; }
        $dir = str_starts_with($this->screenshots, '~/') ? getenv('HOME') . substr($this->screenshots, 1) : $this->screenshots;
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("myphp: no folder $dir for screenshots");
        }
        $name = pathinfo($p->path(), PATHINFO_FILENAME);
        for ($n = 1; file_exists($path = sprintf('%s/%s-%04d.png', $dir, $name, $n)); $n++) {}
        if (file_put_contents($path, $png) === false) {
            throw new RuntimeException("myphp: the screenshot could not be written to $path");
        }
        $this->say($this->t('videoScreenshot'));
        return $path;
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

    public function setVolume(float $v): void
    {
        $this->volume = max(0.0, min(1.0, $v));
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

    /** Plays at $rate, 0.25x to 16x as IINA's slider goes. */
    public function setSpeed(float $rate): void
    {
        $rate = max(0.25, min(16.0, round($rate, 2)));
        $this->player?->setRate($rate);
        $this->played();
        $this->speedAt = log($rate / 0.25, 64);
        $this->say($this->t('videoSpeedTo', ['speed' => self::number($rate)]));
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

    /** $v as IINA writes a number: no trailing zeros. */
    public static function number(float $v): string
    {
        return rtrim(rtrim(sprintf('%.2f', $v), '0'), '.');
    }
}
