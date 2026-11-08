<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;
use MyPHP\App;
use MyPHP\Dialog;
use MyPHP\L10n;
use MyPHP\Media\Track;
use MyPHP\MenuItem;
use MyPHP\OpenDialogOptions;
use MyPHP\PlayerPlugins\Instance;
use MyPHP\PlayerPlugins\PluginMenuItem;
use MyPHP\PlayerPlugins\Plugins;
use MyPHP\Window;

/**
 * IINA's menus of the player (MenuController): Playback, Video, Audio and
 * Subtitles, File's items of the film and Window's Inspector and Log Viewer, of the
 * player $s, which the app puts in its menu bar, built again for another
 * player or other key bindings (key()). Their titles, checks and lists
 * follow the player as they open (IINA's menuWillOpen); the items IINA
 * binds to keys take the keys of the player's bindings that do what they
 * do, a step's size and its title the binding's (updateKeyEquivalentsFrom).
 * Of no player (null) they are disabled.
 *
 * Not here yet: Video's Lock Window Aspect Ratio.
 */
final class MediaPlayerMenus
{
    /** IINA's ratios of Aspect Ratio and Crop (AppData.aspects). */
    public const ASPECTS = ['4:3', '5:4', '16:9', '16:10', '1:1', '3:2', '2.21:1', '2.35:1', '2.39:1'];

    /**
     * The items IINA binds to keys and the bindings that do what they do
     * (updateKeyEquivalentsFrom's table): name => [IINA's command, the
     * action, whether its last word is a number of a range, the range,
     * whether the number titles the item].
     */
    private const BOUND = [
        'showCurrentFileInFinder' => [true, ['show-current-file-in-finder'], false, null],
        'deleteCurrentFile' => [true, ['delete-current-file'], false, null],
        'savePlaylist' => [true, ['save-playlist'], false, null],
        'videoPanel' => [true, ['video-panel'], false, null],
        'audioPanel' => [true, ['audio-panel'], false, null],
        'subPanel' => [true, ['sub-panel'], false, null],
        'playlistPanel' => [true, ['playlist-panel'], false, null],
        'chapterPanel' => [true, ['chapter-panel'], false, null],
        'findOnlineSub' => [true, ['find-online-subs'], false, null],
        'saveDownloadedSub' => [true, ['save-downloaded-sub'], false, null],
        'biggerSize' => [true, ['bigger-window'], false, null],
        'smallerSize' => [true, ['smaller-window'], false, null],
        'fitToScreen' => [true, ['fit-to-screen'], false, null],
        'miniPlayer' => [true, ['toggle-music-mode'], false, null],
        'liveText' => [true, ['live-text'], false, null],
        'pictureInPicture' => [true, ['toggle-pip'], false, null],
        'flip' => [true, ['flip'], false, null],
        'mirror' => [true, ['mirror'], false, null],
        'cycleVideoTracks' => [false, ['cycle', 'video'], false, null],
        'cycleAudioTracks' => [false, ['cycle', 'audio'], false, null],
        'cycleSubtitles' => [false, ['cycle', 'sub'], false, null],
        'nextChapter' => [false, ['add', 'chapter', '1'], false, null],
        'previousChapter' => [false, ['add', 'chapter', '-1'], false, null],
        'pause' => [false, ['cycle', 'pause'], false, null],
        'stop' => [false, ['stop'], false, null],
        'forward' => [false, ['seek', '5'], true, [5.0, 60.0]],
        'backward' => [false, ['seek', '-5'], true, [-60.0, -5.0]],
        'nextFrame' => [false, ['frame-step'], false, null],
        'previousFrame' => [false, ['frame-back-step'], false, null],
        'nextMedia' => [false, ['playlist-next'], false, null],
        'previousMedia' => [false, ['playlist-prev'], false, null],
        'speedUp' => [false, ['multiply', 'speed', '2.0'], true, [1.5, 3.0]],
        'speedUpSlightly' => [false, ['multiply', 'speed', '1.1'], true, [1.01, 1.49]],
        'speedDown' => [false, ['multiply', 'speed', '0.5'], true, [0.0, 0.7]],
        'speedDownSlightly' => [false, ['multiply', 'speed', '0.9'], true, [0.71, 0.99]],
        'speedReset' => [false, ['set', 'speed', '1.0'], true, null],
        'abLoop' => [false, ['ab-loop'], false, null],
        'fileLoop' => [false, ['cycle-values', 'loop', '"inf"', '"no"'], false, null],
        'screenshot' => [false, ['screenshot'], false, null],
        'halfSize' => [false, ['set', 'window-scale', '0.5'], true, null],
        'normalSize' => [false, ['set', 'window-scale', '1'], true, null],
        'doubleSize' => [false, ['set', 'window-scale', '2'], true, null],
        'fullScreen' => [false, ['cycle', 'fullscreen'], false, null],
        'alwaysOnTop' => [false, ['cycle', 'ontop'], false, null],
        'mute' => [false, ['cycle', 'mute'], false, null],
        'increaseVolume' => [false, ['add', 'volume', '5'], true, [5.0, 10.0]],
        'decreaseVolume' => [false, ['add', 'volume', '-5'], true, [-10.0, -5.0]],
        'increaseVolumeSlightly' => [false, ['add', 'volume', '1'], true, [1.0, 2.0]],
        'decreaseVolumeSlightly' => [false, ['add', 'volume', '-1'], true, [-2.0, -1.0]],
        'decreaseAudioDelay' => [false, ['add', 'audio-delay', '-0.5'], true, null],
        'decreaseAudioDelaySlightly' => [false, ['add', 'audio-delay', '-0.1'], true, null],
        'increaseAudioDelay' => [false, ['add', 'audio-delay', '0.5'], true, null],
        'increaseAudioDelaySlightly' => [false, ['add', 'audio-delay', '0.1'], true, null],
        'resetAudioDelay' => [false, ['set', 'audio-delay', '0'], true, null],
        'hideSubtitles' => [false, ['cycle', 'sub-visibility'], false, null],
        'hideSecondSubtitles' => [false, ['cycle', 'secondary-sub-visibility'], false, null],
        'decreaseSubDelay' => [false, ['add', 'sub-delay', '-0.5'], true, null],
        'decreaseSubDelaySlightly' => [false, ['add', 'sub-delay', '-0.1'], true, null],
        'increaseSubDelay' => [false, ['add', 'sub-delay', '0.5'], true, null],
        'increaseSubDelaySlightly' => [false, ['add', 'sub-delay', '0.1'], true, null],
        'resetSubDelay' => [false, ['set', 'sub-delay', '0'], true, null],
        'increaseTextSize' => [false, ['multiply', 'sub-scale', '1.1'], true, [1.01, 1.49]],
        'decreaseTextSize' => [false, ['multiply', 'sub-scale', '0.9'], true, [0.71, 0.99]],
        'resetTextSize' => [false, ['set', 'sub-scale', '1'], true, null],
    ];

