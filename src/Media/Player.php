<?php
declare(strict_types=1);

namespace MyPHP\Media;

use Closure;
use MyPHP\App;
use MyPHP\Native\{AssetReader, Awake, CoreMedia, CurlLoop, Equalizer, MediaTicker, Renderers, SoftAudio, SoftVideo, VideoDecoder};
use RuntimeException;

/**
 * Plays a video file, or an audio one, on macOS: Context::video shows it.
 * PHP reads the file's packets (Matroska and WebM in PHP; MP4, MOV and
 * M4V through AVFoundation's reader) and the system decodes and plays
 * them, the video shown straight by the window server, on threads of its
 * own: a timer on the main thread keeps two seconds ahead of the clock.
 * Codecs: H.264 and HEVC (VP9 and AV1 where decoded in hardware); AAC,
 * AC-3, E-AC-3, MP3, Opus and FLAC. It opens paused on
 * its first frame. While it plays the display stays awake, its screen
 * saver off, as IINA keeps it (setKeepAwake, awake()).
 *
 *     $player = new Player('/path/movie.mkv');
 *     $player->on('time', fn() => $win->invalidate());   // the progress bar moves
 *     $player->play();
 *     // in the view:
 *     $c->video($player)->grow(1);
 */
final class Player
{
    public const EVENTS = ['time', 'ended', 'error', 'play', 'pause', 'rate', 'seek', 'track', 'buffering'];
    /** Seconds of packets the renderers are given ahead of the clock. */
    private const AHEAD = 2.0;
    /** A film over HTTP: the clock waits once less than STALL seconds are given ahead and the next are not here, until CACHE_WAIT are (IINA's cachePauseWait, mpv's cache-pause-wait); looked at every BUFFER_LOOK seconds meanwhile. */
    private const STALL = 0.1, CACHE_WAIT = 1.0, BUFFER_LOOK = 0.05;

    /**
     * Whether the system's decoders decode the video, IINA's "Enable
     * hardware decoder" (mpv's hwdec); off, what decodes in software does
     * (AV1, by libdav1d: the system's other decoders are its only ones).
     * Read as a film opens: those playing keep theirs. AV1 the system does
     * not decode (before Apple's M3) decodes in software anyway.
     */
    public static bool $hardwareDecoding = true;

    private Source $source;
    /** Whether this film's video decodes on the system's decoders (self::$hardwareDecoding as it opened, then setHardwareDecoding's). */
    private bool $hardware;
    /** What reads a film over HTTP ahead (its bytes, or HLS's segments), null for a file's. */
    private ?Cache $net = null;
    /** Whether the clock waits for the network (mpv's paused-for-cache), and what failed reading the film. */
    private bool $buffering = false;
    /**
     * Where what the buffering indicator counts starts (the clock's time as
     * it began waiting; null: the first packet read since the seek it began
     * in), and the times of the first and the last packet read since the
     * last seek: the indicator counts what came from the keyframe a seek
     * decodes from, as mpv's cache-buffering-state counts what is cached
     * ahead of what its decoders read (not of the clock, which waits at
     * the target meanwhile).
     */
    private ?float $bufferFrom = null, $readFrom = null;
    private float $readTo = -INF;
    private bool $seeking = false;
    private ?string $readError = null;
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
    /** An exact seek asked while the last one's frames before its time were still being given (its time), made once they are: null for none. */
    private ?float $pending = null;
    private bool $drained = false;
    /** The time of a frame of the video read and how long the frames last (the track's, else its packets'): the frames' grid a step goes by. */
    private ?float $frameAt = null;
    private float $frameTime = 0.0;
    /** The time of the last frame of the video read at or before where the last seek went, null before one: the frame it shows. */
    private ?float $landed = null;
    /** A reader of the file and a decoder of its video of their own, for thumbnails (thumbnail()), made at the first. */
    private ?Source $thumbSource = null;
    private ?VideoDecoder $thumbDecoder = null;
    /** @var list<Packet> what a seek to a keyframe read past to find it, which the source's next packets come after */
    private array $ahead = [];
    private bool $playing = false, $ended = false, $muted = false;
    private float $rate = 1.0, $volume = 1.0;
    /** @var array<string, list<Closure>> */
    private array $listeners = [];
    /** The subtitle track shown, null for none; the subtitle files loaded, as tracks; how late the subtitles show, in seconds. */
    private ?Track $subtitle = null;
    /** @var list<Track> */
    private array $external = [];
    private float $subtitleDelay = 0.0;
    /** The secondary subtitle track shown (mpv's secondary-sid), null for none, and how late it shows (secondary-sub-delay). */
    private ?Track $secondary = null;
    private float $secondaryDelay = 0.0;
    /** The aspect, crop and rotation the picture shows at (setAspectRatio, setCrop, setRotation). */
    private ?float $aspect = null, $crop = null;
    private int $rotation = 0;
    /** The change of the subtitles the player wakes for, once (wakeForCue). */
    private ?float $cueWake = null;
    /** @var array<int, array{0: float, 1: Closure(): void}> the times told as it plays past them (at()), by their id */
    private array $boundaries = [];
    private int $boundaryId = 0;
    /** Where it played to when last looked (null before it plays from a place): a boundary after it and at or before where it is now was passed. */
    private ?float $passedTo = null;
    /** The boundary the player wakes for, once (wakeForBoundary). */
    private ?float $boundaryWake = null;
    /** What keeps the display awake while it plays, and whether it does (setKeepAwake). */
    private Awake $awake;
    private bool $keepAwake = true;
    /** @var array<int, Ass> the headers of the ASS tracks, by track (its object id): their styles */
    private array $assHeaders = [];
    /** @var array<int, Pgs|VobSub> the decoders of the image subtitle tracks, by track (its object id): a PGS track's sets reuse what came before */
    private array $pictureDecoders = [];
    /** @var array<int, Cues> the cues of each subtitle track read so far, by track (its object id) */
    private array $cues = [];
    /** The page's title, chapters and playlist (yt-dlp's), for a film read from a page. @var list<Chapter> */
    private ?string $title = null;
    private array $pageChapters = [];
    private ?Playlist $playlist = null;
    /** @var array<int, array{0: string, 1: array<string, string>, 2: string, 3: ?Download}> the page's subtitles not loaded yet: their URL, headers, extension and download, by track (its object id) */
    private array $remote = [];

