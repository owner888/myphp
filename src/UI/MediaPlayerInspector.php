<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;
use MyPHP\App;
use MyPHP\L10n;
use MyPHP\Media\Player;
use MyPHP\Media\Track;
use MyPHP\Native\ObjC;
use MyPHP\Window;
use MyPHP\WindowOptions;

/**
 * IINA's Inspector (InspectorWindowController), Window > Inspector: a
 * dark HUD window of 456 by 481 under a bar of the tabs General, Tracks,
 * File and Status, laid out as IINA's xib, of the player last in front.
 * General: the video's and the audio's format, codec, decoder, colors,
 * driver, size, bitrate, frame rate, channels and rate; Tracks: a track
 * chosen of all, its id, properties, title, language, file, codec,
 * decoder and what it is; File: its path, title, comment, size, format,
 * duration, chapters and editions; Status: the playback's statistics and
 * a table of properties watched (the settings' watchProperties), added
 * by name and removed. What it shows is read again every second (IINA's
 * timer). The names are what MyPHP plays with (VideoToolbox, libdav1d,
 * AVSampleBufferDisplayLayer, its readers), not mpv's, which IINA shows.
 * What the player has no means to know (the A/V sync, dropped frames,
 * the pixel format and the audio's of hardware decoding) is N/A, as IINA
 * writes what mpv does not say, in the disabled text's color.
 *
 * @internal examples/video (Window > Inspector)
 */
final class MediaPlayerInspector
{
    private const WIDTH = 456, HEIGHT = 481, BAR = 38;
    private static ?Window $window = null;

    /** The codecs' full names, by their short ones (MediaPlayer::codecName). */
    private const CODECS = [
        'h264' => 'H.264 / AVC / MPEG-4 AVC / MPEG-4 part 10', 'hevc' => 'H.265 / HEVC (High Efficiency Video Coding)', 'vp9' => 'Google VP9',
        'av1' => 'Alliance for Open Media AV1', 'aac' => 'AAC (Advanced Audio Coding)', 'ac3' => 'ATSC A/52A (AC-3)', 'eac3' => 'ATSC A/52B (AC-3, E-AC-3)',
        'dts' => 'DCA (DTS Coherent Acoustics)', 'opus' => 'Opus (Opus Interactive Audio Codec)', 'flac' => 'FLAC (Free Lossless Audio Codec)',
        'mp3' => 'MP3 (MPEG audio layer 3)', 'truehd' => 'TrueHD', 'vorbis' => 'Vorbis', 'subrip' => 'SubRip subtitle', 'ass' => 'ASS (Advanced SSA) subtitle',
        'ssa' => 'SSA (SubStation Alpha) subtitle', 'webvtt' => 'WebVTT subtitle', 'mov_text' => '3GPP Timed Text subtitle',
        'hdmv_pgs_subtitle' => 'HDMV Presentation Graphic Stream subtitles', 'dvd_subtitle' => 'DVD subtitles',
    ];

    /** The channel layouts' names, by the count of channels. */
    private const LAYOUTS = [1 => 'mono', 2 => 'stereo', 3 => '3.0', 4 => 'quad', 5 => '5.0', 6 => '5.1', 7 => '6.1', 8 => '7.1'];

    /**
     * Opens the Inspector, or brings it forward (IINA's showWindow), of the
     * player $player gives as each second it is read again (IINA's
     * PlayerCore.lastActive): null shows what it showed, or nothing.
     * @param Closure(): ?MediaPlayerState $player
     */
    public static function open(Closure $player): void
    {
        if (self::$window !== null && !self::$window->isDestroyed()) {
            self::$window->show();
            self::$window->focus();
            return;
        }
        self::$window = new Window(new WindowOptions(title: self::t('iinaInspectorWindowTitle'), width: self::WIDTH, height: self::HEIGHT + self::BAR,
            minWidth: self::WIDTH, minHeight: self::HEIGHT + self::BAR, stateKey: 'inspector', titleBarStyle: 'hiddenInset', vibrancy: 'hud',
            content: Content::view(fn(Context $c) => self::build($c, $player()))));
    }