    /** mpv's names of keys (KeyCodeHelper.mpvSymbolToKeyChar) in accelerators' (Menu::accelerator). */
    private const KEY_NAMES = [
        'LEFT' => 'left', 'RIGHT' => 'right', 'UP' => 'up', 'DOWN' => 'down', 'BS' => 'backspace', 'DEL' => 'delete', 'KP_DEL' => 'delete', 'TAB' => 'tab',
        'INS' => 'insert', 'KP_INS' => 'insert', 'HOME' => 'home', 'END' => 'end', 'PGUP' => 'pageup', 'PGDWN' => 'pagedown', 'PRINT' => 'printscreen',
        'SPACE' => 'space', 'SHARP' => '#', 'PLUS' => 'plus', 'ENTER' => 'enter', 'KP_ENTER' => 'enter', 'ESC' => 'escape', 'KP_DEC' => '.',
        'KP0' => '0', 'KP1' => '1', 'KP2' => '2', 'KP3' => '3', 'KP4' => '4', 'KP5' => '5', 'KP6' => '6', 'KP7' => '7', 'KP8' => '8', 'KP9' => '9',
    ];

    /**
     * What the menus are built of: the player and its key bindings; the
     * app builds them again (App::setMenu) when it changes, the player in
     * front another or the bindings changed.
     */
    public static function key(?MediaPlayerState $s): string
    {
        $bindings = $s === null ? [] : array_map(fn(array $m) => [$m['normal'], $m['action'], $m['iina']], MediaPlayerKeys::current($s->settings()));
        $saved = $s === null ? [] : [$s->savedFilters(true), $s->savedFilters(false)]; // their keys the menus' too
        return ($s === null ? '' : spl_object_id($s)) . ':' . md5(serialize([$bindings, $saved])) . ':' . Plugins::$revision; // the plugins' items' keys too
    }

    /** File's items of the film: Show Current File in Finder, Delete Current File, Save Current Playlist… @return list<MenuItem> */
    public static function fileItems(?MediaPlayerState $s): array
    {
        $b = self::bindings($s);
        $local = fn(): bool => $s?->player !== null && !$s->player->remote(); // IINA's: of a file, not the network's
        $it = self::itemer($s, $b);
        return [
            $it('showCurrentFileInFinder', self::t('iinaMenuShowCurrentFileInFinder'), fn() => $s->perform(['show-current-file-in-finder'], true), $local),
            $it('deleteCurrentFile', self::t('iinaMenuDeleteCurrentFile'), fn() => $s->deleteCurrentFile(false), $local),
            $it('savePlaylist', self::t('iinaMenuSaveCurrentPlaylist'), fn() => $s->savePlaylist(), fn(): bool => $s?->playlist !== null),
        ];
    }

    /** Window's Log Viewer (Cmd+L). */
    public static function logViewer(): MenuItem
    {
        return new MenuItem(self::t('iinaMenuLogViewer'), fn() => LogViewer::open(), 'Cmd+L', id: 'iina.logViewer');
    }

    /** IINA's Playback, Video, Audio and Subtitles menus of player $s. @return list<MenuItem> */
    public static function menus(?MediaPlayerState $s, ?Closure $lastActive = null): array
    {
        $out = [];
        self::$lastActive = $lastActive ?? fn(): ?MediaPlayerState => $s;
        foreach (['iinaMenuPlayback' => 'playback', 'iinaMenuVideo' => 'video', 'iinaMenuAudio' => 'audio', 'iinaMenuSubtitles' => 'subtitles'] as $label => $menu) {
            $build = fn(): array => self::$menu($s, self::bindings($s));
            $out[] = new MenuItem(self::t($label), submenu: $build(), id: "iina.$menu",
                onOpen: function (MenuItem $m) use ($build): void { self::update($m->submenu, $build()); });
        }
        if (Plugins::active()) { // IINA's Plugin menu, built anew as it opens: the plugins' items change as they will
            $out[] = new MenuItem(self::t('iinaMenuPlugin'), submenu: self::plugin($s), id: 'iina.plugin', onOpen: function (MenuItem $m) use ($s): void { $s?->emitForPlugins('iina.menu-update'); $m->setSubmenu(self::plugin($s)); }); // iina.menu-update told first, IINA's
        }
        return $out;
    }

    /** Opens the settings window at page $page ('plugins'), the app's (Manage Plugins…). @var ?Closure(string): void */
    public static ?Closure $showSettings = null;
    /** Plays $paths in a new player window, the app's (the playlist's Play in New Window, IINA's newPlayerCore.openURLs); none: the item is not there. @var ?Closure(list<string>): void */
    public static ?Closure $playInNewWindow = null;