    /**
     * Plays the file at $path, or the film at an http(s) URL (Matroska,
     * WebM, MP4, MOV, MPEG-TS, an HLS playlist), read as it comes; a URL that
     * is no film (a web page) read by yt-dlp, as mpv's ytdl_hook does when
     * the URL does not open (Media\YtDlp): the video of the page, or the
     * first of its playlist (playlist()). $waiting is told the seconds it
     * waits before a page's formats can be read (the ad before it), as it
     * begins to.
     * @param ?Closure(float): void $waiting
     */
    public function __construct(private readonly string $path, private readonly ?Closure $waiting = null)
    {
        if (self::isUrl($path) && YtDlp::first($path) && YtDlp::find() !== null) { // YouTube's, Twitch's: read by yt-dlp first, then as a film (mpv's on_load hook)
            try {
                $this->page($path);
            } catch (RuntimeException $page) {
                try {
                    [$this->source, $this->net] = self::open($path);
                } catch (RuntimeException $direct) {
                    throw new RuntimeException($page->getMessage() . '; opened as a film, ' . $direct->getMessage());
                }
            }
        } elseif (self::isUrl($path)) { // else as a film, then by yt-dlp once that failed (its on_load_fail)
            try {
                [$this->source, $this->net] = self::open($path);
            } catch (RuntimeException $direct) {
                if (YtDlp::find() === null) {
                    throw new RuntimeException($direct->getMessage() . ', and there is no yt-dlp to read it as a page (brew install yt-dlp)');
                }
                try {
                    $this->page($path);
                } catch (RuntimeException $e) {
                    throw new RuntimeException($direct->getMessage() . '; read as a page, ' . $e->getMessage());
                }
            }
        } else {
            $this->source = self::source($path);
        }
        $this->hardware = self::$hardwareDecoding;
        $this->duration = $this->source->duration();
        foreach ($this->source->tracks() as $t) {
            if ($t->type === Track::VIDEO && $this->video === null && self::playable($t)) {
                $this->video = $t;
            }
        }
        $audio = array_values(array_filter($this->audioTracks(), [self::class, 'playable']));
        $this->audio = array_values(array_filter($audio, fn(Track $t) => $t->isDefault))[0] ?? $audio[0] ?? null;
        if ($this->video === null && $this->audio === null) {
            throw new RuntimeException("myphp: nothing in $path the system decodes");
        }
        $this->source->choose($this->video, $this->audio);
        $this->frameTime = $this->video?->frameDuration ?? 0.0;
        $this->out = new Renderers($this->hardware);
        $self = \WeakReference::create($this); // the renderers hold no cycle to the player
        $this->out->videoWanted = static function () use ($self): void { // the layer taking more between ticks, a software decoder's slice spent
            $p = $self->get();
            $p?->give('video');
            $p?->landPending();
        };
        $this->awake = new Awake('Playing ' . basename($path));
        if ($this->net === null) { $this->loadSubtitleFiles($path); }
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
        if ($t === $this->subtitle) {
            return;
        }
        if ($t !== null && $t === $this->secondary) { // one track shows once, as mpv refuses it ("already selected")
            throw new RuntimeException("myphp: track {$t->number} is the secondary subtitle track");
        }
        $this->subtitle = $t;
        if ($t !== null) { $this->fetchSubtitles($t); } // a page's: downloaded from now
        $this->emit('track', 'subtitle');
    }

    /** The secondary subtitle track shown (mpv's secondary-sid, IINA's second subtitle), or null for none. */
    public function secondarySubtitleTrack(): ?Track
    {
        return $this->secondary;
    }

    /**
     * Shows text subtitle track $t, one of subtitleTracks() but the one
     * shown first, as the secondary subtitles, or none for null: their text
     * at the top of the picture, their styles left out (mpv's
     * secondary-sub-ass-override strip). Image tracks (PGS, VobSub) are
     * none of them here.
     */
    public function setSecondarySubtitleTrack(?Track $t): void
    {
        if ($t !== null && !in_array($t, $this->subtitleTracks(), true)) {
            throw new RuntimeException("myphp: track {$t->number} is no subtitle track of the player");
        }
        if ($t !== null && in_array($t->codec, Subtitles::PICTURES, true)) {
            throw new RuntimeException("myphp: track {$t->number} is of image subtitles, which show as the first subtitles only");
        }
        if ($t === $this->secondary) {
            return;
        }
        if ($t !== null && $t === $this->subtitle) {
            throw new RuntimeException("myphp: track {$t->number} is the subtitle track shown");
        }
        $this->secondary = $t;
        if ($t !== null) { $this->fetchSubtitles($t); }
        $this->emit('track', 'secondarySubtitle');
    }

    /** Shows the secondary subtitles $seconds later (earlier, negative): mpv's secondary-sub-delay. */
    public function setSecondarySubtitleDelay(float $seconds): void
    {
        $this->secondaryDelay = $seconds;
    }

    public function secondarySubtitleDelay(): float
    {
        return $this->secondaryDelay;
    }

    /**
     * The text of the secondary subtitles showing now, a line a cue in the
     * order they started (an ASS line's text, its styles left out); none
     * without a secondary track.
     * @return list<string>
     */
    public function secondarySubtitles(): array
    {
        if ($this->secondary === null) {
            return [];
        }
        $this->fetchSubtitles($this->secondary);
        $out = [];
        foreach (($this->cues[spl_object_id($this->secondary)] ?? new Cues())->at($this->time() - $this->secondaryDelay) as $s) {
            if (is_string($s)) { $out[] = $s; } elseif ($s instanceof AssLine) { $out[] = $s->text(); }
        }
        return $out;
    }

    /**
     * Loads subtitle file $path (.srt, .ass, .ssa, .vtt) as a track, which
     * it shows, and returns: its language what the name says between the
     * film's and the extension (movie.zh.srt), its name the file's.
     */
    public function loadSubtitles(string $path): Track
    {
        [$codec, $cues] = Subtitles::file($path);
        if (Subtitles::styled($codec)) { // its lines in their styles, which subtitles() gives as text
            $cues = Subtitles::assFile($path);
        }
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
        $out = [];
        foreach ($this->showing() as $s) {
            if (is_string($s)) { $out[] = $s; } elseif ($s instanceof AssLine) { $out[] = $s->text(); }
        }
        return $out;
    }

