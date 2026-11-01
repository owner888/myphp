<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use MyPHP\UI\MediaPlayerCommands;

/**
 * IINA's iina.mpv, without mpv: the player's properties by mpv's names, of
 * those MyPHP has the means to give (MediaPlayerCommands::propertyValue);
 * set() and command() carried out as a key binding's (mpv's set and
 * commands, MediaPlayerCommands) at the next frame; beyond IINA's safe
 * options and commands, of the file system's permission. mpv's hooks of
 * a film opening: on_load, on_load_fail and on_preloaded; while one runs,
 * what it gets and sets of the film is the Opening's (stream-open-filename,
 * force-media-title, user-agent, http-header-fields, chapter-list, and the
 * commands audio-add and sub-add), as IINA's Online Media plugin uses them.
 */
final class MpvApi extends Api
{
    /** IINA's safeMPVCommands. */
    public const SAFE_COMMANDS = [
        'seek', 'revert-seek', 'sub-seek', 'frame-step', 'frame-back-step', 'stop', 'drop-buffers', 'ab-loop', 'ab-loop-align-cache',
        'playlist-next', 'playlist-prev', 'playlist-next-playlist', 'playlist-prev-playlist', 'playlist-play-index', 'playlist-clear',
        'playlist-remove', 'playlist-move', 'playlist-shuffle', 'playlist-unshuffle', 'sub-remove', 'sub-reload', 'sub-step',
        'audio-remove', 'audio-reload', 'video-remove', 'video-reload', 'print-text', 'expand-text', 'escape-ass', 'show-text',
        'show-progress', 'overlay-remove', 'osd-overlay', 'screenshot-raw', 'ignore', 'begin-vo-dragging', 'context-menu', 'quit'
    ];
    /** IINA's safeMPVOptions. */
    public const SAFE_OPTIONS = [
        'aid', 'vid', 'sid', 'secondary-sid', 'alang', 'slang', 'vlang', 'edition', 'track-auto-selection', 'subs-with-matching-audio',
        'subs-match-os-language', 'subs-fallback', 'subs-fallback-forced', 'start', 'end', 'length', 'frames', 'speed', 'pitch', 'pause',
        'sstep', 'correct-pts', 'container-fps-override', 'loop-file', 'loop-playlist', 'ab-loop-a', 'ab-loop-b', 'ab-loop-count',
        'play-direction', 'rebase-start-time', 'hr-seek', 'hr-seek-framedrop', 'deinterlace', 'deinterlace-field-parity', 'hwdec',
        'hwdec-codecs', 'video-aspect-override', 'video-aspect-method', 'video-rotate', 'video-crop', 'video-zoom', 'video-pan-x',
        'video-pan-y', 'video-align-x', 'video-align-y', 'video-unscaled', 'video-scale-x', 'video-scale-y', 'video-recenter',
        'video-margin-ratio-left', 'video-margin-ratio-right', 'video-margin-ratio-top', 'video-margin-ratio-bottom',
        'video-output-levels', 'panscan', 'framedrop', 'video-latency-hacks', 'display-fps-override', 'vd-lavc-skiploopfilter',
        'vd-lavc-skipidct', 'vd-lavc-skipframe', 'vd-lavc-threads', 'vd-lavc-framedrop', 'vd-lavc-fast', 'vd-lavc-film-grain',
        'vd-lavc-dr', 'vd-apply-cropping', 'hwdec-extra-frames', 'hwdec-image-format', 'hwdec-threads', 'hwdec-software-fallback',
        'vd-lavc-check-hw-profile', 'swapchain-depth', 'brightness', 'contrast', 'saturation', 'gamma', 'hue', 'volume', 'volume-max',
        'volume-gain', 'volume-gain-max', 'volume-gain-min', 'mute', 'audio-delay', 'audio-pitch-correction', 'audio-channels',
        'audio-display', 'audio-samplerate', 'audio-format', 'audio-exclusive', 'audio-spdif', 'gapless-audio', 'initial-audio-sync',
        'replaygain', 'replaygain-preamp', 'replaygain-clip', 'replaygain-fallback', 'ad-lavc-ac3drc', 'ad-lavc-downmix',
        'ad-lavc-threads', 'audio-stream-silence', 'audio-wait-open', 'audio-buffer', 'audio-normalize-downmix', 'audio-set-media-role',
        'sub-delay', 'secondary-sub-delay', 'sub-scale', 'sub-scale-signs', 'sub-scale-by-window', 'sub-scale-with-window',
        'sub-ass-scale-with-window', 'sub-pos', 'secondary-sub-pos', 'sub-speed', 'sub-visibility', 'secondary-sub-visibility', 'sub-ass',
        'sub-ass-justify', 'sub-ass-override', 'secondary-sub-ass-override', 'sub-ass-force-margins', 'sub-use-margins',
        'sub-ass-use-video-data', 'sub-vsfilter-bidi-compat', 'sub-ass-vsfilter-color-compat', 'sub-font', 'sub-font-size', 'sub-color',
        'sub-outline-color', 'sub-outline-size', 'sub-back-color', 'sub-shadow-offset', 'sub-bold', 'sub-italic', 'sub-blur',
        'sub-margin-x', 'sub-margin-y', 'sub-align-x', 'sub-align-y', 'sub-justify', 'sub-border-style', 'sub-spacing', 'sub-line-spacing',
        'sub-hinting', 'sub-shaper', 'sub-codepage', 'sub-fix-timing', 'sub-fix-timing-threshold', 'sub-fix-timing-keep',
        'sub-stretch-durations', 'sub-gauss', 'sub-gray', 'sub-forced-events-only', 'sub-fps', 'sub-filter-sdh', 'sub-filter-sdh-harder',
        'sub-filter-sdh-enclosures', 'sub-clear-on-seek', 'sub-create-cc-track', 'sub-past-video-end', 'sub-font-provider', 'sub-hdr-peak',
        'image-subs-hdr-peak', 'sub-ass-style-overrides', 'stretch-dvd-subs', 'stretch-image-subs-to-screen',
        'image-subs-video-resolution', 'embeddedfonts', 'sub-ass-video-aspect-override', 'sub-ass-prune-delay', 'teletext-page',
        'fullscreen', 'geometry', 'ontop', 'keep-open', 'keep-open-pause', 'image-display-duration', 'stop-screensaver', 'user-agent',
        'referrer', 'network-timeout', 'tls-verify', 'rtsp-transport', 'hls-bitrate', 'cache', 'cache-secs', 'cache-pause',
        'cache-pause-wait', 'cache-pause-initial', 'force-seekable', 'http-header-fields', 'cookies', 'demuxer-readahead-secs',
        'demuxer-mkv-subtitle-preroll', 'demuxer-mkv-subtitle-preroll-secs', 'demuxer-lavf-analyzeduration', 'demuxer-lavf-probescore',
        'demuxer-lavf-probesize', 'demuxer-max-bytes', 'demuxer-max-back-bytes', 'audio-resample-filter-size',
        'audio-resample-phase-shift', 'audio-resample-cutoff', 'audio-resample-linear', 'audio-resample-max-output-size', 'video-sync',
        'interpolation'
    ];