    /**
     * IINA's Plugin menu (MenuController.updatePluginMenu) of player $s:
     * Manage Plugins…, Show or Hide Plugins Panel; each plugin's items (its global instance's, then
     * the player's) under its name, the first five, the rest under More…,
     * a key taken by a key binding of the player's left off and the plugins
     * so keyed listed under Conflicting key shortcuts… at the top; Developer
     * Tool (each instance's, MediaPlayerDevTool), Reload All Plugins.
     * @return list<MenuItem>
     */
    public static function plugin(?MediaPlayerState $s): array
    {
        $taken = [];
        foreach ($s === null ? [] : MediaPlayerKeys::current($s->settings()) as $m) { $taken[$m['normal']] = true; }
        $conflicts = [];
        $out = [
            new MenuItem(self::t('iinaMenuManagePlugins'), fn() => (self::$showSettings)?->__invoke('plugins'), id: 'iina.managePlugins'),
            new MenuItem(self::t($s?->sidebar === 'plugins' ? 'iinaMenuHidePluginsPanel' : 'iinaMenuShowPluginsPanel'), fn() => $s?->showPluginsSidebar($s->sidebar === 'plugins' ? null : $s->pluginTab ?? ''), disabled: $s === null, id: 'iina.pluginsPanel'),
            MenuItem::separator(),
        ];
        $first = true;
        $devTool = [];
        foreach ($s?->plugins() ?? [] as $instance) {
            foreach (array_filter([$instance, $instance->plugin->globalInstance]) as $i) { // its developer tool, and its global instance's (IINA's)
                $devTool[] = new MenuItem(MediaPlayerDevTool::title($i), fn() => MediaPlayerDevTool::show($i, MediaPlayerDevTool::title($i)));
            }
            $items = [...($instance->plugin->globalInstance?->menuItems ?? []), ...$instance->menuItems];
            if ($items === []) { continue; }
            if (!$first) { $out[] = MenuItem::separator(); }
            $first = false;
            $out[] = MenuItem::header($instance->plugin->name);
            $more = [];
            foreach ($items as $n => $item) {
                $made = self::pluginItem($item, $instance, $taken, $conflicts);
                if ($n < 5) { $out[] = $made; } else { $more[] = $made; }
            }
            if ($more !== []) {
                $instance->log('Please avoid adding too much first-level menu items. IINA will only display the first 5 of them.', \MyPHP\Logger::WARNING);
                $out[] = new MenuItem(self::t('iinaMenuMorePlugin'), submenu: $more);
            }
        }
        if ($conflicts !== []) {
            array_unshift($out, new MenuItem(self::t('iinaMenuConflictingShortcuts'), submenu: array_map(fn(array $c) => new MenuItem("{$c[0]}: {$c[1]}", disabled: true), $conflicts)));
        }
        $out[] = MenuItem::separator();
        $out[] = new MenuItem(self::t('iinaMenuDeveloperTool'), submenu: $devTool, id: 'iina.developerTool');
        $out[] = new MenuItem(self::t('iinaMenuReloadPlugins'), fn() => Plugins::reloadAll(), id: 'iina.reloadPlugins');
        return $out;
    }

    /** Plugin $instance's menu item $item as the menu's (IINA's add(menuItemDef:)): a key a binding has listed in $conflicts. @param array<string, true> $taken @param list<array{0: string, 1: string}> $conflicts */
    private static function pluginItem(PluginMenuItem $item, Instance $instance, array $taken, array &$conflicts): MenuItem
    {
        if ($item->isSeparator) { return MenuItem::separator(); }
        $accel = '';
        if ($item->keyBinding !== null) {
            $normal = MediaPlayerKeys::normalize($item->keyBinding);
            if (isset($taken[$normal])) { $conflicts[] = [$instance->plugin->name, $item->keyBinding]; }
            else { $accel = self::accelerator($normal) ?? ''; }
        }
        $sub = array_map(fn(PluginMenuItem $i) => self::pluginItem($i, $instance, $taken, $conflicts), $item->items);
        return new MenuItem($item->title, $item->action === null ? null : fn() => $item->callAction(), $accel, type: $sub !== [] ? '' : MenuItem::CHECKBOX,
            disabled: !$item->enabled, checked: $item->selected, submenu: $sub);
    }

    /**
     * The state of $fresh's items, the same menu built again, in $items:
     * their titles, checks, whether enabled, keys; the lists of the
     * submenus that open of their own (tracks, playlist) left to their onOpen.
     * @param list<MenuItem> $items
     * @param list<MenuItem> $fresh
     */
    private static function update(array $items, array $fresh): void
    {
        foreach (array_values($items) as $i => $it) {
            $f = $fresh[$i] ?? null;
            if ($f === null || $f->id !== $it->id) { continue; }
            if ($f->label !== $it->label) { $it->setLabel($f->label); }
            if ($f->checked !== $it->checked) { $it->setChecked($f->checked); }
            if ($f->disabled !== $it->disabled) { $it->setEnabled(!$f->disabled); }
            if ($f->accelerator !== $it->accelerator) { $it->setAccelerator($f->accelerator); }
            if ($it->onOpen === null && $it->submenu !== []) { self::update($it->submenu, $f->submenu); }
        }
    }

    /**
     * Makes the menus' items of player $s: an item named $name (BOUND) runs
     * $click with the step its binding gives ($value, a seek's $exact) and
     * takes its key, enabled while $s plays and $enabled says.
     * @param array<string, array{0: string, 1: ?float, 2: bool}> $b
     * @return Closure(string, string, Closure, ?Closure=, ?bool=, bool=, string=): MenuItem
     */
    private static function itemer(?MediaPlayerState $s, array $b): Closure
    {
        return function (string $name, string $label, Closure $click, ?Closure $enabled = null, ?bool $checked = null, bool $alternate = false, string $accel = '') use ($s, $b): MenuItem {
            [$key, $value, $exact] = $b[$name] ?? ['', self::defaultValue($name), false];
            $on = $s?->player !== null && ($enabled === null || $enabled());
            return new MenuItem($label, function () use ($s, $click, $value, $exact): void {
                if ($s?->player !== null) { $click($value, $exact); }
            }, $key !== '' ? $key : $accel, type: $checked === null ? '' : MenuItem::CHECKBOX, disabled: !$on, checked: $checked ?? false, id: "iina.$name", alternate: $alternate);
        };
    }

