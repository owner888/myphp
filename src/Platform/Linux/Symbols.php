<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use FFI;
use MyPHP\Logger;
use MyPHP\Platform;
use RuntimeException;

/**
 * The system's icons on Linux (Platform\Symbols): the SF Symbol names the
 * core asks for (the video player's, the log viewer's) drawn as the GTK
 * icon theme's symbolic icons (the freedesktop Icon Naming Spec's names,
 * Adwaita's set), by NAMES: a name, else a second, looked up in the default
 * GtkIconTheme with GTK_ICON_LOOKUP_FORCE_SYMBOLIC at the size in pixels
 * and recolored by gtk_icon_info_load_symbolic. SF Symbols' weight has no
 * equivalent and is not drawn. A name of no mapping (null: no icon of the
 * spec means it), or whose icons the theme lacks, is drawn as a transparent
 * square of the size, so that the UI lays out as it would, and told once in
 * the log: a gap that shows, not another icon in its place
 * (docs/mygo-deviations.md lists them).
 *
 * @internal
 */
final class Symbols implements Platform\Symbols
{
    /** GTK_ICON_LOOKUP_FORCE_SIZE | GTK_ICON_LOOKUP_FORCE_SYMBOLIC. */
    private const LOOKUP = (1 << 4) | (1 << 6);