    /** Property $property as mpv writes it, null for one the player has no means to give. */
    public function getString(string $property): ?string
    {
        $o = $this->player()->hooking;
        if ($o !== null && in_array($property, ['stream-open-filename', 'path'], true)) { return $property === 'path' ? $o->path : $o->url; }
        return MediaPlayerCommands::propertyValue($this->player(), $property);
    }

    public function getFlag(string $property): bool
    {
        return $this->getString($property) === 'yes';
    }

    public function getNumber(string $property): float
    {
        $v = $this->getString($property);
        return is_numeric($v) ? (float)$v : 0.0;
    }

    /** Its value as mpv's node: a number, yes and no a bool, else the string. */
    public function getNative(string $property): mixed
    {
        return Events::typed($this->getString($property));
    }

    /** Sets $property to $value (a number, a string, a bool, a list), as mpv's set. */
    public function set(string $property, mixed $value): void
    {
        if (!$this->permitted(Plugin::FILE_SYSTEM) && !in_array($property, self::SAFE_OPTIONS, true)) {
            $this->fail("mpv.set: $property needs the file system permission.");
        }
        $o = $this->player()->hooking;
        if ($o !== null && ($property === 'stream-open-filename' || $property === 'chapter-list' || str_starts_with($property, 'file-local-options/'))) {
            $this->setOpening($o, $property, $value);
            return;
        }
        $v = match (true) {
            is_bool($value) => $value ? 'yes' : 'no',
            is_int($value), is_float($value), is_string($value) => (string)$value,
            is_array($value) => implode(',', array_map('strval', $value)),
            default => $this->fail('mpv.set only supports numbers, strings, booleans and objects.'),
        };
        $this->player()->perform(['set', $property, $v]);
    }