    /** @param array<string, array{0: string, 1: ?float, 2: bool}> $b @return list<MenuItem> */
    private static function playback(?MediaPlayerState $s, array $b): array
    {
        $it = self::itemer($s, $b);
        $p = $s?->player;
        $num = fn(string $name): float => $b[$name][1] ?? self::defaultValue($name);
        $panel = $s !== null && $s->sidebar === 'playlist';
        $sep = fn() => MenuItem::separator();
        return [
            $it('pause', self::t($p !== null && !$p->playing() ? 'iinaMenuResume' : 'iinaMenuPause'), fn() => $s->togglePause()),
            $it('stop', self::t('iinaMenuStopAndClearPlaylists'), fn() => $s->stop()),
            $sep(),
            $it('forward', self::t('iinaMenuSeekForward', ['seconds' => MediaPlayerState::number(abs($num('forward')))]), fn(float $v, bool $exact) => $s->seekBy($v, $exact)),
            $it('nextFrame', self::t('iinaMenuNextFrame'), fn() => $s->frameStep(), alternate: true),
            $it('backward', self::t('iinaMenuSeekBackward', ['seconds' => MediaPlayerState::number(abs($num('backward')))]), fn(float $v, bool $exact) => $s->seekBy($v, $exact)),
            $it('previousFrame', self::t('iinaMenuPreviousFrame'), fn() => $s->frameStep(true), alternate: true),
            $it('jumpToBegin', self::t('iinaMenuJumpToBeginning'), fn() => $s->seekBy(-$s->player->time(), true)),
            $it('jumpTo', self::t('iinaMenuJumpTo'), fn() => $s->jumpTo(), accel: 'Cmd+J'),
            $sep(),
            new MenuItem(self::t('iinaMenuSpeed', ['speed' => MediaPlayerState::number($p?->rate() ?? 1.0)]), disabled: true, id: 'iina.speedIndicator'),
            $it('speedUp', self::t('iinaMenuSpeedUpBy', ['speed' => MediaPlayerState::number($num('speedUp'))]), fn(float $v) => $s->setSpeed($s->player->rate() * $v)),
            $it('speedUpSlightly', self::t('iinaMenuSpeedUpBy', ['speed' => MediaPlayerState::number($num('speedUpSlightly'))]), fn(float $v) => $s->setSpeed($s->player->rate() * $v), alternate: true),
            $it('speedDown', self::t('iinaMenuSpeedDownBy', ['speed' => MediaPlayerState::number($num('speedDown'))]), fn(float $v) => $s->setSpeed($s->player->rate() * $v)),
            $it('speedDownSlightly', self::t('iinaMenuSpeedDownBy', ['speed' => MediaPlayerState::number($num('speedDownSlightly'))]), fn(float $v) => $s->setSpeed($s->player->rate() * $v), alternate: true),
            $it('speedReset', self::t('iinaMenuResetSpeed'), fn() => $s->setSpeed(1.0)),
            $sep(),
            $it('screenshot', self::t('iinaMenuTakeScreenshot'), fn() => $s->screenshot()),
            new MenuItem(self::t('iinaMenuGoToScreenshotFolder'), fn() => $s?->openScreenshotFolder(), disabled: $s === null, id: 'iina.gotoScreenshotFolder'), // the app's, of any player
            $sep(),
            $it('abLoop', self::t('iinaMenuAbLoop'), fn() => $s->abLoop(), checked: $s !== null && $s->loopA !== null),
            $it('fileLoop', self::t('iinaMenuFileLoop'), fn() => $s->setLooping($s->loop === 'file' ? 'off' : 'file'), checked: $s?->loop === 'file'),
            $sep(),
            $it('playlistPanel', self::t($panel && $s->playlistTab === 'playlist' ? 'iinaMenuHidePlaylist' : 'iinaMenuShowPlaylist'), fn() => $s->perform(['playlist-panel'], true)),
            $it('playlistLoop', self::t('iinaMenuPlaylistLoop'), fn() => $s->setLooping($s->loop === 'playlist' ? 'off' : 'playlist'), checked: $s?->loop === 'playlist'),
            self::list($s, 'playlist', self::t('iinaMenuPlaylist'), fn() => self::playlist($s)),
            $sep(),
            $it('nextMedia', self::t('iinaMenuNextMedia'), fn() => $s->next()),
            $it('previousMedia', self::t('iinaMenuPreviousMedia'), fn() => $s->previous()),
            $sep(),
            $it('chapterPanel', self::t($panel && $s->playlistTab === 'chapters' ? 'iinaMenuHideChapters' : 'iinaMenuShowChapters'), fn() => $s->perform(['chapter-panel'], true)),
            self::list($s, 'chapters', self::t('iinaMenuChapters'), fn() => self::chapters($s)),
            $sep(),
            $it('nextChapter', self::t('iinaMenuNextChapter'), fn() => $s->perform(['add', 'chapter', '1'])),
            $it('previousChapter', self::t('iinaMenuPreviousChapter'), fn() => $s->perform(['add', 'chapter', '-1'])),
        ];
    }