    /** The Inspector's window, null while none is open: a panel of the player last in front, which keeps the player's menus as IINA's utility panel does. */
    public static function window(): ?Window
    {
        return self::$window !== null && !self::$window->isDestroyed() ? self::$window : null;
    }

    /** The Inspector's view of player $s. @internal tests */
    public static function build(Context $c, ?MediaPlayerState $s): Node
    {
        $root = $c->themed(Theme::dark(), 0, 0, 1);
        $root->color = null; // the HUD's material, the window's
        $make = fn() => (object)['tab' => 0, 'track' => 0, 'chosen' => -1, 'watch' => new ListState()];
        $v = $c->runtime?->local($root->id, $make) ?? $make();
        $p = $s?->player;
        $c->runtime?->askWindow('title', self::t('iinaInspectorWindowTitle'));
          $bar = $c->titleBar();
          $c->row(0, 12)->height(max($bar['height'], self::BAR))->fillWidth()->paddingStart($bar['left'] + 12)->alignItems(Node::CENTER)->dragWindow(); // the compact toolbar: the title after the window's controls, the tabs
            $c->text(self::t('iinaInspectorWindowTitle'), 13)->fontWeight(700);
            $tabs = [self::t('iinaInspectorTabGeneral'), self::t('iinaInspectorTabTracks'), self::t('iinaInspectorTabFile'), self::t('iinaInspectorTabStatus')];
            $c->segmented($tabs, $v->tab);
          $c->end();
          $pane = $c->column()->grow(1)->alignSelf(Node::STRETCH)->margin(8, 0, 8, 12); // IINA's tab view, at 12 from the left and 8 from the top and bottom
          if ($p !== null) {
              match ($v->tab) {
                  0 => self::general($c, $s, $p),
                  1 => self::tracks($c, $v, $p),
                  2 => self::file($c, $p),
                  default => self::status($c, $v, $s, $p, $pane),
              };
          }
          $c->end();
        $c->end();
        $c->after(1.0); // IINA's updateTimer: once a second
        return $root;
    }

    /** General: the video's and the audio's, as IINA's first tab. */
    private static function general(Context $c, MediaPlayerState $s, Player $p): void
    {
        $vt = $p->videoTrack();
        $at = $p->audioTrack();
        [$w, $h] = $p->size();
        $c->text(self::t('iinaInspectorVideo'), 13)->fontWeight(700)->absolute()->left(10)->top(8);
        $rows = [
            ['iinaInspectorFormat', $vt === null ? null : MediaPlayer::codecName($vt->codec)],
            ['iinaInspectorCodec', $vt === null ? null : self::CODECS[MediaPlayer::codecName($vt->codec)] ?? null],
            ['iinaInspectorHwDecoder', $p->hardwareDecoding() ? \MyPHP\App::backend()->media()->names()['hwdec'] : null], // none of AV1 in software (libdav1d)
            ['iinaInspectorPrimaries', $vt === null ? null : self::primaries($vt)],
            ['iinaInspectorColorspace', $vt === null ? null : 'Unspecified (' . ($p->hdr() && $s->hdr && $s->hdrAvailable() ? 'H' : 'S') . 'DR)'], // the layer's, which AVFoundation chooses: none set
            ['iinaInspectorPixelFormat', null],
            ['iinaInspectorDriver', $vt === null ? null : \MyPHP\App::backend()->media()->names()['video']],
            ['iinaInspectorSize', "{$w}×{$h}"],
            ['iinaInspectorBitRate', self::bitrate($p->bitrate('video'))],
            ['iinaInspectorFPS', $vt === null || $vt->frameDuration <= 0 ? null : sprintf('%.6f', 1 / $vt->frameDuration)],
        ];
        foreach ($rows as $i => [$label, $value]) { self::field($c, 36 + 22 * $i, 10, 78, 94, 340, self::t($label), $value); }
        $c->box(420, 1, 0xffffff)->opacity(0.1)->absolute()->left(12)->top(260); // IINA's separator
        $c->text(self::t('iinaInspectorAudio'), 13)->fontWeight(700)->absolute()->left(10)->top(273);
        $rows = [
            ['iinaInspectorFormat', null], // the decoded samples', which the system's renderer decodes
            ['iinaInspectorCodec', $at === null ? null : self::CODECS[MediaPlayer::codecName($at->codec)] ?? null],
            ['iinaInspectorDriver', $at === null ? null : \MyPHP\App::backend()->media()->names()['audio']],
            ['iinaInspectorChannels', $at === null || $at->channels <= 0 ? null : self::LAYOUTS[$at->channels] ?? "{$at->channels}ch"],
            ['iinaInspectorBitRate', self::bitrate($p->bitrate('audio'))],
            ['iinaInspectorSampleRate', $at === null || $at->sampleRate <= 0 ? null : (string)(int)$at->sampleRate],
        ];
        foreach ($rows as $i => [$label, $value]) { self::field($c, 301 + 22 * $i, 10, 78, 94, 340, self::t($label), $value); }
    }

