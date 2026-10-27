<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;
use MyPHP\Media\PlaybackPositions;
use MyPHP\Media\Player;
use MyPHP\Media\Track;
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
    /** Whether the player built last goes on from where it was left (VideoPlayerOptions::$resume). */
    private bool $resume = false;
    /** @var list<Closure> the player's listeners, removed for another player */
    private array $off = [];

    /** Where films were left (VideoPlayerOptions::$resume): the app's, PlaybackPositions::default(), unless set. */
    public ?PlaybackPositions $positions = null;
    /** Seconds between two notes of where a film playing is. */
    public const NOTE_EVERY = 10.0;

    /**
     * Takes player $p, built with in $rt's window: its volume this state's,
     * a frame asked for when what shows of its time changes (look), at its
     * end and when it fails; with $resume, from where it was left (more
     * than 5 s in and before its last 5), noting where it is every
     * NOTE_EVERY seconds, at a pause and when another player comes, and
     * forgetting it at its end.
     * @internal Context::videoPlayer
     */
    public function attach(Player $p, Runtime $rt, bool $resume): void
    {
        $this->runtime = \WeakReference::create($rt);
        if ($this->player === $p) {
            return;
        }
        foreach ($this->off as $off) { $off(); }
        if ($this->resume && $this->player !== null) { $this->note(); }
        $this->player = $p;
        $this->resume = $resume;
        $this->error = '';
        $this->speedAt = log($p->rate() / 0.25, 64);
        $p->setVolume($this->volume);
        $this->moved = microtime(true);
        $noted = 0.0;
        if ($resume && ($at = $this->positions()->get($p->path())) !== null && $at > 5 && $at < $p->duration() - 5) {
            $p->seek($at);
            $this->say($this->t('videoResumedAt', ['time' => self::clock($at)]));
        }
        $this->off = [
            $p->on('time', function () use (&$noted) { // a frame only when what shows changed: none while the controls are hidden and the subtitles stay
                $look = $this->look();
                if ($look !== $this->drawn) { $this->drawn = $look; $this->changed(); }
                if ($this->resume && abs($this->player->time() - $noted) >= self::NOTE_EVERY) { $noted = $this->player->time(); $this->note(); }
            }),
            $p->on('ended', function () {
                if ($this->resume) { $this->positions()->forget($this->player->path()); } // watched to the end: from the start next time
                $this->changed();
            }),
            $p->on('error', function (string $why) { $this->error = $why; $this->changed(); }),
        ];
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
        return $clock . "\0" . implode("\n", $this->subtitlesVisible ? $p->subtitles() : []);
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
        if ($this->resume && !$p->playing()) { $this->note(); }
        $this->say($this->t($p->playing() ? 'videoResume' : 'videoPause'), 'time');
    }

    public function stop(): void
    {
        $this->player?->pause();
        $this->player?->seek(0);
        $this->say($this->t('videoStop'));
    }

    public function seekBy(float $s): void
    {
        $p = $this->player;
        if ($p === null) { return; }
        $p->seek($p->time() + $s);
        $this->say(self::clock($p->time()) . ' / ' . self::clock($p->duration()));
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
        $p?->setSubtitleTrack(self::next($p->subtitleTracks(), $p->subtitleTrack()));
    }

    /** Plays the next audio track the system decodes, round to the first. */
    public function cycleAudio(): void
    {
        $p = $this->player;
        if ($p === null) { return; }
        $tracks = array_values(array_filter($p->audioTracks(), [Player::class, 'playable']));
        $next = self::next($tracks, $p->audioTrack()) ?? $tracks[0] ?? null;
        if ($next !== null) { $p->setAudioTrack($next); }
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
    private static function next(array $tracks, ?Track $current): ?Track
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