    /** @param array<string, array{0: string, 1: ?float, 2: bool}> $b @return list<MenuItem> */
    private static function video(?MediaPlayerState $s, array $b): array
    {
        $it = self::itemer($s, $b);
        $p = $s?->player;
        $sep = fn() => MenuItem::separator();
        $sized = fn(): bool => $p?->videoTrack() !== null && !$s->musicMode;
        return [
            $it('videoPanel', self::t($s?->sidebar === 'video' ? 'iinaMenuHideVideo' : 'iinaMenuShowVideo'), fn() => $s->perform(['video-panel'], true)),
            $sep(),
            $it('cycleVideoTracks', self::t('iinaMenuCycleVideoTracks'), fn() => null, fn(): bool => false), // the film's one picture, shown always (docs/upstream-deviations.md)
            self::list($s, 'videoTrack', self::t('iinaMenuVideoTrack'), fn() => self::tracks($s, Track::VIDEO)),
            $sep(),
            $it('halfSize', self::t('iinaMenuHalfSize'), fn() => $s->windowScale(0.5), $sized),
            $it('normalSize', self::t('iinaMenuNormalSize'), fn() => $s->windowScale(1.0), $sized),
            $it('doubleSize', self::t('iinaMenuDoubleSize'), fn() => $s->windowScale(2.0), $sized),
            $it('fitToScreen', self::t('iinaMenuFitToScreen'), fn() => $s->windowScale(-1.0), $sized),
            $sep(),
            $it('biggerSize', self::t('iinaMenuBiggerSize'), fn() => $s->perform(['bigger-window'], true), $sized),
            $it('smallerSize', self::t('iinaMenuSmallerSize'), fn() => $s->perform(['smaller-window'], true), $sized),
            $sep(),
            $it('pictureInPicture', self::t($s?->pictureInPicture ? 'iinaMenuExitPip' : 'iinaMenuPip'), fn() => $s->togglePictureInPicture(), fn(): bool => $s->pictureInPicture || $s->pictureInPicturePossible()),
            $it('fullScreen', self::t($s?->fullScreen() ? 'iinaMenuExitFullscreen' : 'iinaMenuFullscreen'), fn() => $s->perform(['cycle', 'fullscreen'])),
            $it('alwaysOnTop', self::t('iinaMenuFloatOnTop'), fn() => $s->perform(['cycle', 'ontop']), checked: $s?->onTop ?? false),
            $it('liveText', self::t('iinaMenuLiveText'), fn() => $s->perform(['live-text'], true), checked: (bool)($s?->settings()->get('enableLiveText') ?? false)),
            $sep(),
            $it('miniPlayer', self::t($s?->musicMode ? 'iinaMenuExitMiniPlayer' : 'iinaMenuMiniPlayer'), fn() => $s->toggleMusicMode()),
            $sep(),
            self::list($s, 'aspect', self::t('iinaMenuAspectRatio'), fn() => self::ratios($s, false)),
            self::list($s, 'crop', self::t('iinaMenuCrop'), fn() => self::ratios($s, true)),
            self::list($s, 'rotation', self::t('iinaMenuRotation'), fn() => self::rotations($s)),
            new MenuItem(self::t('iinaMenuFlip'), id: 'iina.flipMenu', submenu: [
                $it('mirror', self::t('iinaMenuMirror'), fn() => $s->setMirror(!$s->mirror), checked: $s?->mirror ?? false),
                $it('flip', self::t('iinaMenuFlipVertical'), fn() => $s->setFlip(!$s->flip), checked: $s?->flip ?? false),
            ]),
            $it('deinterlace', self::t('iinaMenuDeinterlace'), fn() => $s->setDeinterlace(!$s->deinterlace), checked: $s?->deinterlace ?? false),
            $it('delogo', self::t('iinaMenuDelogo'), fn() => $s->toggleDelogo(), checked: $s?->hasLabel(MediaPlayerState::DELOGO) ?? false),
            $sep(),
            ...self::filterItems($s, true),
        ];
    }

    /** @param array<string, array{0: string, 1: ?float, 2: bool}> $b @return list<MenuItem> */
    private static function audio(?MediaPlayerState $s, array $b): array
    {
        $it = self::itemer($s, $b);
        $p = $s?->player;
        $num = fn(string $name): float => $b[$name][1] ?? self::defaultValue($name);
        $sep = fn() => MenuItem::separator();
        $volume = MediaPlayerState::number(round(($s?->volume ?? 1.0) * 100, 2));
        $step = fn(string $name, string $label) => $it($name, self::t($label, ['volume' => MediaPlayerState::number(abs($num($name)))]), fn(float $v) => $s->setVolume($s->volume + $v / 100), alternate: str_ends_with($name, 'Slightly'));
        $delay = fn(string $name, string $label) => $it($name, self::t($label, ['seconds' => MediaPlayerState::number(abs($num($name)))]), fn(float $v) => $s->setAudioDelay($s->player->audioDelay() + $v), alternate: str_ends_with($name, 'Slightly'));
        return [
            $it('audioPanel', self::t($s?->sidebar === 'audio' ? 'iinaMenuHideAudio' : 'iinaMenuShowAudio'), fn() => $s->perform(['audio-panel'], true)),
            $sep(),
            $it('cycleAudioTracks', self::t('iinaMenuCycleAudioTracks'), fn() => $s->perform(['cycle', 'audio'])),
            self::list($s, 'audioTrack', self::t('iinaMenuAudioTrack'), fn() => self::tracks($s, Track::AUDIO)),
            $it('loadExternalAudio', self::t('iinaMenuLoadExternalAudio'), function () use ($s): void {
                $paths = Dialog::open(new OpenDialogOptions(parent: Window::focused(), title: self::t('iinaMenuLoadExternalAudio'), defaultPath: $s->player->remote() ? '' : dirname($s->player->path())));
                if ($paths !== []) { $s->loadAudio($paths[0]); }
            }),
            $sep(),
            new MenuItem(self::t($p?->muted() ? 'iinaMenuVolumeMuted' : 'iinaMenuVolume', ['volume' => $volume]), disabled: true, id: 'iina.volumeIndicator'),
            $step('increaseVolume', 'iinaMenuVolumeUp'),
            $step('increaseVolumeSlightly', 'iinaMenuVolumeUp'),
            $step('decreaseVolume', 'iinaMenuVolumeDown'),
            $step('decreaseVolumeSlightly', 'iinaMenuVolumeDown'),
            $it('mute', self::t('iinaMenuMute'), fn() => $s->toggleMute(), checked: $p?->muted() ?? false),
            $sep(),
            new MenuItem(self::t('iinaMenuAudioDelay', ['seconds' => MediaPlayerState::number($p?->audioDelay() ?? 0.0)]), disabled: true, id: 'iina.audioDelayIndicator'),
            $delay('increaseAudioDelay', 'iinaMenuAudioDelayUp'),
            $delay('increaseAudioDelaySlightly', 'iinaMenuAudioDelayUp'),
            $delay('decreaseAudioDelay', 'iinaMenuAudioDelayDown'),
            $delay('decreaseAudioDelaySlightly', 'iinaMenuAudioDelayDown'),
            $it('resetAudioDelay', self::t('iinaMenuResetAudioDelay'), fn() => $s->setAudioDelay(0.0)),
            $sep(),
            self::list($s, 'audioDevice', self::t('iinaMenuAudioDevice'), fn() => self::audioDevices($s)),
            $sep(),
            ...self::filterItems($s, false),
        ];
    }