    /** Tracks: a track of all chosen, what it is, as IINA's second tab. */
    private static function tracks(Context $c, object $v, Player $p): void
    {
        $all = []; $labels = [];
        foreach ([[Track::VIDEO, $p->videoTrack() === null ? [] : [$p->videoTrack()], 'iinaTrackVideo'], [Track::AUDIO, $p->audioTracks(), 'iinaTrackAudio'], [Track::SUBTITLE, $p->subtitleTracks(), 'iinaTrackSub']] as [$type, $tracks, $key]) {
            foreach ($tracks as $i => $t) {
                $all[] = [$t, $i + 1];
                $labels[] = self::t($key) . MediaPlayer::trackName($t, MediaPlayer::trackDetails($t)); // IINA's: the type, then its readable title, run together
            }
        }
        $c->text(self::t('iinaInspectorTrack'), 13)->absolute()->left(10)->top(12);
        if ($all === []) { return; }
        $v->track = min($v->track, count($all) - 1);
        $c->select($labels, $v->track, 372)->absolute()->left(60)->top(12);
        $c->box(420, 1, 0xffffff)->opacity(0.1)->absolute()->left(12)->top(44);
        [$t, $id] = $all[$v->track];
        $selected = $t === $p->videoTrack() || $t === $p->audioTrack() || $t === $p->subtitleTrack() || $t === $p->secondarySubtitleTrack();
        $external = $p->subtitlePath($t) !== null || $t->number < 0;
        self::field($c, 57, 10, 78, 94, 340, self::t('iinaInspectorID'), (string)$id);
        $c->text(self::t('iinaInspectorProperties'), 11)->fontWeight(700)->absolute()->left(10)->top(79)->width(78);
        foreach ([[94, 'iinaInspectorDefault', $t->isDefault], [140, 'iinaInspectorForced', false], [185, 'iinaInspectorSelected', $selected], [240, 'iinaInspectorExternal', $external]] as [$x, $label, $on]) {
            $c->text(self::t($label), 11)->opacity($on ? 1.0 : 0.25)->absolute()->left($x)->top(79); // IINA's: the label's color or the disabled text's
        }
        $lang = MediaPlayer::language($t->language, $c->locale());
        $codec = MediaPlayer::codecName($t->codec);
        $rows = [
            [101, 'iinaInspectorSourceID', $external ? null : (string)$t->number],
            [123, 'iinaInspectorTitle', $t->name !== '' ? $t->name : null],
            [143, 'iinaInspectorLanguage', $lang !== '' ? $lang : null],
            [163, 'iinaInspectorTrackFilePath', $p->subtitlePath($t)],
            [183, 'iinaInspectorCodec', $codec],
            [203, 'iinaInspectorDecoder', $selected ? self::decoder($p, $t, $codec) : null], // of a track decoded
            [223, 'iinaInspectorFPS', $t->frameDuration > 0 ? MediaPlayerState::number(round(1 / $t->frameDuration, 6)) : null],
            [243, 'iinaInspectorChannels', $t->channels > 0 ? self::LAYOUTS[$t->channels] ?? "{$t->channels}ch" : null],
            [263, 'iinaInspectorSampleRate', $t->sampleRate > 0 ? (string)(int)$t->sampleRate : null],
        ];
        foreach ($rows as [$y, $label, $value]) { self::field($c, $y, 10, 78, 94, 340, self::t($label), $value); }
    }