    /**
     * Each SF Symbol name the core draws and the freedesktop icon(s) of its
     * meaning: [name, second] (the second where the first may be missing),
     * null where none has its meaning.
     * @var array<string, ?list<string>>
     */
    public const NAMES = [
        // Playing
        'play.fill' => ['media-playback-start'], 'play.circle' => ['media-playback-start'], 'play.circle.fill' => ['media-playback-start'],
        'pause.fill' => ['media-playback-pause'], 'pause.circle.fill' => ['media-playback-pause'],
        'playpause' => null, 'playpause.circle' => null,
        'backward.end.fill' => ['media-skip-backward'], 'forward.end.fill' => ['media-skip-forward'],
        'backward.fill' => ['media-seek-backward'], 'forward.fill' => ['media-seek-forward'], 'goforward.15' => ['media-seek-forward'],
        'repeat' => ['media-playlist-repeat'], 'repeat.1' => ['media-playlist-repeat-song'], 'shuffle' => ['media-playlist-shuffle'],
        'play.rectangle' => ['video-x-generic'],
        // Sound
        'speaker.slash.fill' => ['audio-volume-muted'], 'speaker.wave.1.fill' => ['audio-volume-low'], 'speaker.wave.2.fill' => ['audio-volume-medium'],
        'speaker.wave.3' => ['audio-volume-high'], 'speaker.wave.3.fill' => ['audio-volume-high'], 'speaker.plus' => ['audio-volume-overamplified'],
        'hifispeaker.and.homepod' => ['audio-speakers'], 'waveform' => ['audio-x-generic'], 'music.note' => ['audio-x-generic'], 'music.note.list' => ['audio-x-generic'],
        'archivebox' => ['package-x-generic'], 'checkmark' => ['object-select'], 'flag' => ['flag', 'emblem-important'],
        'sidebar.left' => ['sidebar-show', 'view-dual'], 'tray' => ['mail-folder-inbox', 'mail-inbox'],
        'airpods' => ['audio-headphones', 'audio-headset'], 'headphones' => ['audio-headphones', 'audio-headset'],
        'antenna.radiowaves.left.and.right' => ['bluetooth-active', 'bluetooth', 'network-wireless'],
        'microphone.dynamic.on.stand' => ['audio-input-microphone'], 'music.microphone' => ['audio-input-microphone'], 'music.mic' => ['audio-input-microphone'],
        // Windows and views
        'arrow.up.left.and.arrow.down.right' => ['view-fullscreen'], 'arrow.up.backward.and.arrow.down.forward' => ['view-fullscreen'],
        'arrow.up.backward.and.arrow.down.forward.rectangle' => ['view-fullscreen'], 'arrow.up.left.and.arrow.down.right.rectangle' => ['view-fullscreen'], 'rectangle.expand.diagonal' => ['view-fullscreen'],
        'arrow.down.right.and.arrow.up.left' => ['view-restore'], 'arrow.down.forward.and.arrow.up.backward.rectangle' => ['view-restore'],
        'list.bullet' => ['view-list-bullet', 'view-list'], 'list.bullet.rectangle' => ['view-list-bullet', 'view-list'], 'list.bullet.rectangle.fill' => ['view-list-bullet', 'view-list'],
        'rectangle.grid.3x2.fill' => ['view-grid'], 'ellipsis' => ['view-more-horizontal'], 'ellipsis.rectangle' => ['view-more-horizontal'],
        'pin' => ['view-pin'], 'pin.fill' => ['view-pin'], 'pin.square' => ['view-pin'], 'eye.circle.fill' => ['view-reveal'],
        'captions.bubble' => ['media-view-subtitles'], 'captions.bubble.fill' => ['media-view-subtitles'],
        'camera.fill' => ['camera-photo'], 'camera.shutter.button' => ['camera-photo'], 'camera.on.rectangle' => ['applets-screenshooter'],
        'photo' => ['image-x-generic'], 'photo.fill' => ['image-x-generic'], 'photo.tv' => ['video-display'],
        'sun.max' => ['display-brightness'], 'rotate.right' => ['object-rotate-right'],
        // Settings and tools
        'gear' => ['emblem-system', 'preferences-system'], 'gearshape' => ['emblem-system', 'preferences-system'], 'gearshape.fill' => ['emblem-system', 'preferences-system'], 'gearshape.circle' => ['emblem-system', 'preferences-system'],
        'puzzlepiece' => ['application-x-addon'], 'puzzlepiece.fill' => ['application-x-addon'], 'puzzlepiece.extension' => ['application-x-addon'], 'puzzlepiece.extension.fill' => ['application-x-addon'],
        'wrench' => ['applications-engineering'], 'wrench.adjustable' => ['applications-engineering'], 'wrench.and.screwdriver' => ['applications-engineering'], 'flask' => ['applications-science'],
        'keyboard' => ['input-keyboard'], 'computermouse' => ['input-mouse'], 'magicmouse.fill' => ['input-mouse'],
        'globe' => ['web-browser'], 'network' => ['network-workgroup'], 'server.rack' => ['network-server'], 'cylinder.split.1x2' => ['drive-harddisk'],
        'textformat' => ['font-x-generic', 'preferences-desktop-font'], 'textformat.size' => ['preferences-desktop-font', 'font-x-generic'],
        'bold' => ['format-text-bold'], 'italic' => ['format-text-italic'], 'paintpalette' => ['color-select'],
        'pencil' => ['document-edit'], 'lock' => ['changes-prevent', 'system-lock-screen'], 'lock.display' => ['system-lock-screen'],
        'character.book.closed' => ['accessories-dictionary'], 'battery.0' => ['battery-level-0', 'battery-empty'], 'progress.indicator' => ['content-loading'],
        'person.crop.circle' => ['avatar-default', 'system-users'], 'person.crop.circle.fill' => ['avatar-default', 'system-users'],
        'star.fill' => ['starred'], 'star.leadinghalf.fill' => ['semi-starred'], 'star.leadinghalf.filled' => ['semi-starred'],
        // Files and time
        'doc' => ['text-x-generic'], 'doc.fill' => ['text-x-generic'], 'folder' => ['folder'], 'folder.fill' => ['folder'], 'square.grid.3x1.folder.badge.plus' => ['folder-new'],
        'square.and.arrow.down' => ['document-save'], 'trash' => ['user-trash'], 'trash.fill' => ['user-trash'],
        'clock' => ['preferences-system-time'], 'clock.fill' => ['preferences-system-time'], 'calendar.badge.clock' => ['x-office-calendar'],
        'clock.arrow.circlepath' => ['document-open-recent'], 'clock.arrow.trianglehead.counterclockwise.rotate.90' => ['document-open-recent'],
        'doc.badge.clock' => ['document-open-recent'], 'document.badge.clock' => ['document-open-recent'],
        // Actions and states
        'plus' => ['list-add'], 'plus.circle.fill' => ['list-add'], 'minus' => ['list-remove'],
        'clear' => ['edit-clear'], 'xmark.circle.fill' => ['edit-clear', 'window-close'], 'magnifyingglass' => ['system-search', 'edit-find'], 'text.magnifyingglass' => ['edit-find'],
        'info.circle' => ['dialog-information'], 'info.bubble' => ['dialog-information'], 'questionmark.circle' => ['dialog-question', 'help-about'],
        'exclamationmark.triangle' => ['dialog-warning'], 'exclamationmark.circle' => ['dialog-error'], 'checkmark.circle.fill' => ['emblem-ok', 'emblem-default'],
        'nosign' => ['action-unavailable'], 'slash.circle.fill' => ['action-unavailable'],
        'arrow.clockwise' => ['view-refresh'], 'arrow.clockwise.circle' => ['view-refresh'], 'arrow.clockwise.circle.fill' => ['view-refresh'], 'arrow.trianglehead.2.clockwise.rotate.90' => ['view-refresh'],
        'arrow.counterclockwise' => ['edit-undo'], 'arrow.counterclockwise.circle.fill' => ['edit-undo'], 'arrow.uturn.backward.circle.fill' => ['edit-undo'],
        'arrow.down.to.line' => ['go-bottom'], 'arrow.up.to.line' => ['go-top'], 'arrow.left.to.line' => ['go-first'], 'arrow.right' => ['go-next'],
        'arrow.up.circle' => ['go-up'], 'arrowshape.up.circle' => ['go-up'], 'arrow.turn.down.right' => ['go-jump'], 'arrow.up.arrow.down' => ['view-sort-ascending'],
        'chevron.down' => ['pan-down', 'go-down'], 'chevron.down.circle' => ['pan-down', 'go-down'], 'chevron.down.circle.fill' => ['pan-down', 'go-down'],
        'chevron.up' => ['pan-up', 'go-up'], 'chevron.up.circle' => ['pan-up', 'go-up'],
        'chevron.left' => ['pan-start', 'go-previous'], 'chevron.right' => ['pan-end', 'go-next'], 'chevron.forward.circle.fill' => ['pan-end', 'go-next'],
        // No icon of the spec means these
        'chevron.forward.2' => null, 'chevron.up.chevron.down' => null, 'chevron.left.forwardslash.chevron.right' => null,
        'pip.enter' => null, 'pip.exit' => null, 'pip.swap' => null,
        'doc.viewfinder' => null, 'doc.text.viewfinder' => null, 'document.viewfinder' => null, 'text.viewfinder' => null, 'viewfinder.circle.fill' => null,
        'cpu' => null, 'crop' => null, 'aspectratio' => null, 'slider.vertical.3' => null, 'slider.horizontal.below.square.and.square.filled' => null,
        'sun.lefthalf.filled' => null, 'moonphase.first.quarter' => null, 'pencil.slash' => null, 'paintbrush.pointed.fill' => null, 'shadow' => null,
        'textformat.characters.arrow.left.and.right' => null, 'character.textbox.badge.sparkles' => null, 'sparkles' => null, 'book.and.wrench' => null,
        'macwindow' => null, 'macwindow.on.rectangle' => null, 'macwindow.and.cursorarrow' => null, 'macwindow.and.pointer.arrow' => null,
        'menubar.rectangle' => null, 'menubar.dock.rectangle' => null, 'filemenu.and.selection' => null, 'cursorarrow.click' => null, 'pointer.arrow.click' => null,
        'app.badge' => null, 'app.badge.checkmark.fill' => null, 'bolt.badge.a' => null, 'bolt.badge.automatic' => null,
        'circle.fill' => null, 'rectangle.fill' => null, 'square.stack.3d.up' => null, 'square.2.layers.3d.top.filled' => null, 'square.dashed' => null,
        'square.dashed.inset.filled' => null, 'square.dotted' => null, 'circle.dashed.inset.fill' => null, 'circle.dashed.inset.filled' => null, 'inset.filled.circle.dashed' => null,
        'inset.filled.bottomthird.rectangle' => null, 'inset.filled.topleft.rectangle' => null, 'inset.filled.topthird.middlethird.bottomthird.rectangle' => null,
        'rectangle.bottomthird.inset.fill' => null, 'rectangle.bottomthird.inset.filled' => null, 'rectangle.connected.to.line.below' => null,
        'capsule.on.rectangle' => null, 'capsule.on.rectangle.liquid.glass' => null, 'liquid.glass' => null, 'geometry.size' => null, 'text.below.photo' => null,
        'list.and.film' => null, 'list.clipboard' => null, 'point.3.filled.connected.trianglepath.dotted' => null,
        'arrow.up.left.circle' => null, 'arrow.up.left.circle.fill' => null, 'arrow.up.and.down' => null, 'arrow.up.and.down.and.arrow.left.and.right' => null,
        'arrow.right.and.line.vertical.and.arrow.left' => null, 'arrow.up.left.bottomright.rectangle' => null, 'arrow.up.left.and.down.right.and.arrow.up.right.and.down.left' => null,
    ];