    /**
     * The lines showing now of an ASS or SSA track shown, in their styles
     * and where they are as they are now (moved, faded, animated, sung:
     * AssLine::at); none of another. subtitles() gives their text.
     * @return list<AssLine>
     */
    public function subtitleLines(): array
    {
        $t = $this->time() - $this->subtitleDelay;
        $out = [];
        foreach ($this->showing() as $s) {
            if ($s instanceof AssLine) { $out[] = $s->at($t - $s->start); }
        }
        return $out;
    }

    /**
     * The pictures of the image subtitles showing now (PGS, VobSub), of the
     * track shown, each where it is on the subtitles' plane, which lies over
     * the picture (subtitlePlane); none without one, or of a text track.
     * @return list<SubtitlePicture>
     */
    public function subtitlePictures(): array
    {
        return array_merge(...array_values(array_filter($this->showing(), 'is_array')));
    }

    /**
     * Where the subtitles' plane of an image track lies in a box $w by $h,
     * [x, y, w, h]: over the whole picture as picture() lays it out (a crop
     * cuts it with the picture), fitted in what shows of a turned one, of
     * the plane's own aspect, $planeWidth by $planeHeight.
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function subtitlePlane(float $w, float $h, int $planeWidth, int $planeHeight): array
    {
        [[$sx, $sy, $sw, $sh], [$pw, $ph]] = $this->picture($w, $h);
        if ($this->rotation === 90 || $this->rotation === 270) {
            [[$x, $y, $fw, $fh]] = Picture::fit($sw, $sh, $planeWidth / max(1, $planeHeight), null, 0);
            return [$sx + $x, $sy + $y, $fw, $fh];
        }
        return [$sx + ($sw - $pw) / 2, $sy + ($sh - $ph) / 2, $pw, $ph];
    }

    /** What the subtitles of the track shown show now, texts, ASS lines or pictures. @return list<string|AssLine|list<SubtitlePicture>> */
    private function showing(): array
    {
        if ($this->subtitle !== null) { $this->fetchSubtitles($this->subtitle); }
        return $this->subtitle === null ? [] : ($this->cues[spl_object_id($this->subtitle)] ?? new Cues())->at($this->time() - $this->subtitleDelay);
    }

    /** Notes the cue subtitle packet $p carries. */
    private function cue(Packet $p): void
    {
        $cues = $this->cues[spl_object_id($p->track)] ??= new Cues();
        if ($p->track->codec === 'S_HDMV/PGS') { // a display set: its pictures, or none to clear, until the next
            $d = $this->pictureDecoders[spl_object_id($p->track)] ??= new Pgs();
            $cues->add($p->time, INF, $d->displaySet($p->data));
            return;
        }
        if ($p->track->codec === 'S_VOBSUB') { // a subpicture: from and to when it says, after the packet's time
            $d = $this->pictureDecoders[spl_object_id($p->track)] ??= new VobSub($p->track->private);
            $shown = $d->packet($p->data);
            if ($shown !== null) {
                [$from, $to, $pic] = $shown;
                $cues->add($p->time + $from, $to !== null ? $p->time + $to : ($p->duration > 0 ? $p->time + $p->duration : INF), [$pic]);
            }
            return;
        }
        if (Subtitles::styled($p->track->codec)) { // a dialogue line in the styles of the track's header
            $line = Subtitles::assPacket($this->assHeaders[spl_object_id($p->track)] ??= new Ass($p->track->private), $p->data, $p->time, $p->duration > 0 ? $p->duration : INF);
            if ($line !== null) {
                $cues->add($p->time, $p->duration > 0 ? $p->time + $p->duration : INF, $line);
            }
            return;
        }
        $text = Subtitles::packet($p->track->codec, $p->data);
        if ($text === '') {
            return;
        }
        $cues->add($p->time, $p->duration > 0 ? $p->time + $p->duration : INF, $text);
    }

    /** Whether the system decodes track $t, or libdav1d does (AV1): the player plays a track only if so. */
    public static function playable(Track $t): bool
    {
        return CoreMedia::playable($t) || SoftVideo::wanted($t, false) || SoftAudio::wanted($t);
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
        $this->source->choose($this->video, $t); // HLS's renditions: the one played read from here
        $this->go($this->time(), true); // from the keyframe before, its frames up to now decoded and not shown
        $this->emit('track', 'audio');
    }

    /**
     * Loads audio file $path (a film's too: its audio) as tracks beside the
     * film's, as mpv's audio-add does for IINA's Load External Audio:
     * played from where the film is, the first chosen; returns them. A URL
     * is read as it plays. What has no audio the player plays throws.
     * @return list<Track>
     */
    public function loadAudio(string $path): array
    {
        [$s, $cache] = self::isUrl($path) ? self::open($path) : [self::source($path), null];
        if (array_filter($s->tracks(), fn(Track $t) => $t->type === Track::AUDIO && self::playable($t)) === []) {
            throw new RuntimeException("myphp: $path has no audio the player plays");
        }
        if (!$this->source instanceof Merged) {
            $this->source = new Merged([$this->source], $this->net === null ? [] : [$this->net]);
        }
        $tracks = $this->source->add($s, $cache, basename((string)(parse_url($path, PHP_URL_PATH) ?: $path)));
        if ($cache !== null && $this->net === null) { $this->net = $this->source; } // read over the network now: the clock waits for it
        foreach ($tracks as $t) {
            if (self::playable($t)) { $this->setAudioTrack($t); break; }
        }
        return $tracks;
    }

    /** Whether $path is an http(s) URL. */
    private static function isUrl(string $path): bool
    {
        return preg_match('~^https?://~i', $path) === 1;
    }

    /** Whether it plays a film over the network (an http(s) URL), IINA's isNetworkResource. */
    public function remote(): bool
    {
        return $this->net !== null;
    }