    /** File: the file's, as IINA's third tab. */
    private static function file(Context $c, Player $p): void
    {
        $c->text(self::t('iinaInspectorFilePath'), 11)->fontWeight(700)->absolute()->left(10)->top(12);
        $c->text($p->path(), 11)->absolute()->left(10)->top(30)->width(424)->singleLine()->ellipsis('…')->selectable();
        $c->box(420, 1, 0xffffff)->opacity(0.1)->absolute()->left(12)->top(56);
        $title = $p->title();
        $size = $p->fileSize();
        $chapters = count($p->chapters());
        $rows = [
            ['iinaInspectorTitle', $title !== basename($p->path()) && $title !== '' ? $title : null], // IINA's: none where the title is the file's name
            ['iinaInspectorComment', null],
            ['iinaInspectorSize', $size === null ? null : MediaPlayerState::byteCount($size, false) . 'B'],
            ['iinaInspectorFormat', $p->fileFormat()],
            ['iinaInspectorDuration', MediaPlayerState::clock($p->duration())],
            ['iinaInspectorChapters', (string)$chapters],
            ['iinaInspectorEditions', null],
        ];
        foreach ($rows as $i => [$label, $value]) { self::field($c, 69 + 22 * $i, 10, 62, 78, 356, self::t($label), $value); }
    }

    /** Status: the playback's statistics and the properties watched, as IINA's fourth tab. */
    private static function status(Context $c, object $v, MediaPlayerState $s, Player $p, Node $pane): void
    {
        $screen = ObjC::msg('p:', ObjC::cls('NSScreen'), 'mainScreen');
        $fps = $screen === null ? null : sprintf('%.6f', (float)ObjC::msg('l:', $screen, 'maximumFramesPerSecond')); // the screen's refresh rate
        $rows = [['iinaInspectorAVSyncDiff', null], ['iinaInspectorTotalAVSync', null], ['iinaInspectorDroppedFrames', null], ['iinaInspectorMistimedFrames', null],
            ['iinaInspectorDisplayFPS', $fps], ['iinaInspectorEstimatedOutputFPS', null], ['iinaInspectorEstimatedDispFPS', null]];
        foreach ($rows as $i => [$label, $value]) { self::field($c, 12 + 20 * $i, 10, 131, 147, 287, self::t($label), $value); }
        $c->box(420, 1, 0xffffff)->opacity(0.1)->absolute()->left(12)->top(158);
        $c->text(self::t('iinaInspectorWatch'), 11)->fontWeight(700)->absolute()->left(10)->top(171);
        $set = $s->settings();
        $names = $set->get('watchProperties');
        $table = $c->column()->absolute()->left(12)->top(197)->right(12)->bottom(40)->background(0xaaaaaa, 0.1); // IINA's watchTableBackgroundColor
          $c->row()->height(17)->fillWidth()->background(0x0d0d0d); // the headers, small and bold, over IINA's dark header color
            $c->text(self::t('iinaInspectorName'), 11)->fontWeight(700)->width(180)->paddingX(4);
            $c->text(self::t('iinaInspectorValue'), 11)->fontWeight(700)->grow(1)->paddingX(4);
          $c->end();
          $c->column()->grow(1)->fillWidth();
            $c->list($v->watch, count($names), function (int $i) use ($c, $v, $s, $names): void {
                $value = MediaPlayerCommands::propertyValue($s, $names[$i]);
                $row = $c->row()->height(17)->fillWidth()->alignItems(Node::CENTER);
                if ($i === $v->chosen) { $row->background($c->theme->accent); }
                  $c->text($names[$i], 11)->width(180)->paddingX(4)->singleLine()->ellipsis('…');
                  $value === null ? $c->text(self::t('iinaInspectorError'), 11)->italic()->opacity(0.25)->grow(1)->paddingX(4)
                      : $c->text($value, 11)->grow(1)->paddingX(4)->singleLine()->ellipsis('…')->selectable();
                $c->end();
                if ($c->clicked($row)) { $v->chosen = $i; }
            });
          $c->end();
        $c->end();
        $add = $c->column()->size(16, 20)->absolute()->left(12)->bottom(10)->center()->role(Role::BUTTON)->label(self::t('iinaAlertAddWatchTitle'));
          MediaPlayer::sf($c, 'plus', 11, 0.9); // NSAddTemplate
        $c->end();
        $remove = $c->column()->size(16, 20)->absolute()->left(28)->bottom(10)->center()->role(Role::BUTTON)->label('-');
          MediaPlayer::sf($c, 'minus', 11, $v->chosen >= 0 ? 0.9 : 0.25); // NSRemoveTemplate, enabled while a row is chosen
        $c->end();
        if ($c->clicked($add)) { // IINA's quickPromptPanel("add_watch")
            $name = \MyPHP\Dialog::prompt(Window::focused(), self::t('iinaAlertAddWatchTitle'), '', self::t('iinaAlertAddWatchMessage'));
            if ($name !== null && trim($name) !== '') { $s->setSetting('watchProperties', [...$names, trim($name)]); $v->chosen = count($names); }
        }
        if ($v->chosen >= 0 && $c->clicked($remove)) {
            $s->setSetting('watchProperties', array_values(array_filter($names, fn(int $i) => $i !== $v->chosen, ARRAY_FILTER_USE_KEY)));
            $v->chosen = -1;
        }
    }

