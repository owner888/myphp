<?php
declare(strict_types=1);

namespace MyPHP\Media;

use Closure;
use MyPHP\Native\{AssetReader, CoreMedia, MediaTicker, Renderers};
use RuntimeException;

/**
 * Plays a video file, or an audio one, on macOS: Context::video shows it.
 * PHP reads the file's packets (Matroska and WebM in PHP; MP4, MOV and
 * M4V through AVFoundation's reader) and the system decodes and plays
 * them, the video shown straight by the window server, on threads of its
 * own: a timer on the main thread keeps two seconds ahead of the clock.
 * Codecs: H.264 and HEVC; AAC, AC-3, E-AC-3 and MP3. It opens paused on
 * its first frame.
 *
 *     $player = new Player('/path/movie.mkv');
 *     $player->on('time', fn() => $win->invalidate());   // the progress bar moves
 *     $player->play();
 *     // in the view:
 *     $c->video($player)->grow(1);
 */
final class Player
{
    public const EVENTS = ['time', 'ended', 'error'];
    /** Seconds of packets the renderers are given ahead of the clock. */
    private const AHEAD = 2.0;

    private Source $source;
    /** @internal the renderers, whose layer the window shows */
    public readonly Renderers $out;
    private ?Track $video = null, $audio = null;
    private float $duration;
    /**
     * The packets read and not yet given, of the video and of the audio:
     * each renderer takes its own as it asks for more, so that one full
     * (the video's, decoding the frames up to where a seek went) never
     * starves the other, whose clock it waits for.
     * @var array{video: list<Packet>, audio: list<Packet>}
     */
    private array $queued = ['video' => [], 'audio' => []];
    /** Where the last seek went: packets before it are decoded (video) or dropped (audio), not shown. */
    private float $target = 0.0;
    /** The end of the last packet given, and whether the source has no more. */
    private float $fedTo = 0.0;
    private bool $drained = false;
    private bool $playing = false, $ended = false, $muted = false;
    private float $rate = 1.0, $volume = 1.0;
    /** @var array<string, list<Closure>> */
    private array $listeners = [];
    /** The subtitle track shown, null for none; the subtitle files loaded, as tracks; how late the subtitles show, in seconds. */
    private ?Track $subtitle = null;
    /** @var list<Track> */
    private array $external = [];
    private float $subtitleDelay = 0.0;
    /** @var array<int, Cues> the cues of each subtitle track read so far, by track (its object id) */
    private array $cues = [];

