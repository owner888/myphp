<?php
declare(strict_types=1);

namespace MyPHP\UI;

use MyPHP\Dialog;
use MyPHP\Media\Player;
use MyPHP\Logger;
use MyPHP\OpenDialogOptions;
use MyPHP\Shell;
use MyPHP\Window;

/**
 * The actions of the player's key bindings carried out: mpv's commands as
 * mpv's do what they say of the player (seek, set, add, cycle,
 * cycle-values, multiply of its properties, frame-step, ab-loop,
 * playlist-next…) and IINA's own (its panels, picture in picture, music
 * mode, the window's size…). A property or command the player has no
 * means for is logged and does nothing (video-zoom, deinterlace, edition…).
 *
 * @internal MediaPlayer
 */
final class MediaPlayerCommands
{
    /** mpv's words before a command that change how it shows, not what it does. */
    private const PREFIXES = ['no-osd', 'osd-auto', 'osd-bar', 'osd-msg', 'osd-msg-bar', 'raw', 'repeatable', 'expand-properties', 'async', 'sync'];

    /** Carries out the action of binding $m (MediaPlayerKeys::mapping's) in the player of $s. */
    public static function run(Context $c, MediaPlayerState $s, Player $p, array $m): void
    {
        $words = array_values(array_filter($m['action'], fn(string $w) => !in_array($w, self::PREFIXES, true)));
        if ($words === []) { return; }
        Logger::verbose('Key binding: ' . MediaPlayerKeys::rawAction($m), 'keybindings');
        $m['iina'] ? self::iina($c, $s, $p, $words[0]) : self::mpv($c, $s, $p, $words);
    }

    /** @param list<string> $w */
    private static function mpv(Context $c, MediaPlayerState $s, Player $p, array $w): void
    {
        $args = array_map(fn(string $a) => trim($a, '"\''), array_slice($w, 1));
        switch ($w[0]) {
            case 'ignore': return;
            case 'seek':
                $flags = explode('+', $args[1] ?? 'relative');
                $v = (float)($args[0] ?? 0);
                $percent = array_filter($flags, fn(string $f) => str_ends_with($f, 'percent')) !== [];
                $to = $percent ? $p->duration() * $v / 100 : $v;
                $exact = in_array('exact', $flags, true) || (!in_array('keyframes', $flags, true) && array_filter($flags, fn(string $f) => str_starts_with($f, 'absolute')) !== []); // absolute seeks are exact, as mpv's hr-seek
                array_filter($flags, fn(string $f) => str_starts_with($f, 'absolute')) !== [] ? $s->seekBy($to - $p->time(), $exact) : $s->seekBy($to, $exact);
                return;
            case 'revert-seek': $s->revertSeek(); return;
            case 'frame-step': $s->frameStep(); return;
            case 'frame-back-step': $s->frameStep(true); return;
            case 'ab-loop': $s->abLoop(); return;
            case 'sub-seek': $s->subSeek((int)($args[0] ?? 1)); return;
            case 'sub-step': $s->subSeek((int)($args[0] ?? 1), true); return;
            case 'playlist-next': $s->next(); return;
            case 'playlist-prev': $s->previous(); return;
            case 'playlist-shuffle': $s->playlist?->shuffle(); return;
            case 'playlist-clear': $s->clearPlaylist(); return;
            case 'playlist-remove': $s->removeFromPlaylist(); return;
            case 'screenshot': $s->screenshot(); return;
            case 'stop': $s->stop(); return;
            case 'quit': case 'quit-watch-later': case 'close': $s->quit($w[0] === 'quit-watch-later'); return;
            case 'write-watch-later-config': $s->note(); return;
            case 'show-progress': $s->showProgress(); return;
            case 'show-text': $s->say(implode(' ', $args)); return;
            case 'set': self::property($c, $s, $p, $args[0] ?? '', 'set', $args[1] ?? ''); return;
            case 'add': self::property($c, $s, $p, $args[0] ?? '', 'add', $args[1] ?? '1'); return;
            case 'multiply': self::property($c, $s, $p, $args[0] ?? '', 'multiply', $args[1] ?? '1'); return;
            case 'cycle': self::property($c, $s, $p, $args[0] ?? '', 'cycle', $args[1] ?? 'up'); return;
            case 'cycle-values':
                $prop = $args[0] ?? '';
                $values = array_slice($args, 1);
                if ($prop === '!reverse') { $prop = $args[1] ?? ''; $values = array_reverse(array_slice($args, 2)); }
                if ($values === []) { return; }
                $now = self::value($c, $s, $p, $prop);
                $i = array_search($now, $values, true);
                self::property($c, $s, $p, $prop, 'set', $values[$i === false ? 0 : ($i + 1) % count($values)]);
                return;
            default:
                Logger::warn("The player has no means for mpv's command \"{$w[0]}\"", 'keybindings');
        }
    }