    private static ?FFI $g = null;
    /** @var array<string, ?string> the theme's icon of each name asked, null for none */
    private array $found = [];
    /** @var array<string, true> the names told in the log as drawn empty */
    private array $told = [];

    public function exists(string $name): bool
    {
        return $this->icon($name) !== null;
    }

    public function draw(string $name, float $size, float $weight, int $rgb, float $scale): array
    {
        $px = max(1, (int)round($size * $scale));
        $icon = $this->icon($name);
        if ($icon === null) {
            $why = array_key_exists($name, self::NAMES) && self::NAMES[$name] !== null ? 'the icon theme has none of ' . implode(', ', self::NAMES[$name]) : 'no freedesktop icon is mapped to it';
            return $this->empty($name, $px, $why);
        }
        $g = self::g();
        $info = $g->gtk_icon_theme_lookup_icon($this->theme(), "$icon-symbolic", $px, self::LOOKUP);
        if ($info === null) {
            return $this->empty($name, $px, "the icon theme has no $icon-symbolic at $px pixels");
        }
        $fg = $g->new('GdkRGBA');
        [$fg->red, $fg->green, $fg->blue, $fg->alpha] = [(($rgb >> 16) & 255) / 255, (($rgb >> 8) & 255) / 255, ($rgb & 255) / 255, 1.0];
        $pb = $g->gtk_icon_info_load_symbolic($info, FFI::addr($fg), null, null, null, null, null);
        $g->g_object_unref($info);
        if ($pb === null) { // its SVG unread: GdkPixbuf has no SVG loader (librsvg2-common)
            return $this->empty($name, $px, "GTK could not load $icon-symbolic (is GdkPixbuf's SVG loader, librsvg2-common, installed?)");
        }
        try {
            [$w, $h, $stride] = [$g->gdk_pixbuf_get_width($pb), $g->gdk_pixbuf_get_height($pb), $g->gdk_pixbuf_get_rowstride($pb)];
            if ($g->gdk_pixbuf_get_n_channels($pb) !== 4) {
                throw new RuntimeException("myphp: the icon $icon has no alpha");
            }
            $all = FFI::string($g->gdk_pixbuf_get_pixels($pb), $stride * ($h - 1) + $w * 4);
        } finally {
            $g->g_object_unref($pb);
        }
        $out = ''; // straight alpha, as GdkPixbuf has it, premultiplied
        for ($y = 0; $y < $h; $y++) {
            $row = substr($all, $y * $stride, $w * 4);
            for ($i = 0; $i < $w * 4; $i += 4) {
                $a = ord($row[$i + 3]);
                $out .= chr(intdiv(ord($row[$i]) * $a + 127, 255)) . chr(intdiv(ord($row[$i + 1]) * $a + 127, 255)) . chr(intdiv(ord($row[$i + 2]) * $a + 127, 255)) . chr($a);
            }
        }
        return [$w, $h, $out];
    }