    /** A label at ($lx, $y), $lw wide, small and bold, and its value at ($vx, $y), $vw wide: N/A in the disabled text's color where it has none (IINA's setLabelColor). */
    private static function field(Context $c, float $y, float $lx, float $lw, float $vx, float $vw, string $label, ?string $value): void
    {
        $c->text($label, 11)->fontWeight(700)->absolute()->left($lx)->top($y)->width($lw)->singleLine();
        $t = $c->text($value ?? self::t('iinaGeneralNa'), 11)->absolute()->left($vx)->top($y)->width($vw)->singleLine()->ellipsis('…');
        $value === null ? $t->opacity(0.25) : $t->selectable();
    }

    /** IINA's bit rate: FloatingPointByteCountFormatter's of the bits a second, "bps" after it; N/A before it is known. */
    private static function bitrate(?float $bits): ?string
    {
        return $bits === null ? null : MediaPlayerState::byteCount(round($bits), false) . 'bps';
    }

    /** A picture's primaries and transfer by their standards' short names, "bt.709 / bt.1886 (SDR)", as the container says them, guessed by its size where it says none (as CoreMedia::colorNames). */
    private static function primaries(Track $t): string
    {
        $c = $t->colors;
        $hd = $t->width >= 1280 || $t->height > 576;
        $pri = $c['primaries'] ?? 2;
        $primaries = match ($pri === 2 ? ($hd ? 1 : ($t->height === 576 ? 5 : 6)) : $pri) {
            9 => 'bt.2020', 12 => 'display-p3', 5 => 'bt.601-625', 6, 7 => 'bt.601-525', 4 => 'bt.470m', 11 => 'dci-p3', default => 'bt.709',
        };
        $gamma = match ($c['transfer'] ?? 2) { 16 => 'pq', 18 => 'hlg', 13 => 'srgb', 8 => 'linear', 4 => 'gamma2.2', 5 => 'gamma2.8', default => 'bt.1886' };
        return "$primaries / $gamma (" . ($gamma === 'pq' || $gamma === 'hlg' ? 'H' : 'S') . 'DR)';
    }

    /** What decodes a track decoded and the codec's full name: VideoToolbox or libdav1d, AudioToolbox or MyPHP's Vorbis, MyPHP's subtitles. */
    private static function decoder(Player $p, Track $t, string $codec): ?string
    {
        $by = match ($t->type) { Track::VIDEO => $p->videoDecoder(), Track::AUDIO => $p->audioDecoder(), default => 'MyPHP' };
        $desc = self::CODECS[$codec] ?? $codec;
        return $by === null ? null : "$by ($desc)";
    }

    private static function t(string $key, array $args = []): string
    {
        return L10n::in(App::locale())->get($key, $args);
    }
}