    /** While the clock waits for the network (the buffering event), how much of what it waits for is here, 0 to 100 (mpv's cache-buffering-state); else null. */
    public function bufferingPercent(): ?int
    {
        if (!$this->buffering) {
            return null;
        }
        $from = $this->bufferFrom ?? $this->readFrom;
        $goal = $this->out->time() + self::CACHE_WAIT;
        if ($from === null) {
            return 0;
        }
        return $goal <= $from ? 100 : (int)min(100, max(0.0, max($this->readTo, $this->given()) - $from) / ($goal - $from) * 100);
    }

    /** The bytes of the film its cache holds (a film over HTTP's; 0 for a file). */
    public function cacheBytes(): int
    {
        return $this->net?->cached() ?? 0;
    }

    /** How fast the film comes, bytes a second over the last (0 for a file). */
    public function cacheSpeed(): float
    {
        return $this->net?->speed() ?? 0.0;
    }

    /** Where what was read of the film ends: given to the renderers, or waiting for them. */
    private function given(): float
    {
        $end = $this->fedTo;
        foreach ($this->queued as $q) {
            if ($q !== []) { $p = $q[count($q) - 1]; $end = max($end, $p->time + $p->duration); }
        }
        return $end;
    }

    /**
     * A film over HTTP: the clock waits (mpv's paused-for-cache, told by the
     * buffering event) once less than STALL seconds are given ahead of it
     * and the next are not here, and goes on once CACHE_WAIT seconds are, or
     * the end; looked at every BUFFER_LOOK seconds while it waits.
     */
    private function cache(): void
    {
        if ($this->net === null) {
            return;
        }
        $ahead = $this->given() - $this->out->time();
        if (!$this->buffering) {
            if ($this->playing && !$this->drained && $ahead < self::STALL && !$this->source->ready()) {
                $this->buffering = true;
                $this->bufferFrom = $this->seeking ? null : $this->out->time();
                $this->out->setRate(0);
                $this->emit('buffering', true);
                $this->lookAtCache();
            }
            return;
        }
        if ($ahead >= self::CACHE_WAIT || $this->drained || !$this->playing) {
            $this->buffering = false;
            if ($this->playing) { $this->out->setRate($this->rate); }
            $this->emit('buffering', false);
        }
    }

    private function lookAtCache(): void
    {
        App::after(self::BUFFER_LOOK, function (): void {
            if (!$this->buffering) { return; }
            $this->feed();
            $this->cache();
            if ($this->buffering) { $this->lookAtCache(); }
        });
    }

    /**
     * The reader of the film at URL $url and what reads it ahead, each
     * request with $headers, of $chunk bytes at most (0: all): HLS by its path's extension (.m3u8) or
     * yt-dlp's $protocol (m3u8, m3u8_native), else by what its first bytes
     * are, as FFmpeg probes: EBML's id, an ISO base media box, an HLS
     * playlist, a TS packet's sync byte.
     * @param array<string, string> $headers
     * @return array{0: Source, 1: Cache}
     */
    private static function open(string $url, array $headers = [], string $protocol = '', int $chunk = 0): array
    {
        if (str_starts_with($protocol, 'm3u8') || preg_match('~\.m3u8?$~i', (string)parse_url($url, PHP_URL_PATH)) === 1) {
            $h = new Hls($url, $headers);
            return [$h, $h];
        }
        if (!in_array($protocol, ['', 'http', 'https'], true)) {
            throw new RuntimeException("myphp: $url comes by $protocol, which the player does not read");
        }
        $http = new HttpBytes($url, $headers, $chunk);
        $head = $http->read(0, 8);
        $source = match (true) {
            str_starts_with($head, "\x1A\x45\xDF\xA3") => new Matroska($http),
            in_array(substr($head, 4, 4), ['ftyp', 'moov', 'mdat', 'free', 'skip', 'wide', 'pnot', 'styp', 'sidx'], true) => new Mp4($http),
            str_starts_with($head, '#EXTM3U') => null,
            ($head[0] ?? '') === 'G' || ($head[4] ?? '') === 'G' => new Ts($http),
            default => throw new RuntimeException("myphp: $url is no Matroska, WebM, MP4, MOV, MPEG-TS or HLS film"),
        };
        if ($source === null) {
            $h = new Hls($url, $headers);
            return [$h, $h];
        }
        return [$source, $http];
    }

    /**
     * The results of $jobs, run at once: each in a fiber of its own, their
     * waits for the network together (in a fiber of the app; one after
     * the other outside one, as each waits there); what failed thrown, once
     * all are over.
     * @template T
     * @param list<Closure(): T> $jobs
     * @return list<T>
     */
    private static function together(array $jobs): array
    {
        $done = [];
        foreach ($jobs as $k => $job) {
            (new \Fiber(function () use ($job, $k, &$done): void {
                try { $done[$k] = [$job(), null]; } catch (\Throwable $e) { $done[$k] = [null, $e]; }
            }))->start();
        }
        CurlLoop::waitUntil(function () use (&$done, $jobs): bool { return count($done) === count($jobs); }); // not an arrow function: it would hold $done as it was
        ksort($done);
        foreach ($done as [, $e]) { if ($e !== null) { throw $e; } }
        return array_column($done, 0);
    }

    /**
     * The page at $url, as yt-dlp reads it: a video's formats (one, or a
     * video's and an audio's, Merged), its title, chapters and subtitles
     * (loaded as they are chosen); a playlist's entries, the one to play
     * first read again.
     */
    private function page(string $url): void
    {
        $info = YtDlp::info($url);
        if (($info['_type'] ?? 'video') === 'playlist') {
            [$entries, $start] = YtDlp::entries($info, $url);
            if ($entries === []) {
                throw new RuntimeException("myphp: $url: yt-dlp found a playlist of no videos");
            }
            $this->playlist = new Playlist(array_column($entries, 0), array_combine(array_column($entries, 0), array_column($entries, 1)));
            $this->playlist->select($entries[$start][0]);
            $info = YtDlp::info($entries[$start][0]);
        }
        if (($wait = YtDlp::wait($info)) > 0) { // the ad before it waited out, its URLs refused till then (HTTP 403), as mpv waits
            if ($this->waiting !== null) { ($this->waiting)($wait); }
            $until = microtime(true) + $wait;
            CurlLoop::waitUntil(fn(): bool => microtime(true) >= $until);
        }
        $opened = self::together(array_map(fn(array $f) => fn(): array => self::open($f['url'], $f['headers'], $f['protocol'], $f['chunk']), YtDlp::formats($info))); // the video's and the audio's at once
        if (count($opened) === 1) {
            [$this->source, $this->net] = $opened[0];
        } else {
            $this->source = $this->net = new Merged(array_column($opened, 0), array_column($opened, 1));
        }
        $this->title = is_string($info['title'] ?? null) ? $info['title'] : null;
        $this->pageChapters = YtDlp::chapters($info);
        foreach (YtDlp::subtitles($info) as $s) {
            $t = new Track(number: -1 - count($this->external), type: Track::SUBTITLE, codec: Subtitles::FILES[$s['ext']], private: '', language: $s['language'],
                name: $s['name'], isDefault: false, frameDuration: 0.0, width: 0, height: 0, sampleRate: 0.0, channels: 0);
            $this->external[] = $t;
            $this->remote[spl_object_id($t)] = [$s['url'], $s['headers'], $s['ext'], null];
        }
    }

