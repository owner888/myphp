<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use MyPHP\UI\MediaPlayerCommands;

/**
 * IINA's iina.mpv, without mpv: the player's properties by mpv's names, of
 * those MyPHP has the means to give (MediaPlayerCommands::propertyValue);
 * set() and command() carried out as a key binding's (mpv's set and
 * commands, MediaPlayerCommands) at the next frame; beyond IINA's safe
 * options and commands, of the file system's permission. mpv's hooks
 * MyPHP has none of: addHook() throws.
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
        $this->player()->perform([$name, ...array_map('strval', $args)]);
    }

    /** mpv's hooks (on_load and the rest), which MyPHP, playing without mpv, has none of. */
    public function addHook(string $name, int $priority, \Closure $callback): void
    {
        $this->fail("mpv.addHook: MyPHP plays without mpv, and has no hook \"$name\"");
    }
}