    /** Runs mpv's command $name with $args. @param list<string> $args */
    public function command(string $name, array $args = []): void
    {
        if (!$this->permitted(Plugin::FILE_SYSTEM) && !in_array($name, self::SAFE_COMMANDS, true)) {
            $this->fail("mpv.command: $name needs the file system permission.");
        }
        $o = $this->player()->hooking;
        if ($o !== null && ($name === 'audio-add' || $name === 'sub-add')) {
            $url = self::unwrap((string)($args[0] ?? ''));
            if ($url === null) { $this->log("mpv.command: $name of {$args[0]}: the player reads no such URL", \MyPHP\Logger::WARNING); return; }
            if ($name === 'audio-add') {
                $o->audio[] = $url[0];
            } else {
                $ext = $url[1] ?? strtolower(pathinfo((string)parse_url($url[0], PHP_URL_PATH), PATHINFO_EXTENSION));
                if (!isset(\MyPHP\Media\Subtitles::FILES[$ext])) { $this->log("mpv.command: sub-add of {$url[0]}: the player reads no subtitles of \"$ext\"", \MyPHP\Logger::WARNING); return; }
                $o->subtitles[] = ['url' => $url[0], 'name' => (string)($args[2] ?? $ext), 'language' => (string)($args[3] ?? ''), 'ext' => $ext];
            }
            return;
        }
        $this->player()->perform([$name, ...array_map('strval', $args)]);
    }

    /** Hooks $callback(next) to $name of a film opening (mpv's on_load, on_load_fail, on_preloaded; MyPHP has no other), of $priority (lower first). */
    public function addHook(string $name, int $priority, \Closure $callback): void
    {
        if (!in_array($name, ['on_load', 'on_load_fail', 'on_preloaded'], true)) { $this->fail("mpv.addHook: MyPHP plays without mpv, and has no hook \"$name\""); }
        $this->player()->addHook($name, $priority, $this->instance, $callback);
    }

    /** $property of the film opening set to $value (what of it the player has a use for), as a hook sets it. */
    private function setOpening(\MyPHP\Media\Opening $o, string $property, mixed $value): void
    {
        switch ($property) {
            case 'stream-open-filename':
                $o->url = preg_replace('~^data(?!://)~', 'data://', (string)$value) ?? (string)$value;
                return;
            case 'file-local-options/force-media-title':
                $o->title = (string)$value;
                return;
            case 'file-local-options/user-agent':
                $o->headers['User-Agent'] = (string)$value;
                return;
            case 'file-local-options/http-header-fields':
                foreach (is_array($value) ? $value : explode(',', (string)$value) as $field) {
                    [$k, $v] = array_map('trim', explode(':', (string)$field, 2)) + [1 => ''];
                    if ($k !== '') { $o->headers[$k] = $v; }
                }
                return;
            case 'chapter-list':
                $o->chapters = array_map(fn($c) => new \MyPHP\Media\Chapter((string)($c['title'] ?? ''), (float)($c['time'] ?? 0)), array_values(array_filter(is_array($value) ? $value : [], 'is_array')));
                return;
            default:
                $this->log("mpv.set: $property: the player has no use for it", \MyPHP\Logger::WARNING);
        }
    }

    /**
     * $url as the player reads it: one of the web as it is, an EDL of one
     * (mpv's "edl://", as IINA's Online Media plugin wraps a subtitle: its
     * codec's extension with it); [url, extension or null], null for another.
     * @return ?array{0: string, 1: ?string}
     */
    private static function unwrap(string $url): ?array
    {
        if (preg_match('~^https?://~i', $url) === 1) { return [$url, null]; }
        if (!str_starts_with($url, 'edl://')) { return null; }
        $codec = preg_match('~(?:^|[;,])codec=([\w-]+)~', $url, $m) === 1 ? $m[1] : null;
        if (preg_match('~%(\d+)%~', $url, $n, PREG_OFFSET_CAPTURE) !== 1) { return null; }
        $inner = substr($url, $n[0][1] + strlen($n[0][0]), (int)$n[1][0]);
        if (preg_match('~^https?://~i', $inner) !== 1) { return null; }
        return [$inner, match ($codec) { 'webvtt' => 'vtt', 'ass' => 'ass', 'ssa' => 'ssa', 'subrip', 'srt' => 'srt', default => null }];
    }
}