    /** A transparent square of $px for $name, which the log tells once, $why. @return array{0: int, 1: int, 2: string} */
    private function empty(string $name, int $px, string $why): array
    {
        if (!isset($this->told[$name])) {
            $this->told[$name] = true;
            Logger::warn("the system symbol \"$name\" draws empty on Linux: $why", 'linux');
        }
        return [$px, $px, str_repeat("\0", $px * $px * 4)];
    }

    /** The theme's icon of SF name $name: its first name whose symbolic icon the theme has, null for none. */
    private function icon(string $name): ?string
    {
        if (array_key_exists($name, $this->found)) {
            return $this->found[$name];
        }
        foreach (self::NAMES[$name] ?? [] as $icon) {
            if (self::g()->gtk_icon_theme_has_icon($this->theme(), "$icon-symbolic")) { // the name the lookup takes, the forced symbolic one: by the plain name GTK finds its own colored PNGs first
                return $this->found[$name] = $icon;
            }
        }
        return $this->found[$name] = null;
    }

    private function theme(): FFI\CData
    {
        return self::g()->gtk_icon_theme_get_default() ?? throw new RuntimeException('myphp: GTK has no icon theme before it opens the display (App::run)');
    }

    /** GTK's icon theme and GdkPixbuf, an FFI of its own. */
    private static function g(): FFI
    {
        return self::$g ??= FFI::cdef('
            typedef struct { double red, green, blue, alpha; } GdkRGBA;
            void* gtk_icon_theme_get_default(void);
            int gtk_icon_theme_has_icon(void*, const char*);
            void* gtk_icon_theme_lookup_icon(void*, const char*, int, int);
            void* gtk_icon_info_load_symbolic(void*, const GdkRGBA*, const GdkRGBA*, const GdkRGBA*, const GdkRGBA*, int*, void**);
            int gdk_pixbuf_get_width(void*);
            int gdk_pixbuf_get_height(void*);
            int gdk_pixbuf_get_rowstride(void*);
            int gdk_pixbuf_get_n_channels(void*);
            unsigned char* gdk_pixbuf_get_pixels(void*);
            void g_object_unref(void*);
        ', 'libgtk-3.so.0');
    }
}