    /** The property's value as mpv writes it, of those cycle-values goes through; null for one it has none of. */
    private static function value(Context $c, MediaPlayerState $s, Player $p, string $prop): ?string
    {
        return match ($prop) {
            'pause' => $p->playing() ? 'no' : 'yes',
            'mute' => $p->muted() ? 'yes' : 'no',
            'fullscreen' => ($c->runtime?->fullScreen ?? false) ? 'yes' : 'no',
            'ontop' => $s->onTop ? 'yes' : 'no',
            'sub-visibility' => $s->subtitlesVisible ? 'yes' : 'no',
            'secondary-sub-visibility' => $s->secondarySubtitlesVisible ? 'yes' : 'no',
            'loop', 'loop-file' => $s->loop === 'file' ? 'inf' : 'no',
            'loop-playlist' => $s->loop === 'playlist' ? 'inf' : 'no',
            'speed' => MediaPlayerState::number($p->rate()),
            'window-scale' => MediaPlayerState::number(self::windowScale($c, $p)),
            'video-aspect-override' => ($a = $p->aspectRatio()) === null ? '-1' : (string)$a,
            'hwdec' => $p->hardwareDecoding() ? 'auto' : 'no',
            'brightness', 'contrast', 'saturation', 'gamma', 'hue' => (string)$p->videoEqualizer($prop),
            'sub-ass-override' => ['yes', 'force', 'strip', 'scale', 'no'][$s->assOverride ?? $s->settings()->get('subOverrideLevel')],
            default => null,
        };
    }

    /**
     * Property $prop of player $s by mpv's name of it, which key bindings
     * and IINA's watch table take, written as mpv's getString writes it:
     * yes or no, a number of six decimals, a string; of what MyPHP plays
     * with, its own names (VideoToolbox, AVSampleBufferDisplayLayer, its
     * readers); null for one the player has no means to give (IINA's Error).
     * @internal MediaPlayerInspector
     */
    public static function propertyValue(MediaPlayerState $s, string $prop): ?string
    {
        $p = $s->player;
        if ($p === null) { return null; }
        $yes = fn(bool $b): string => $b ? 'yes' : 'no';
        $num = fn(float $v): string => sprintf('%.6f', $v);
        $l = $s->playlist;
        return match ($prop) {
            'path' => $p->path(),
            'filename' => basename($p->path()),
            'media-title' => $p->title(),
            'file-format' => $p->fileFormat(),
            'file-size' => ($n = $p->fileSize()) === null ? null : (string)$n,
            'duration' => $num($p->duration()),
            'time-pos' => $num($p->time()),
            'time-remaining' => $num(max(0.0, $p->duration() - $p->time())),
            'percent-pos' => $p->duration() > 0 ? $num($p->time() / $p->duration() * 100) : null,
            'pause' => $yes(!$p->playing()),
            'speed' => $num($p->rate()),
            'volume' => $num($s->volume * 100),
            'mute' => $yes($p->muted()),
            'audio-delay' => $num($p->audioDelay()),
            'sub-delay' => $num($p->subtitleDelay()),
            'sub-scale' => $num($s->subScale),
            'sub-visibility' => $yes($s->subtitlesVisible),
            'secondary-sub-visibility' => $yes($s->secondarySubtitlesVisible),
            'width' => $p->videoTrack() === null ? null : (string)$p->size()[0],
            'height' => $p->videoTrack() === null ? null : (string)$p->size()[1],
            'chapter' => $p->chapters() === [] ? null : (string)$p->chapter(),
            'chapters' => (string)count($p->chapters()),
            'playlist-pos' => $l === null ? '-1' : (string)$l->index(),
            'playlist-count' => (string)($l?->count() ?? 0),
            'loop-file' => $s->loop === 'file' ? 'inf' : 'no',
            'loop-playlist' => $s->loop === 'playlist' ? 'inf' : 'no',
            'ontop' => $yes($s->onTop),
            'fullscreen' => $yes($s->fullScreen()),
            'hwdec-current' => $p->videoTrack() === null ? null : ($p->hardwareDecoding() ? 'VideoToolbox' : 'no'), // MyPHP's names, as the Inspector's
            'video-rotate' => (string)$p->rotation(),
            'brightness', 'contrast', 'saturation', 'gamma', 'hue' => (string)$p->videoEqualizer($prop),
            'video-bitrate' => ($b = $p->bitrate('video')) === null ? null : (string)(int)round($b),
            'audio-bitrate' => ($b = $p->bitrate('audio')) === null ? null : (string)(int)round($b),
            'current-vo' => Player::VIDEO_OUTPUT,
            'current-ao' => Player::AUDIO_OUTPUT,
            default => null,
        };
    }