    /** The film's title: the page's (yt-dlp's), else its file's name (a URL's, decoded): IINA's media-title. */
    public function title(): string
    {
        if ($this->title !== null) {
            return $this->title;
        }
        return self::isUrl($this->path) ? rawurldecode(basename((string)parse_url($this->path, PHP_URL_PATH))) : basename($this->path);
    }

    /** The playlist of the page the URL is (yt-dlp's entries, each a video's page, the one playing chosen), null for a film's. */
    public function playlist(): ?Playlist
    {
        return $this->playlist;
    }

    /**
     * Subtitle track $t, of the page's (remote: yt-dlp's), loaded once its
     * file came: downloaded as it is first shown, read as a file of its kind
     * (a temporary one), its cues then shown as a file's are.
     */
    private function fetchSubtitles(Track $t): void
    {
        $id = spl_object_id($t);
        if (!isset($this->remote[$id])) {
            return;
        }
        [$url, $headers, $ext, $d] = $this->remote[$id];
        if ($d === null) {
            $this->remote[$id][3] = new Download($url, headers: $headers);
            return;
        }
        if (!$d->over()) {
            return;
        }
        unset($this->remote[$id]);
        $file = sys_get_temp_dir() . '/myphp-subtitles-' . getmypid() . "-$id.$ext";
        try {
            file_put_contents($file, $d->text());
            $cues = Subtitles::styled($t->codec) ? Subtitles::assFile($file) : Subtitles::file($file)[1];
        } catch (RuntimeException $e) {
            $this->readError = $e->getMessage();
            return;
        } finally {
            @unlink($file);
        }
        $all = $this->cues[$id] = new Cues();
        foreach ($cues as [$start, $end, $text]) { $all->add($start, $end, $text); }
    }