    /** @param array<string, array{0: string, 1: ?float, 2: bool}> $b @return list<MenuItem> */
    private static function subtitles(?MediaPlayerState $s, array $b): array
    {
        $it = self::itemer($s, $b);
        $p = $s?->player;
        $num = fn(string $name): float => $b[$name][1] ?? self::defaultValue($name);
        $sep = fn() => MenuItem::separator();
        $delay = fn(string $name, string $label) => $it($name, self::t($label, ['seconds' => MediaPlayerState::number(abs($num($name)))]), fn(float $v) => $s->setSubtitleDelay($s->player->subtitleDelay() + $v), alternate: str_ends_with($name, 'Slightly'));
        $online = fn(?string $provider = null): Closure => fn(): bool => $s->canSearchOnline($provider); // no search going; OpenSubtitles of a key in the options
        $from = [];
        foreach (MediaPlayerState::PROVIDERS as $id => $name) { // IINA's populateMenu: its sources, by name
            $from[] = $it('onlineSub' . ucfirst(ltrim($id, ':')), $name, fn() => $s->searchOnlineSubtitles($id), $online($id));
        }
        $from[] = $sep(); // and after a line, the plugins' ("name — plugin")
        foreach (MediaPlayerState::pluginProviders() as $id => $name) {
            $from[] = new MenuItem($name, fn() => $s?->searchOnlineSubtitles($id), disabled: !($s?->canSearchOnline($id) ?? false), id: "iina.onlineSub:$id");
        }
        return [
            $it('subPanel', self::t($s?->sidebar === 'subtitles' ? 'iinaMenuHideSubtitles' : 'iinaMenuShowSubtitles'), fn() => $s->perform(['sub-panel'], true)),
            $sep(),
            $it('cycleSubtitles', self::t('iinaMenuCycleSubtitles'), fn() => $s->perform(['cycle', 'sub'])),
            self::list($s, 'subTrack', self::t('iinaMenuSubtitle'), fn() => self::tracks($s, Track::SUBTITLE)),
            $it('hideSubtitles', self::t(($s?->subtitlesVisible ?? true) ? 'iinaMenuSubHide' : 'iinaMenuSubShow'), fn() => $s->toggleSubtitles()),
            self::list($s, 'secondSubTrack', self::t('iinaMenuSecondarySubtitle'), fn() => self::tracks($s, Track::SUBTITLE, true)),
            $it('hideSecondSubtitles', self::t(($s?->secondarySubtitlesVisible ?? true) ? 'iinaMenuSubSecondHide' : 'iinaMenuSubSecondShow'), fn() => $s->toggleSecondarySubtitles()),
            $it('loadExternalSub', self::t('iinaMenuLoadExternalSubtitle'), function () use ($s): void {
                $paths = Dialog::open(new OpenDialogOptions(parent: Window::focused(), title: self::t('iinaMenuLoadExternalSubtitle')));
                if ($paths !== []) { $s->loadSubtitles($paths[0]); }
            }),
            $sep(),
            $it('findOnlineSub', self::t('iinaMenuFindOnlineSub', ['provider' => MediaPlayerState::providerName($s?->onlineProvider() ?? ':opensubtitles')]), fn() => $s->searchOnlineSubtitles(), $online()),
            new MenuItem(self::t('iinaMenuFindOnlineSubtitlesFrom'), id: 'iina.onlineSubSource', submenu: $from),
            $it('saveDownloadedSub', self::t('iinaMenuSaveDownloadedSubtitle'), fn() => $s->saveDownloadedSubtitle()),
            $sep(),
            self::list($s, 'encoding', self::t('iinaMenuEncoding'), fn() => self::encodings($s)),
            $sep(),
            $it('increaseTextSize', self::t('iinaMenuScaleUp'), fn(float $v) => $s->setSubScale($s->subScale * $v)),
            $it('decreaseTextSize', self::t('iinaMenuScaleDown'), fn(float $v) => $s->setSubScale($s->subScale * $v)),
            $it('resetTextSize', self::t('iinaMenuResetSubtitleScale'), fn() => $s->setSubScale(1.0)),
            $sep(),
            new MenuItem(self::t('iinaMenuSubDelay', ['seconds' => MediaPlayerState::number($p?->subtitleDelay() ?? 0.0)]), disabled: true, id: 'iina.subDelayIndicator'),
            $delay('increaseSubDelay', 'iinaMenuSubDelayUp'),
            $delay('increaseSubDelaySlightly', 'iinaMenuSubDelayUp'),
            $delay('decreaseSubDelay', 'iinaMenuSubDelayDown'),
            $delay('decreaseSubDelaySlightly', 'iinaMenuSubDelayDown'),
            $it('resetSubDelay', self::t('iinaMenuResetSubtitleDelay'), fn() => $s->setSubtitleDelay(0.0)),
            $sep(),
            $it('subFont', self::t('iinaMenuFont'), fn() => MediaPlayerFontSheet::open($s->settings()->get('subTextFont'), fn(string $f) => $s->setSetting('subTextFont', $f))),
        ];
    }

    /** A submenu named $name of what there is as it opens, $items' (IINA's menus of the delegate). @param Closure(): list<MenuItem> $items */
    private static function list(?MediaPlayerState $s, string $name, string $label, Closure $items): MenuItem
    {
        return new MenuItem($label, disabled: $s?->player === null, id: "iina.$name", onOpen: fn(MenuItem $m) => $m->setSubmenu($s?->player === null ? [] : $items()));
    }

    /** The player the filters' windows show (menus()' $lastActive). @var Closure(): ?MediaPlayerState */
    private static Closure $lastActive;