    /** The window's scale: its width over the film's. */
    private static function windowScale(Context $c, Player $p): float
    {
        [$w] = $p->size();
        return $w > 0 ? $c->width / $w : 1.0;
    }

    /** mpv's set, add, multiply and cycle of property $prop by $arg, as the player has the means. */
    private static function property(Context $c, MediaPlayerState $s, Player $p, string $prop, string $op, string $arg): void
    {
        $bool = fn(bool $now): bool => match ($op) { 'set' => $arg === 'yes' || $arg === 'true' || $arg === '1', default => !$now };
        $num = fn(float $now): float => match ($op) { 'set' => (float)$arg, 'add' => $now + (float)$arg, 'multiply' => $now * self::number($arg), default => $now };
        $tracks = function (array $tracks, ?object $current) use ($op, $arg): ?object { // mpv's ids, 1 the first, no none: the next, the one set
            if ($op === 'set') { return $arg === 'no' || $arg === '0' ? null : ($tracks[(int)$arg - 1] ?? $current); }
            $i = $current === null ? -1 : (int)array_search($current, $tracks, true);
            $n = count($tracks) + 1; // and none
            $i = ($i + ($op === 'cycle' && $arg === 'down' ? -1 : ($op === 'add' ? (int)$arg : 1)) + 1 + $n) % $n - 1;
            return $i < 0 ? null : $tracks[$i];
        };
        switch ($prop) {
            case 'pause': if ($bool(!$p->playing()) === $p->playing()) { $s->togglePause(); } return;
            case 'mute': if ($bool($p->muted()) !== $p->muted()) { $s->toggleMute(); } return;
            case 'volume': $s->setVolume($num($s->volume * 100) / 100); return;
            case 'speed': $s->setSpeed($num($p->rate())); return;
            case 'audio-delay': $s->setAudioDelay($num($p->audioDelay())); return;
            case 'sub-delay': $s->setSubtitleDelay($num($p->subtitleDelay())); return;
            case 'secondary-sub-delay': $p->setSecondarySubtitleDelay($num($p->secondarySubtitleDelay())); return;
            case 'sub-visibility': if ($bool($s->subtitlesVisible) !== $s->subtitlesVisible) { $s->toggleSubtitles(); } return;
            case 'secondary-sub-visibility':
                if ($bool($s->secondarySubtitlesVisible) !== $s->secondarySubtitlesVisible) {
                    $s->toggleSecondarySubtitles();
                    $s->say($c->texts()->get($s->secondarySubtitlesVisible ? 'videoSecondarySubVisible' : 'videoSecondarySubHidden'));
                }
                return;
            case 'sub-scale': $s->setSubScale($num($s->subScale)); return;
            case 'sub-pos': $s->setSubPos($num($s->subPos ?? (float)$s->settings()->get('subPos'))); return;
            case 'fullscreen': $c->runtime?->askWindow('fullScreen', $bool($c->runtime->fullScreen)); return;
            case 'ontop': $s->onTop = $bool($s->onTop); return;
            case 'window-scale': $s->windowScale(max(0.125, $num(self::windowScale($c, $p)))); return;
            case 'loop': case 'loop-file':
                $on = $op === 'cycle' ? $s->loop !== 'file' : !in_array($arg, ['no', '0', 'false'], true);
                $s->setLooping($on ? 'file' : 'off');
                return;
            case 'loop-playlist':
                $on = $op === 'cycle' ? $s->loop !== 'playlist' : !in_array($arg, ['no', '0', 'false'], true);
                $s->setLooping($on ? 'playlist' : 'off');
                return;
            case 'chapter':
                $chapters = $p->chapters();
                if ($chapters === []) { return; }
                $i = (int)max(0, min(count($chapters) - 1, $op === 'set' ? (int)$arg : $p->chapter() + ($op === 'add' ? (int)$arg : 1)));
                $s->playChapter($i);
                $s->say($c->texts()->get('videoChapterTo', ['name' => $chapters[$i]->title]));
                return;
            case 'sub': $p->setSubtitleTrack($tracks($p->subtitleTracks(), $p->subtitleTrack())); return;
            case 'secondary-sid': $p->setSecondarySubtitleTrack($tracks($p->subtitleTracks(), $p->secondarySubtitleTrack())); return;
            case 'audio':
                $audio = array_values(array_filter($p->audioTracks(), [Player::class, 'playable']));
                $t = $tracks($audio, $p->audioTrack()) ?? ($op === 'cycle' ? $audio[0] ?? null : null);
                if ($t !== null) { $p->setAudioTrack($t); }
                return;
            case 'video-aspect-override': case 'video-aspect':
                $a = $arg === '-1' || $arg === 'no' || $arg === '0' ? null : (str_contains($arg, ':') ? self::number(str_replace(':', '/', $arg)) : (float)$arg);
                $p->setAspectRatio($a);
                $s->say($c->texts()->get('videoAspectTo', ['ratio' => $a === null ? $c->texts()->get('videoDefault') : $arg]));
                return;
            case 'hwdec':
                $on = $op === 'cycle' ? !$p->hardwareDecoding() : !in_array($arg, ['no', 'false', '0'], true);
                $s->setHardwareDecoding($on);
                $s->say($c->texts()->get('videoHwdecTo', ['state' => $c->texts()->get($on ? 'videoOn' : 'videoOff')]));
                return;
            case 'brightness': case 'contrast': case 'saturation': case 'gamma': case 'hue': // mpv's video equalizer, cycle going up by 1
                $s->setVideoEqualizer($prop, (int)round($op === 'cycle' ? $p->videoEqualizer($prop) + ($arg === 'down' ? -1 : 1) : $num((float)$p->videoEqualizer($prop))));
                return;
            case 'sub-ass-override':
                $levels = ['yes', 'force', 'strip', 'scale', 'no'];
                $now = $s->assOverride ?? $s->settings()->get('subOverrideLevel');
                $s->assOverride = $op === 'cycle' ? ($now + 1) % 5 : (array_search($arg, $levels, true) ?: 0);
                return;
            default:
                Logger::warn("The player has no means for mpv's property \"$prop\"", 'keybindings');
        }
    }