    /** The reader of the file at $path: Matroska's and MPEG-TS's in PHP, AVFoundation's for the rest. */
    private static function source(string $path): Source
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'mkv', 'mka', 'webm' => new Matroska($path),
            'ts', 'm2ts', 'mts', 'm2t' => new Ts($path),
            default => new AssetReader($path),
        };
    }

    /** How long the film at $path lasts, in seconds, read from its header (a playlist's); not of one over the network, which it does not open for that. */
    public static function durationOf(string $path): float
    {
        if (self::isUrl($path)) {
            throw new RuntimeException("myphp: $path is read only as it plays");
        }
        return self::source($path)->duration();
    }

    /** The file it plays. */
    public function path(): string
    {
        return $this->path;
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

    /** The film's chapters, by their start; none when the file has none. @return list<Chapter> */
    public function chapters(): array
    {
        return $this->pageChapters !== [] ? $this->pageChapters : $this->source->chapters();
    }

    /** The place of the chapter playing in chapters(): the last that starts at or before where it plays; -1 before the first, or with none. */
    public function chapter(): int
    {
        $t = $this->time();
        $at = -1;
        foreach ($this->chapters() as $i => $ch) {
            if ($ch->start > $t + 1e-3) { break; }
            $at = $i;
        }
        return $at;
    }

    /** Where it plays, in seconds. */
    public function time(): float
    {
        return max(0.0, min($this->pending ?? $this->out->time(), $this->duration > 0 ? $this->duration : INF));
    }

    public function playing(): bool
    {
        return $this->playing;
    }

    /** Whether it keeps the display awake, its screen saver off: while it plays, unless setKeepAwake(false). */
    public function awake(): bool
    {
        return $this->awake->held();
    }

    /** @internal UI\VideoPlayerState: whether what it keeps awake while it plays is the display (its screen saver off), else only the system (IINA's "not while only playing audio"). */
    public function setAwakeDisplay(bool $display): void
    {
        $this->awake->setDisplay($display);
    }

    /** Whether it keeps the display awake while it plays (as IINA's "prevent the screen saver", on by default); off, it lets it sleep now. */
    public function setKeepAwake(bool $on): void
    {
        $this->keepAwake = $on;
        $on && $this->playing ? $this->awake->hold() : $this->awake->release();
    }

    /** Whether it played to the end, which play() starts again from. */
    public function ended(): bool
    {
        return $this->ended;
    }

    public function play(): void
    {
        if ($this->ended) {
            $this->go(0.0, true);
        }
        if ($this->playing) {
            return;
        }
        $this->playing = true;
        if ($this->keepAwake) { $this->awake->hold(); }
        $this->out->setRate($this->rate);
        $this->feed();
        MediaTicker::add($this, $this->tick(...));
        $this->wakeForCue();
        $this->passedTo = $this->time(); $this->boundaryWake = null; $this->wakeForBoundary();
        $this->emit('play');
    }

    public function pause(): void
    {
        if (!$this->playing) {
            return;
        }
        $this->playing = false;
        $this->awake->release(); // paused, ended or failed: the display may sleep
        $this->out->setRate(0);
        MediaTicker::remove($this);
        $this->emit('pause');
        $this->cache(); // no wait for the network while paused
    }

    /**
     * Goes to $seconds, and tells where it went (the seek event). Exact,
     * decoding starts at the keyframe before and it shows from the time
     * itself; else it goes to the keyframe nearest in the way it goes, as
     * mpv's keyframe seeks (IINA's of the arrow keys): the first at or
     * after a time ahead, the last at or before one behind (the one before
     * when none comes after), which shows at once, nothing decoded unseen.
     */
    public function seek(float $seconds, bool $exact = true): void
    {
        if ($exact && $this->catchingUp()) { // as mpv's seeks while one decodes: the last kept, made once it landed (a slider dragged)
            $this->pending = $seconds = max(0.0, $this->duration > 0 ? min($seconds, $this->duration) : $seconds);
            $this->emit('seek', $seconds);
            return;
        }
        $this->emit('seek', $this->go($seconds, $exact));
    }

    /** Whether the last seek's frames before its time are still to be given the video's renderer (decoded unseen, from the keyframe). */
    private function catchingUp(): bool
    {
        $q = $this->queued['video'];
        return $q !== [] && $q[0]->time + $q[0]->duration <= $this->target;
    }

    /** The exact seek kept while the last one decoded, made once it landed (at a tick, or as the renderer took more). */
    private function landPending(): void
    {
        if ($this->pending !== null && !$this->catchingUp()) {
            $this->go($this->pending, true);
        }
    }

    /**
     * Pauses and goes $frames frames on (back when negative) from the one
     * showing, as mpv's frame-step and frame-back-step: the frame nearest
     * its place on the frames' grid (their duration, from the time of one),
     * not before the first nor past the last, at its own time.
     */
    public function step(int $frames): void
    {
        if ($this->video === null || $this->frameAt === null || $this->frameTime <= 0) {
            throw new RuntimeException("myphp: no frames to step by in {$this->path}");
        }
        $this->pause();
        $d = $this->frameTime;
        $last = (int)floor(($this->duration - $this->frameAt) / $d - 1e-6);
        // The one showing: where it is, a quarter of a frame early let pass (a container's rounded times of frames land up to half a millisecond off the grid).
        $k = max(0, min($last, (int)floor(($this->time() - $this->frameAt) / $d + 0.25) + $frames));
        $this->go($this->frameAt + ($k + 0.5) * $d, true); // half a frame on: the nearest frame shows
        if ($this->landed !== null) { $this->out->setRate(0.0, $this->landed); } // the clock at its time
        $this->emit('seek', $this->time());
    }

    /**
     * The frame showing, as a PNG of the film's pixels (its aspect, crop
     * and rotation not applied, its subtitles not on it); null before one
     * shows, and while it shows in no window (the system gives the frame
     * of a layer on screen). From macOS 14.4.
     */
    public function snapshot(): ?string
    {
        return $this->out->snapshot();
    }

    /**
     * A thumbnail of the film at $seconds, $width pixels wide: the keyframe
     * at or before it, decoded by a reader and a decoder of its own (what
     * plays goes on as it was), as IINA's thumbnails of the seek bar are;
     * null for a film of no video, or over the network (IINA makes none
     * of one). A few milliseconds: a keyframe read and
     * decoded, on this thread.
     */
    public function thumbnail(float $seconds, int $width): ?Thumbnail
    {
        if ($this->video === null || $this->net !== null) { // none of a film over the network, as IINA makes none
            return null;
        }
        $this->thumbSource ??= self::source($this->path);
        if ($this->thumbDecoder?->width !== $width) { $this->thumbDecoder = new VideoDecoder($this->video, $width, $this->hardware); }
        $p = $this->thumbSource->keyframe(max(0.0, min($seconds, $this->duration)));
        if ($p === null) {
            return null;
        }
        $d = $this->thumbDecoder;
        $rgba = $d->frame($p);
        [$w, $h] = [$d->width, $d->height];
        return new Thumbnail($p->time, $w, $h, fn(): string => $rgba);
    }

    /** Goes to $seconds as seek() does, telling nothing; returns where it went. */
    private function go(float $seconds, bool $exact): float
    {
        $this->pending = null; // this one instead
        $seconds = max(0.0, $this->duration > 0 ? min($seconds, $this->duration) : $seconds);
        $forward = $seconds > $this->time();
        $this->source->seek($seconds);
        $this->ahead = [];
        $this->readFrom = null; $this->readTo = -INF;
        if (!$exact && $this->video !== null) {
            $seconds = $this->keyframe($seconds, $forward);
        }
        $this->out->flush();
        $this->queued = ['video' => [], 'audio' => []]; $this->drained = false; $this->ended = false;
        $this->target = $seconds; $this->fedTo = $seconds; $this->landed = null;
        $this->out->setRate($this->playing && !$this->buffering ? $this->rate : 0.0, $seconds);
        $this->feed($seconds);
        $this->seeking = true;
        $this->cache(); // over HTTP, the clock waits there for what is not here
        $this->seeking = false;
        if ($this->playing) { $this->cueWake = null; $this->wakeForCue(); } // from where it went
        $this->passedTo = $seconds; $this->boundaryWake = null; // a seek passes none
        if ($this->playing) { $this->wakeForBoundary(); }
        return $seconds;
    }

    /**
     * The time of the keyframe a keyframe seek to $seconds goes to, the
     * source just gone to the one at or before it: that one's, or ahead
     * ($forward) the first at or after $seconds, read up to; $seconds
     * itself where there is none (ahead past the last, as mpv goes to the
     * end). What was read from it on is $ahead, which the next packets come
     * from; the video before it is left out, decoding starting at it.
     */
    private function keyframe(float $seconds, bool $forward): float
    {
        $read = []; $at = null;
        while (($p = $this->source->next()) !== null) {
            $read[] = $p;
            if ($p->track === $this->video && $p->keyframe && (!$forward || $p->time >= $seconds - 1e-3)) {
                $at = $p->time;
                break;
            }
        }
        if ($at === null) { // as an exact seek
            $this->ahead = $read;
            return $seconds;
        }
        $this->ahead = array_values(array_filter($read, fn(Packet $q) => $q->track !== $this->video || $q->time >= $at - 1e-6));
        return max(0.0, $at);
    }

    /**
     * @internal UI\VideoPlayerState: decodes this film's video on the
     * system's decoders or not, as IINA's quick setting of hardware
     * decoding (mpv's hwdec set as it plays): the frames from where it is
     * decoded again, from the keyframe before, the other way. Whether it
     * can be decoded the other way: decodingSwitchable().
     */
    public function setHardwareDecoding(bool $on): void
    {
        if ($on === $this->hardware) {
            return;
        }
        $this->hardware = $on;
        $this->out->setHardware($on);
        $this->thumbDecoder = null; // its thumbnails decoded so too
        $this->go($this->time(), true);
    }

    /** @internal UI\VideoPlayer: whether its video decodes on the system's decoders (what only they decode, whatever was asked; AV1 without them, in software). */
    public function hardwareDecoding(): bool
    {
        return $this->video !== null && !\MyPHP\Native\SoftVideo::wanted($this->video, $this->hardware);
    }

    /** @internal UI\VideoPlayer: whether its video decodes either way, and so is switched (AV1, with libdav1d and the system's decoder). */
    public function decodingSwitchable(): bool
    {
        return $this->video !== null && \MyPHP\Native\SoftVideo::wanted($this->video, false) && \MyPHP\Native\CoreMedia::playable($this->video);
    }

    /** Plays at $rate times the speed (0.5 to 2 sound best). */
    public function setRate(float $rate): void
    {
        if ($rate <= 0) {
            throw new RuntimeException('myphp: a rate is positive (pause() stops)');
        }
        if ($rate == $this->rate) {
            return;
        }
        $this->rate = $rate;
        if ($this->playing) {
            $this->out->setRate($rate);
            $this->feed();
        }
        $this->emit('rate', $rate);
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

    /** Shows the picture at aspect $ratio (its width over its height, as IINA's 4:3, 16:9…), null for its own. */
    public function setAspectRatio(?float $ratio): void
    {
        $this->aspect = self::ratio($ratio, 'an aspect ratio');
    }

    /** The aspect asked of the picture, null for its own. */
    public function aspectRatio(): ?float
    {
        return $this->aspect;
    }

    /** Cuts the picture to aspect $ratio around its middle, null for none. */
    public function setCrop(?float $ratio): void
    {
        $this->crop = self::ratio($ratio, 'a crop');
    }

    public function crop(): ?float
    {
        return $this->crop;
    }

    /** Turns the picture clockwise by $degrees: 0, 90, 180 or 270. */
    public function setRotation(int $degrees): void
    {
        if (!in_array($degrees, [0, 90, 180, 270], true)) {
            throw new RuntimeException("myphp: a rotation is 0, 90, 180 or 270 degrees, not $degrees");
        }
        $this->rotation = $degrees;
    }

    public function rotation(): int
    {
        return $this->rotation;
    }

    /**
     * Where the picture shows in a box $w by $h, as setAspectRatio,
     * setCrop and setRotation lay it out: see Picture::fit.
     * @internal the window places the video's layer by it
     * @return array{0: array{0: float, 1: float, 2: float, 3: float}, 1: array{0: float, 1: float}}
     */
    public function picture(float $w, float $h): array
    {
        [$vw, $vh] = $this->size();
        return Picture::fit($w, $h, $this->aspect ?? ($vh > 0 ? $vw / $vh : 16 / 9), $this->crop, $this->rotation);
    }

    /** Whether the picture shows as the file has it: its own aspect, uncut, upright. */
    public function plainPicture(): bool
    {
        return $this->aspect === null && $this->crop === null && $this->rotation === 0;
    }

    /** Plays the sound $seconds later than the picture (earlier when negative), from where it is. */
    public function setAudioDelay(float $seconds): void
    {
        if ($seconds == $this->out->audioDelay) {
            return;
        }
        $this->out->audioDelay = $seconds;
        $this->go($this->time(), true); // the sound given again at its new times
    }

    public function audioDelay(): float
    {
        return $this->out->audioDelay;
    }

    /** IINA's equalizer's bands, their frequencies in Hz (FFmpeg's equalizer a band, as IINA's filter for mpv). */
    public const EQUALIZER_BANDS = Equalizer::FREQUENCIES;

    /** @var list<float> the equalizer's gains, dB: all 0, flat, it is off */
    private array $eq = [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0];

    /**
     * Sets IINA's equalizer: a gain of each of EQUALIZER_BANDS, -12 to 12
     * dB, as its sliders; all 0 (Flat) turns it off, the audio then played
     * as the system decodes it, untouched. Otherwise the audio is decoded
     * here, filtered (FFmpeg's equalizer a band, IINA's filter for mpv) and
     * played as PCM; it goes on from where it is, filtered so.
     * @param list<float> $gains
     */
    public function setEqualizer(array $gains): void
    {
        if (count($gains) !== count(self::EQUALIZER_BANDS)) {
            throw new RuntimeException('myphp: the equalizer has ' . count(self::EQUALIZER_BANDS) . ' bands, not ' . count($gains));
        }
        $gains = array_map('floatval', array_values($gains));
        foreach ($gains as $g) {
            if (!is_finite($g) || abs($g) > 12) { throw new RuntimeException("myphp: an equalizer's gain is from -12 to 12 dB, not $g"); }
        }
        if ($gains === $this->eq) {
            return;
        }
        $was = $this->eq;
        $this->eq = $gains;
        $this->out->setEqualizer($gains);
        if (!Equalizer::flat($was) || !Equalizer::flat($gains)) {
            $this->go($this->time(), true); // the sound given again, filtered from here
        }
    }

    /** The equalizer's gains, dB, of EQUALIZER_BANDS. @return list<float> */
    public function equalizer(): array
    {
        return $this->eq;
    }

    private static function ratio(?float $r, string $what): ?float
    {
        if ($r !== null && $r <= 0) {
            throw new RuntimeException("myphp: $what is positive, not $r");
        }
        return $r;
    }

    /**
     * Calls $fn on $event, one of EVENTS: time, at each tick while it plays
     * (4 a second, and where the subtitles change); ended, when it played to the end;
     * error, with what failed, when the system cannot decode it (the player
     * pauses); play and pause, when it starts and stops playing (at its
     * end and on an error too); rate, with the new rate; seek, with where
     * a seek went, once it is there; track, with 'audio', 'subtitle' or 'secondarySubtitle', when
     * that track changes; buffering, true as the clock starts waiting for
     * the network (a film over HTTP) and false as it goes on. Returns a
     * function that removes it.
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
        $this->landPending();
        if (($why = $this->out->failure() ?? $this->readError) !== null) {
            $this->readError = null;
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
        $this->cache();
        $this->pass();
        $this->emit('time');
        $this->wakeForCue();
        $this->wakeForBoundary();
    }

    /**
     * Calls $fn each time it plays past $seconds, as AVPlayer's boundary
     * time observers: playing from before it to it or after, not a seek
     * landing there, within a few milliseconds of it (a timer set for it
     * when it comes before the next tick). Returns a function that removes
     * it.
     */
    public function at(float $seconds, Closure $fn): Closure
    {
        $id = ++$this->boundaryId;
        $this->boundaries[$id] = [$seconds, $fn];
        if ($this->playing) { $this->boundaryWake = null; $this->wakeForBoundary(); }
        return function () use ($id): void { unset($this->boundaries[$id]); };
    }

    /** Tells the boundaries played past since it last looked, in order; one may seek, which goes on from there. */
    private function pass(): void
    {
        $from = $this->passedTo;
        $now = $this->time();
        $this->passedTo = $now;
        if ($from === null || $now <= $from) {
            return;
        }
        $passed = array_filter($this->boundaries, fn(array $b): bool => $b[0] > $from + 1e-6 && $b[0] <= $now + 1e-6);
        uasort($passed, fn(array $a, array $b): int => $a[0] <=> $b[0]);
        foreach ($passed as [, $fn]) {
            $fn();
            if ($this->passedTo !== $now) { return; } // it sought (a loop back): the rest were not passed
        }
    }

    /** Wakes at the next boundary before the next tick, a quarter of a second off: once for each. */
    private function wakeForBoundary(): void
    {
        $now = $this->time();
        $next = null;
        foreach ($this->boundaries as [$at]) {
            if ($at > $now + 1e-6 && ($next === null || $at < $next)) { $next = $at; }
        }
        if ($next === null || $next === $this->boundaryWake || ($wait = ($next - $now) / $this->rate) >= MediaTicker::INTERVAL) {
            return;
        }
        $this->boundaryWake = $next;
        App::after($wait + 0.002, function () use ($next): void { // just past it
            if ($this->playing && $this->boundaryWake === $next) {
                $this->boundaryWake = null;
                $this->pass();
                $this->wakeForBoundary();
            }
        });
    }

    /**
     * Tells the time again where the subtitles shown change before the next
     * tick, a quarter of a second off: a cue shows when it starts, not up
     * to a tick late. Once for each change.
     */
    private function wakeForCue(): void
    {
        $now = $this->time();
        $next = null; // the clock's time of the next change of the subtitles shown, first or secondary
        foreach ([[$this->subtitle, $this->subtitleDelay], [$this->secondary, $this->secondaryDelay]] as [$t, $delay]) {
            $cue = $t === null ? null : ($this->cues[spl_object_id($t)] ?? null)?->next($now - $delay);
            if ($cue !== null) { $next = min($next ?? INF, $cue + $delay); }
        }
        if ($next === null || $next === $this->cueWake || ($wait = ($next - $now) / $this->rate) >= MediaTicker::INTERVAL) {
            return;
        }
        $this->cueWake = $next;
        App::after($wait + 0.002, function () use ($next): void { // just past it
            if ($this->playing && $this->cueWake === $next) {
                $this->cueWake = null;
                $this->emit('time');
                $this->wakeForCue(); // the next change, or this one again if the clock was not there yet (it starts a moment after play())
            }
        });
    }

    /** Reads the packets up to AHEAD seconds past the clock (or past $at, where a seek went), and gives each renderer its own while it takes them. */
    private function feed(?float $at = null): void
    {
        $until = ($at ?? $this->out->time()) + self::AHEAD * max(1.0, $this->rate) + max(0.0, -$this->out->audioDelay); // two seconds of the clock's (faster than the film's at a rate over 1), and the sound an earlier delay plays now
        $last = fn(string $k): float => ($q = $this->queued[$k]) === [] ? -INF : $q[count($q) - 1]->time;
        while (!$this->drained && max($last('video'), $last('audio')) <= $until) {
            try {
                $p = $this->ahead !== [] ? array_shift($this->ahead) : $this->source->next();
                if ($p === null && !$this->source->ready()) { // not here yet (over HTTP): on as it comes
                    break;
                }
            } catch (RuntimeException $e) { // the network failed: told at the next tick
                $this->readError = $e->getMessage();
                break;
            }
            if ($p === null) {
                $this->drained = true;
                break;
            }
            if ($p->track === $this->video || $p->track === $this->audio) {
                $this->readFrom ??= $p->time;
                $this->readTo = max($this->readTo, $p->time + $p->duration);
            }
            if ($p->track === $this->video) {
                $this->queued['video'][] = $p;
                $this->frameAt ??= $p->time;
                if ($this->frameTime <= 0 && $p->duration > 0) { $this->frameTime = $p->duration; } // a container's are rounded (Matroska's to its milliseconds): the track's, when it says
                if ($p->time <= $this->target + 1e-9 && ($this->landed === null || $p->time > $this->landed)) { $this->landed = $p->time; }
            } elseif ($p->track === $this->audio && $p->time + $p->duration + $this->out->audioDelay > $this->target) { // the audio from where the seek went, as it plays delayed
                $this->queued['audio'][] = $p;
            } elseif (Subtitles::readable($p->track)) { // every subtitle track's, for one shown later
                $this->cue($p);
            }
        }
        $this->out->pump($this->drained); // at the end, what the software decoder holds
        $this->give('video');
        $this->give('audio');
    }

    /** Gives the renderer of the $k queued ('video', 'audio') its packets while it takes them. */
    private function give(string $k): void
    {
        $this->out->slice();
        while ($this->queued[$k] !== [] && $this->out->ready($this->queued[$k][0]->track)) {
            $p = array_shift($this->queued[$k]);
            $end = $p->time + $p->duration;
            $this->out->enqueue($p, $k === 'audio' || $end > $this->target); // the video's frames before it decoded, not shown
            $this->fedTo = max($this->fedTo, $end);
        }
    }

    public function __destruct()
    {
        MediaTicker::remove($this);
    }
}