    /**
     * Video Filters… (Shift+Cmd+F) and Saved Video Filters, or Audio
     * Filters… (Shift+Cmd+G) and Saved Audio Filters ($video): the window
     * (MediaPlayerFilters), and an item of each saved filter, its key the
     * one saved, checked while it is on, turning it on or off (IINA's
     * menuToggleVideoFilterString).
     * @return list<MenuItem>
     */
    private static function filterItems(?MediaPlayerState $s, bool $video): array
    {
        $player = self::$lastActive;
        $saved = [];
        foreach ($s?->savedFilters($video) ?? [] as $i => $f) {
            $saved[] = new MenuItem($f['name'], function () use ($s, $video, $f): void {
                if ($s->player !== null && !$s->toggleFilter($video, $f['filterString'], $f['name'])) {
                    Dialog::message(new \MyPHP\MessageOptions(parent: Window::focused(), type: 'warning', message: self::t('iinaAlertFilterIncorrect')));
                }
            }, MediaPlayerFilters::accelerator($f['shortcutKey'], $f['shortcutKeyModifiers']), type: MenuItem::CHECKBOX,
                checked: $s->filterOn($video, $f['filterString']), disabled: $s->player === null, id: ($video ? 'iina.savedVideoFilter' : 'iina.savedAudioFilter') . $i);
        }
        return [
            new MenuItem(self::t($video ? 'iinaMenuVideoFilters' : 'iinaMenuAudioFilters'), fn() => MediaPlayerFilters::open($video, $player), $video ? 'Cmd+Shift+f' : 'Cmd+Shift+g',
                id: $video ? 'iina.videoFilters' : 'iina.audioFilters'),
            new MenuItem(self::t($video ? 'iinaMenuSavedVideoFilters' : 'iinaMenuSavedAudioFilters'), submenu: $saved, id: $video ? 'iina.savedVideoFilters' : 'iina.savedAudioFilters'),
        ];
    }

    /** Playback > Playlist: the playlist's films, the one playing checked (IINA's updatePlaylist). @return list<MenuItem> */
    private static function playlist(MediaPlayerState $s): array
    {
        $l = $s->playlist;
        if ($l === null) { return []; }
        $items = [];
        foreach ($l->files() as $i => $path) {
            $items[] = new MenuItem($l->title($path), fn() => $s->open($path), type: MenuItem::CHECKBOX, checked: $i === $l->index());
        }
        return $items;
    }

    /** Playback > Chapters: "time – title", the time as long as the last's, the one playing checked (IINA's updateChapterList). @return list<MenuItem> */
    private static function chapters(MediaPlayerState $s): array
    {
        $chapters = $s->player->chapters();
        $longest = $chapters === [] ? '' : MediaPlayerState::clock($chapters[count($chapters) - 1]->start);
        $at = $s->player->chapter();
        $items = [];
        foreach ($chapters as $i => $ch) {
            $time = MediaPlayerState::clock($ch->start);
            $time = preg_replace('/\d/', '0', substr($longest, 0, max(0, strlen($longest) - strlen($time)))) . $time; // IINA's padder: "0:05:00" beside "1:02:03"
            $items[] = new MenuItem("$time – {$ch->title}", fn() => $s->playChapter($i), type: MenuItem::CHECKBOX, checked: $i === $at);
        }
        return $items;
    }

    /**
     * A track menu: <None> and the tracks of $type, the one shown checked
     * (IINA's updateTracks); the secondary subtitle's ($secondary). The
     * picture's one track, none unchosen; a sound of no decoder here
     * disabled.
     * @return list<MenuItem>
     */
    private static function tracks(MediaPlayerState $s, string $type, bool $secondary = false): array
    {
        $p = $s->player;
        [$tracks, $shown] = match ($type) {
            Track::VIDEO => [$p->videoTrack() === null ? [] : [$p->videoTrack()], $p->videoTrack()],
            Track::AUDIO => [$p->audioTracks(), $p->audioTrack()],
            default => [$p->subtitleTracks(), $secondary ? $p->secondarySubtitleTrack() : $p->subtitleTrack()],
        };
        $choose = fn(?Track $t) => match ($type) {
            Track::VIDEO => null,
            Track::AUDIO => $t === null ? null : $p->setAudioTrack($t),
            default => $secondary ? $p->setSecondarySubtitleTrack($t) : $p->setSubtitleTrack($t),
        };
        $fixed = $type !== Track::SUBTITLE; // a film's picture and sound are not left out here
        $items = [new MenuItem(self::t('iinaTrackNone'), fn() => $choose(null), type: MenuItem::CHECKBOX, disabled: $fixed, checked: $shown === null)];
        foreach ($tracks as $t) {
            $items[] = new MenuItem(MediaPlayer::trackName($t, MediaPlayer::trackDetails($t)), fn() => $choose($t), type: MenuItem::CHECKBOX,
                disabled: $type === Track::VIDEO || ($type === Track::AUDIO && !\MyPHP\Media\Player::playable($t)), checked: $t === $shown);
        }
        return $items;
    }

    /**
     * Audio > Audio Device: mpv's "auto" and the system's outputs, as IINA
     * titles them ("[description] name"), the one the player plays on
     * checked (IINA's updateAudioDevice).
     * @return list<MenuItem>
     */
    private static function audioDevices(MediaPlayerState $s): array
    {
        $now = $s->audioDevice ?? $s->settings()->get('audioDevice');
        $devices = ['auto' => 'Autoselect device'];
        foreach (\MyPHP\App::backend()->media()->audioOutputs() as $device => $name) { $devices[$device] = $name; }
        $items = [];
        foreach ($devices as $name => $desc) {
            $items[] = new MenuItem("[$desc] $name", fn() => $s->setAudioDevice($name), type: MenuItem::CHECKBOX, checked: $name === $now);
        }
        return $items;
    }

    /** Window's Inspector (Cmd+I), of the player $player gives (MediaPlayerInspector::open). @param Closure(): ?MediaPlayerState $player */
    public static function inspector(Closure $player): MenuItem
    {
        return new MenuItem(self::t('iinaMenuInspector'), fn() => MediaPlayerInspector::open($player), 'Cmd+I', id: 'iina.inspector');
    }

    /**
     * Video > Aspect Ratio (Default and IINA's ratios) or Crop (None, them,
     * and after a separator Custom…, the interactive mode's crop of all the
     * picture to begin with), the one set checked: none of them for a
     * custom crop, as IINA's.
     * @return list<MenuItem>
     */
    private static function ratios(MediaPlayerState $s, bool $crop): array
    {
        $now = $crop ? $s->player->crop() : $s->player->aspectRatio();
        $custom = $crop && $s->hasLabel(MediaPlayerState::CROP);
        $items = [new MenuItem(self::t($crop ? 'iinaQuicksettingNone' : 'iinaQuicksettingDefault'), fn() => $crop ? $s->setCropRatio(null) : $s->setAspectRatio(null), type: MenuItem::CHECKBOX, checked: $now === null && !$custom)];
        foreach (self::ASPECTS as $ratio) {
            $items[] = new MenuItem($ratio, fn() => $crop ? $s->setCropRatio($ratio) : $s->setAspectRatio($ratio), type: MenuItem::CHECKBOX,
                checked: $now !== null && abs($now - MediaPlayerState::ratio($ratio)) < 1e-3);
        }
        if ($crop) {
            $items[] = MenuItem::separator();
            $items[] = new MenuItem(self::t('iinaMenuCropCustom'), fn() => $s->enterInteractive('crop', true), type: MenuItem::CHECKBOX);
        }
        return $items;
    }