    /** A number as mpv writes one: "1.1", "1/1.1". */
    private static function number(string $v): float
    {
        if (str_contains($v, '/')) {
            [$a, $b] = explode('/', $v, 2);
            return (float)$b != 0.0 ? (float)$a / (float)$b : 1.0;
        }
        return (float)$v;
    }

    /** IINA's own commands (IINACommand). */
    private static function iina(Context $c, MediaPlayerState $s, Player $p, string $cmd): void
    {
        switch ($cmd) {
            case 'video-panel': case 'audio-panel': case 'sub-panel':
                $tab = ['video-panel' => 'video', 'audio-panel' => 'audio', 'sub-panel' => 'subtitles'][$cmd];
                $s->sidebar = $s->sidebar === $tab ? null : $tab; $s->tab = $tab; // the quick settings at its tab, or hidden (IINA's toggle)
                return;
            case 'playlist-panel': case 'chapter-panel':
                $tab = $cmd === 'playlist-panel' ? 'playlist' : 'chapters';
                $s->sidebar = $s->sidebar === 'playlist' && $s->playlistTab === $tab ? null : 'playlist'; $s->playlistTab = $tab;
                return;
            case 'toggle-pip': $s->togglePictureInPicture(); return;
            case 'flip': $s->setFlip(!$s->flip); return; // IINA's menuToggleFlip
            case 'mirror': $s->setMirror(!$s->mirror); return;
            case 'toggle-music-mode': $s->toggleMusicMode(); return;
            case 'bigger-window': case 'smaller-window': $c->runtime?->askWindow('magnify', ($cmd === 'bigger-window' ? 25 : -25) / max(1.0, $c->width)); return; // IINA's: 25 points wider or narrower
            case 'fit-to-screen': $s->windowScale(-1.0); return;
            case 'show-current-file-in-finder': if (!$p->remote()) { Shell::showItemInFolder($p->path()); } return;
            case 'delete-current-file': case 'delete-current-file-hard': $s->deleteCurrentFile($cmd === 'delete-current-file-hard'); return;
            case 'find-online-subs': $s->searchOnlineSubtitles(); return;
            case 'open-file':
                $paths = Dialog::open(new OpenDialogOptions(parent: Window::focused(), multiple: true));
                if ($paths !== []) { $s->open($paths[0]); }
                return;
            case 'save-playlist': $s->savePlaylist(); return;
            case 'live-text': $s->setSetting('enableLiveText', !$s->settings()->get('enableLiveText')); return; // IINA's menuToggleLiveText
            default:
                Logger::warn("The player has no means for IINA's command \"$cmd\"", 'keybindings');
        }
    }
}