    public function __construct(string $path)
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $this->source = in_array($ext, ['mkv', 'mka', 'webm'], true) ? new Matroska($path) : new AssetReader($path);
        $this->duration = $this->source->duration();
        foreach ($this->source->tracks() as $t) {
            if ($t->type === Track::VIDEO && $this->video === null && CoreMedia::playable($t)) {
                $this->video = $t;
            }
        }
        $audio = array_values(array_filter($this->audioTracks(), [self::class, 'playable']));
        $this->audio = array_values(array_filter($audio, fn(Track $t) => $t->isDefault))[0] ?? $audio[0] ?? null;
        if ($this->video === null && $this->audio === null) {
            throw new RuntimeException("myphp: nothing in $path the system decodes");
        }
        $this->out = new Renderers();
        $this->loadSubtitleFiles($path);
        $this->feed();
    }

    /**
     * Loads the subtitle files beside $path that are named after it
     * (movie.srt, movie.zh.ass, movie.English.vtt), as IINA does, and
     * shows the first; else the first embedded track the file marks
     * default.
     */
    private function loadSubtitleFiles(string $path): void
    {
        $base = pathinfo($path, PATHINFO_FILENAME);
        $dir = dirname($path);
        foreach (scandir($dir) ?: [] as $f) {
            if (isset(Subtitles::FILES[strtolower(pathinfo($f, PATHINFO_EXTENSION))]) && str_starts_with($f, $base . '.')) {
                $this->loadSubtitles("$dir/$f");
            }
        }
        $this->subtitle = $this->external[0] ?? null;
        if ($this->subtitle === null) {
            foreach ($this->subtitleTracks() as $t) {
                if ($t->isDefault) { $this->subtitle = $t; break; }
            }
        }
    }

    /** The subtitle tracks of the file the player reads (its text ones) and the subtitle files loaded. @return list<Track> */
    public function subtitleTracks(): array
    {
        return [...array_values(array_filter($this->source->tracks(), [Subtitles::class, 'readable'])), ...$this->external];
    }

    /** The subtitle track shown, or null for none. */
    public function subtitleTrack(): ?Track
    {
        return $this->subtitle;
    }

    /** Shows subtitle track $t, one of subtitleTracks(), or none for null. */
    public function setSubtitleTrack(?Track $t): void
    {
        if ($t !== null && !in_array($t, $this->subtitleTracks(), true)) {
            throw new RuntimeException("myphp: track {$t->number} is no subtitle track of the player");
        }
        $this->subtitle = $t;
    }

    /**
     * Loads subtitle file $path (.srt, .ass, .ssa, .vtt) as a track, which
     * it shows, and returns: its language what the name says between the
     * film's and the extension (movie.zh.srt), its name the file's.
     */
    public function loadSubtitles(string $path): Track
    {
        [$codec, $cues] = Subtitles::file($path);
        $parts = explode('.', basename($path));
        $t = new Track(number: -1 - count($this->external), type: Track::SUBTITLE, codec: $codec, private: '', language: count($parts) > 2 ? $parts[count($parts) - 2] : 'und',
            name: basename($path), isDefault: false, frameDuration: 0.0, width: 0, height: 0, sampleRate: 0.0, channels: 0);
        $this->external[] = $t;
        $all = $this->cues[spl_object_id($t)] = new Cues();
        foreach ($cues as [$start, $end, $text]) {
            $all->add($start, $end, $text);
        }
        $this->subtitle = $t;
        return $t;
    }

    /** Shows the subtitles $seconds later (earlier, negative), as a file timed for another cut of the film needs. */
    public function setSubtitleDelay(float $seconds): void
    {
        $this->subtitleDelay = $seconds;
    }

    public function subtitleDelay(): float
    {
        return $this->subtitleDelay;
    }

    /**
     * The lines of the subtitles showing now, of the track shown, in the
     * order they started; none without one. An embedded track's cues come
     * as the film is read, a little ahead of where it plays, and stay: a
     * seek into a part not read yet misses one begun before the keyframe
     * the reading starts at.
     * @return list<string>
     */
    public function subtitles(): array
    {
        return $this->subtitle === null ? [] : ($this->cues[spl_object_id($this->subtitle)] ?? new Cues())->at($this->time() - $this->subtitleDelay);
    }

    /** Notes the cue subtitle packet $p carries. */
    private function cue(Packet $p): void
    {
        $text = Subtitles::packet($p->track->codec, $p->data);
        if ($text === '') {
            return;
        }
        ($this->cues[spl_object_id($p->track)] ??= new Cues())->add($p->time, $p->duration > 0 ? $p->time + $p->duration : INF, $text);
    }

    /** Whether the system decodes track $t: the player plays an audio track only if so. */
    public static function playable(Track $t): bool
    {
        return CoreMedia::playable($t);
    }

    /** The file's audio tracks, playable or not, in its order. @return list<Track> */
    public function audioTracks(): array
    {
        return array_values(array_filter($this->source->tracks(), fn(Track $t) => $t->type === Track::AUDIO));
    }

    /** The audio track playing, or null for none. */
    public function audioTrack(): ?Track
    {
        return $this->audio;
    }

    /** Plays audio track $t from where the player is, which must be playable. */
    public function setAudioTrack(Track $t): void
    {
        if ($t === $this->audio) {
            return;
        }
        if (!in_array($t, $this->audioTracks(), true) || !self::playable($t)) {
            throw new RuntimeException("myphp: track {$t->number} ({$t->codec}) is no audio track the player plays");
        }
        $this->audio = $t;
        $this->seek($this->time()); // from the keyframe before, its frames up to now decoded and not shown
    }

    /** The video track shown, or null for none (a file of audio). */
    public function videoTrack(): ?Track
    {
        return $this->video;
    }

    /** The video's size in pixels, [0, 0] without video. @return array{0: int, 1: int} */
    public function size(): array
    {
        return $this->video === null ? [0, 0] : [$this->video->width, $this->video->height];
    }

    public function duration(): float
    {
        return $this->duration;
    }

    /** Where it plays, in seconds. */
    public function time(): float
    {
        return max(0.0, min($this->out->time(), $this->duration > 0 ? $this->duration : INF));
    }

    public function playing(): bool
    {
        return $this->playing;
    }

    /** Whether it played to the end, which play() starts again from. */
    public function ended(): bool
    {
        return $this->ended;
    }

    public function play(): void
    {
        if ($this->ended) {
            $this->seek(0);
        }
        $this->playing = true;
        $this->out->setRate($this->rate);
        $this->feed();
        MediaTicker::add($this, $this->tick(...));
    }

    public function pause(): void
    {
        $this->playing = false;
        $this->out->setRate(0);
        MediaTicker::remove($this);
    }

    /** Goes to $seconds: decoding starts at the keyframe before and shows from the time itself. */
    public function seek(float $seconds): void
    {
        $seconds = max(0.0, $this->duration > 0 ? min($seconds, $this->duration) : $seconds);
        $this->source->seek($seconds);
        $this->out->flush();
        $this->queued = ['video' => [], 'audio' => []]; $this->drained = false; $this->ended = false;
        $this->target = $seconds; $this->fedTo = $seconds;
        $this->out->setRate($this->playing ? $this->rate : 0.0, $seconds);
        $this->feed($seconds);
    }

    /** Plays at $rate times the speed (0.5 to 2 sound best). */
    public function setRate(float $rate): void
    {
        if ($rate <= 0) {
            throw new RuntimeException('myphp: a rate is positive (pause() stops)');
        }
        $this->rate = $rate;
        if ($this->playing) {
            $this->out->setRate($rate);
            $this->feed();
        }
    }

    public function rate(): float
    {
        return $this->rate;
    }

    /** Sets the volume, 0 to 1. */
    public function setVolume(float $v): void
    {
        $this->volume = max(0.0, min(1.0, $v));
        $this->out->setVolume($this->volume);
    }

    public function volume(): float
    {
        return $this->volume;
    }

    public function setMuted(bool $m): void
    {
        $this->muted = $m;
        $this->out->setMuted($m);
    }

    public function muted(): bool
    {
        return $this->muted;
    }

    /**
     * Calls $fn on $event, one of EVENTS: time, at each tick while it plays
     * (20 a second, for a progress bar); ended, when it played to the end;
     * error, with what failed, when the system cannot decode it (the player
     * pauses). Returns a function that removes it.
     */
    public function on(string $event, Closure $fn): Closure
    {
        if (!in_array($event, self::EVENTS, true)) {
            throw new RuntimeException("no player event \"$event\"");
        }
        $this->listeners[$event][] = $fn;
        return function () use ($event, $fn) {
            $this->listeners[$event] = array_values(array_filter($this->listeners[$event] ?? [], fn($f) => $f !== $fn));
        };
    }

    private function emit(string $event, mixed ...$args): void
    {
        foreach ($this->listeners[$event] ?? [] as $fn) {
            $fn(...$args);
        }
    }

    private function tick(): void
    {
        $this->feed();
        if (($why = $this->out->failure()) !== null) {
            $this->pause();
            $this->emit('error', $why);
            return;
        }
        if ($this->drained && $this->queued === ['video' => [], 'audio' => []] && $this->out->time() >= $this->fedTo) {
            $this->pause();
            $this->ended = true;
            $this->emit('ended');
            return;
        }
        $this->emit('time');
    }

    /** Reads the packets up to AHEAD seconds past the clock (or past $at, where a seek went), and gives each renderer its own while it takes them. */
    private function feed(?float $at = null): void
    {
        $until = ($at ?? $this->out->time()) + self::AHEAD;
        $last = fn(string $k): float => ($q = $this->queued[$k]) === [] ? -INF : $q[count($q) - 1]->time;
        while (!$this->drained && max($last('video'), $last('audio')) <= $until) {
            $p = $this->source->next();
            if ($p === null) {
                $this->drained = true;
                break;
            }
            if ($p->track === $this->video) {
                $this->queued['video'][] = $p;
            } elseif ($p->track === $this->audio && $p->time + $p->duration > $this->target) { // the audio from where the seek went
                $this->queued['audio'][] = $p;
            } elseif (Subtitles::readable($p->track)) { // every text track's, for one shown later
                $this->cue($p);
            }
        }
        foreach (['video', 'audio'] as $k) {
            while ($this->queued[$k] !== [] && $this->out->ready($this->queued[$k][0]->track)) {
                $p = array_shift($this->queued[$k]);
                $end = $p->time + $p->duration;
                $this->out->enqueue($p, $k === 'audio' || $end > $this->target); // the video's frames before it decoded, not shown
                $this->fedTo = max($this->fedTo, $end);
            }
        }
    }

    public function __destruct()
    {
        MediaTicker::remove($this);
    }
}