    /** Video > Rotation: 0° to 270°, the one set checked. @return list<MenuItem> */
    private static function rotations(MediaPlayerState $s): array
    {
        return array_map(fn(int $i, int $deg) => new MenuItem("{$deg}°", fn() => $s->setRotation($i), type: MenuItem::CHECKBOX, checked: $s->player->rotation() === $deg),
            array_keys(MediaPlayerState::ROTATIONS), MediaPlayerState::ROTATIONS);
    }

    /** Subtitles > Encoding: Auto detect, then IINA's encodings, the one chosen checked. @return list<MenuItem> */
    private static function encodings(MediaPlayerState $s): array
    {
        $now = $s->subEncoding ?? 'auto';
        $items = [new MenuItem(L10n::in(App::locale())->get('iinaSubencodingAuto'), fn() => $s->setSubEncoding('auto'), type: MenuItem::CHECKBOX, checked: $now === 'auto'), MenuItem::separator()];
        foreach (MediaPlayerSettingsPages::ENCODINGS as [$title, $code]) {
            $items[] = new MenuItem($title, fn() => $s->setSubEncoding($code), type: MenuItem::CHECKBOX, checked: $now === $code);
        }
        return $items;
    }

    /**
     * The key and the step of each item BOUND of the player's bindings: the
     * first binding that does what it does, the number in its action, a
     * seek's "exact" (IINA's updateKeyEquivalentsFrom).
     * @return array<string, array{0: string, 1: ?float, 2: bool}>
     */
    private static function bindings(?MediaPlayerState $s): array
    {
        if ($s === null) { return []; }
        $out = [];
        foreach (MediaPlayerKeys::current($s->settings()) as $m) {
            $accel = self::accelerator($m['normal']);
            if ($accel === null) { continue; }
            foreach (self::BOUND as $name => [$iina, $action, $num, $range]) {
                if (isset($out[$name]) || $m['iina'] !== $iina) { continue; }
                $same = self::sameAction($m['action'], $action, $num, $range);
                if ($same !== null) { $out[$name] = [$accel, $same[0], $same[1]]; }
            }
        }
        return $out;
    }

    /** The step of an item of no binding: its action's number. */
    private static function defaultValue(string $name): ?float
    {
        [, $action, $num] = self::BOUND[$name] ?? [false, [], false];
        return $num ? (float)$action[count($action) - 1] : null;
    }

    /**
     * Whether binding action $lhs does what an item's $rhs does, as IINA's
     * sameKeyAction: a seek's flags "relative" and "exact" aside (the latter
     * kept), its last word a number in $range, or $rhs's, where $num says.
     * @param list<string> $lhs
     * @param list<string> $rhs
     * @param ?array{0: float, 1: float} $range
     * @return ?array{0: ?float, 1: bool} its number and "exact", null if it does not
     */
    public static function sameAction(array $lhs, array $rhs, bool $num, ?array $range): ?array
    {
        $exact = false;
        if (($lhs[0] ?? '') === 'seek' && ($rhs[0] ?? '') === 'seek' && count($lhs) > 2) {
            if (count($lhs) === 4) { // mpv's old form, its flags a third and a fourth word
                $lhs = [$lhs[0], $lhs[1], "{$lhs[2]}+{$lhs[3]}"];
            }
            $flags = array_values(array_diff(explode('+', $lhs[count($lhs) - 1]), ['relative']));
            if (in_array('exact', $flags, true)) {
                $flags = array_values(array_diff($flags, ['exact']));
                $exact = true;
            }
            if ($flags === []) { array_pop($lhs); }
        }
        if ($lhs === [] || count($lhs) !== count($rhs)) { return null; }
        if (!$num) {
            return $lhs === $rhs ? [null, false] : null;
        }
        if (array_slice($lhs, 0, -1) !== array_slice($rhs, 0, -1)) { return null; }
        [$l, $r] = [$lhs[count($lhs) - 1], $rhs[count($rhs) - 1]];
        if (!is_numeric($l) || !is_numeric($r)) { return null; }
        $ok = $range !== null ? (float)$l >= $range[0] && (float)$l <= $range[1] : (float)$l === (float)$r;
        return $ok ? [(float)$l, $exact] : null;
    }

    /**
     * mpv's key $normal (MediaPlayerKeys::normalize) as a menu's accelerator
     * (KeyCodeHelper.macOSKeyEquivalent): Meta as Cmd, a capital letter as
     * Shift and its letter; null for one of no key equivalent (the mouse's,
     * a sequence).
     */
    public static function accelerator(string $normal): ?string
    {
        $parts = explode('+', $normal);
        $key = (string)array_pop($parts);
        $mods = [];
        foreach ($parts as $m) {
            $mods[] = ['Ctrl' => 'Ctrl', 'Alt' => 'Alt', 'Shift' => 'Shift', 'Meta' => 'Cmd'][$m] ?? null;
        }
        if (in_array(null, $mods, true)) { return null; }
        if (isset(self::KEY_NAMES[$key])) {
            $key = self::KEY_NAMES[$key];
        } elseif (preg_match('/^F([1-9]|1[0-2])$/', $key) === 1) {
            $key = strtolower($key);
        } elseif (mb_strlen($key) !== 1) {
            return null;
        } elseif (mb_strtolower($key) !== $key) { // a capital: Shift and its letter
            $key = mb_strtolower($key);
            if (!in_array('Shift', $mods, true)) { $mods[] = 'Shift'; }
        }
        return implode('+', [...$mods, $key]);
    }

    private static function t(string $key, array $args = []): string
    {
        return L10n::in(App::locale())->get($key, $args);
    }
}
