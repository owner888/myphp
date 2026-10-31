<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;
use MyPHP\Dialog;
use MyPHP\Media\PlayerSettings;
use MyPHP\Native\AudioDevices;
use MyPHP\MessageOptions;
use MyPHP\OpenDialogOptions;
use MyPHP\Window;

/**
 * The pages of the player's settings window, as IINA's SettingsPage
 * subclasses build theirs (SettingsPageGeneral and the rest): their
 * sections, lists and rows, each row bound to a setting of
 * Media\PlayerSettings by IINA's name of it, IINA's strings its label,
 * description and options (MoviePlayerSettings::tr). A page is
 * ['id', 'title', 'symbol', 'help', 'subsections', 'sections'], a section
 * ['title', 'lists'], a row MoviePlayerSettings::row's.
 *
 * @internal UI\MoviePlayerSettings
 */
final class MoviePlayerSettingsPages
{
    private readonly PlayerSettings $set;

    public function __construct(private readonly Context $c, private readonly MoviePlayerState $s, private readonly object $v)
    {
        $this->set = $s->settings();
    }

    /** @return array<string, array<string, mixed>> the pages by id, in IINA's order */
    public function all(): array
    {
        $pages = [$this->general(), $this->ui(), $this->video(), $this->audio(), $this->subtitles(), $this->network(), $this->control(), $this->keyBindings(), $this->advanced(), $this->utilities()];
        return array_column($pages, null, 'id');
    }

    private function general(): array
    {
        return $this->page('general', MoviePlayerSettings::iina($this->c, 'preference.general'), 'gear', [
            $this->section('$Behavior', [
                [
                    $this->popup('actionAfterLaunch', 'menubar.rectangle+sparkles', [0, 1, 2]),
                    $this->item('$WhenMediaIsOpened', symbol: 'doc+play.circle.fill', expand: true, sub: [
                        $this->sw('pauseWhenOpen'),
                        $this->sw('fullScreenWhenOpen'),
                    ]),
                    $this->item('pauseresumeWhen', symbol: 'playpause+arrow.clockwise.circle.fill', expand: true, sub: [
                        $this->sw('pauseWhenMinimized'),
                        $this->sw('pauseWhenInactive'),
                        $this->sw('playWhenEnteringFullScreen'),
                        $this->sw('pauseWhenLeavingFullScreen'),
                        $this->sw('pauseWhenGoesToSleep'),
                    ]),
                ],
                [
                    $this->sw('alwaysOpenInNewWindow', 'macwindow.on.rectangle', sub: [
                        $this->sw('groupSimultaneousOpensInPlaylist'),
                        $this->sw('allowDuplicatePlayers'),
                    ]),
                    $this->sw('quitWhenNoOpenedWindow', 'macwindow+xmark.circle.fill'),
                    $this->sw('keepOpenOnFileEnd', 'macwindow+pause.circle.fill'),
                ],
                [
                    $this->sw('useLegacyFullScreen', ['arrow.up.left.and.arrow.down.right.rectangle', 'rectangle.expand.diagonal']),
                    $this->sw('blackOutMonitor', 'lock.display'),
                    $this->sw('preventScreenSaver', 'photo.tv+nosign', sub: [
                        $this->sw('allowScreenSaverForAudio', desc: true),
                    ]),
                ],
                [
                    $this->sw('autoSwitchToMusicMode', 'music.note.list'),
                ],
                [
                    $this->swPopup('$CheckForUpdates', 'checkForUpdates', 'updateCheckInterval', [3600, 86400, 604800, 2629800], 'arrowshape.up.circle', '$CheckForUpdates'),
                ],
            ]),
            $this->section('$History', [[
                $this->sw('resumeLastPosition', 'arrow.clockwise+clock.fill'),
                $this->sw('recordPlaybackHistory', 'play.rectangle+clock.fill'),
                $this->sw('recordRecentFiles', 'filemenu.and.selection+clock.fill', sub: [
                    $this->sw('trackAllFilesInRecentOpenMenu', desc: true),
                ]),
            ]]),
            $this->section('$Playlist', [[
                $this->sw('playlistAutoAdd', 'list.bullet+plus.circle.fill'),
                $this->sw('playlistAutoPlayNext', 'list.bullet+play.circle.fill'),
                $this->sw('playlistShowMetadata', 'music.microphone+person.crop.circle.fill', sub: [
                    $this->sw('playlistShowMetadataInMusicMode'),
                ]),
                $this->swPopup('autoRepeat.label', 'autoRepeat', 'defaultRepeatMode', [0, 1], 'repeat'),
            ]]),
            $this->section('$Screenshots', [[
                $this->sw('screenshotSaveToFile', 'camera.on.rectangle', extra: function (): void {
                    $c = $this->c;
                    $c->text(str_replace(getenv('HOME') ?: "\0", '~', $this->set->get('screenShotFolder')), 13, $c->theme->text)->singleLine()->ellipsis('…')->maxWidth(180);
                    if (MoviePlayerSettings::symbolButton($c, 'folder.fill', MoviePlayerSettings::tr($c, '$Source'))) {
                        $paths = Dialog::open(new OpenDialogOptions(parent: Window::focused(), title: 'Choose a path', directory: true));
                        if ($paths) { $this->s->setSetting('screenShotFolder', $paths[0]); }
                    }
                }),
                $this->popup('screenShotFormat', null, \MyPHP\Media\Screenshot::formats()),
                $this->sw('screenshotCopyToClipboard', 'list.clipboard'),
                $this->sw('screenShotIncludeSubtitle', 'captions.bubble'),
                $this->sw('screenshotShowPreview', 'photo+eye.circle.fill'),
            ]]),
        ]);
    }

    private function ui(): array
    {
        $c = $this->c;
        $set = $this->set;
        return $this->page('ui', MoviePlayerSettings::iina($c, 'preference.ui'), 'macwindow', [
            $this->section('$Appearance', [[
                $this->popup('themeMaterial', 'moonphase.first.quarter', [0, 2, 4]),
                $this->item('sidebar.liquid_glass', symbol: ['capsule.on.rectangle.liquid.glass', 'liquid.glass', 'capsule.on.rectangle'], expand: true, iina: true, sub: [
                    $this->sw('useLiquidGlassOSD', label: '$OnScreenDisplay'),
                    $this->sw('useLiquidGlassOSC', label: '$OnScreenController'),
                    $this->sw('useLiquidGlassSidebar', label: 'sidebar.sidebar', iina: true),
                ]),
                $this->sw('compactUI', 'rectangle.grid.3x2.fill', label: 'sidebar.compact_interface', iina: true),
            ]]),
            $this->section('$Window', [
                [
                    $this->geometry('size', '$InitialWindowSize', ['arrow.up.left.and.down.right.and.arrow.up.right.and.down.left', 'arrow.up.left.and.arrow.down.right']),
                    $this->geometry('position', '$InitialWindowPosition', 'arrow.up.and.down.and.arrow.left.and.right'),
                ],
                [
                    $this->popup('resizeWindowOption', 'arrow.up.left.bottomright.rectangle', [0, 1, 2, 3, 4], detail: function (): void {
                        $c = $this->c;
                        $picked = MoviePlayerSettings::radios($c, [0 => MoviePlayerSettings::tr($c, '$AlwaysWhenPlaying'), 1 => MoviePlayerSettings::tr($c, '$WhenMediaIsOpenedManually'), 2 => MoviePlayerSettings::tr($c, '$DoNotResize')], $this->set->get('resizeWindowTiming'));
                        if ($picked !== null) { $this->s->setSetting('resizeWindowTiming', $picked); }
                    }, terms: [MoviePlayerSettings::tr($c, '$AlwaysWhenPlaying'), MoviePlayerSettings::tr($c, '$WhenMediaIsOpenedManually'), MoviePlayerSettings::tr($c, '$DoNotResize')]),
                ],
                [$this->sw('usePhysicalResolution', 'desktopcomputer')],
                [
                    $this->sw('alwaysFloatOnTop', 'square.2.layers.3d.top.filled'),
                    $this->sw('alwaysShowOnTopIcon', 'pin.square'),
                ],
            ]),
            $this->section('$OnScreenController', [
                [
                    $this->item('$Layout', symbol: 'menubar.dock.rectangle', id: 'oscPosition', terms: $this->optionTerms('oscPosition', [0, 1, 2]), detail: function (): void {
                        $picked = MoviePlayerSettings::oscLayout($this->c, $this->set->get('oscPosition'));
                        if ($picked !== null) { $this->s->setSetting('oscPosition', $picked); }
                    }),
                    $this->item('$Toolbar', symbol: 'ellipsis.rectangle', id: 'controlBarToolbarButtons', detail: function (): void {
                        $buttons = MoviePlayerSettings::toolbar($this->c, $this->v, $this->set->get('controlBarToolbarButtons'));
                        if ($buttons !== null) { $this->s->setSetting('controlBarToolbarButtons', $buttons); }
                    }),
                ],
                [
                    $this->popup('arrowBtnAction', 'forward.fill', [0, 1, 2]),
                    $this->swInput('controlBarAutoHideTimeout.label', 'enableControlBarAutoHide', 'controlBarAutoHideTimeout', 'timer', 0, 1e9, true, '$s'),
                ],
                [
                    $this->sw('controlBarStickToCenter', 'arrow.right.and.line.vertical.and.arrow.left'),
                    $this->sw('showChapterPos', 'ruler'),
                    $this->sw('showRemainingTime', 'slider.horizontal.below.square.and.square.filled', sub: [
                        $this->sw('scaleRemainingTime'),
                    ]),
                    $this->sw('disablePlaySliderScrolling', ['computermouse+slash.circle.fill', 'computermouse']),
                    $this->sw('disableVolumeSliderScrolling'),
                ],
            ]),
            $this->section('$OnScreenDisplay', [
                [
                    $this->sw('enableOSD', ['inset.filled.topleft.rectangle', 'app.badge']),
                    $this->item('$SuppressMessagesFor', expand: true, sub: [
                        $this->sw('disableOSDFileStartMsg'),
                        $this->sw('disableOSDPauseResumeMsgs'),
                        $this->sw('disableOSDSeekMsg'),
                        $this->sw('disableOSDSpeedMsg'),
                    ]),
                ],
                [
                    $this->input('osdAutoHideTimeout', 'timer', 0, 1e9, true, '$s', label: 'controlBarAutoHideTimeout.label'),
                    $this->input('osdTextSize', 'textformat.size', 1, 500, false, '$pt'),
                    $this->sw('displayTimeAndBatteryInFullScreen', 'info.bubble'),
                ],
            ]),
            $this->section('$ThumbnailPreview', [[
                $this->sw('enableThumbnailPreview', ['text.below.photo', 'photo'], sub: [
                    $this->input('maxThumbnailPreviewCacheSize', null, 0, 1e9, false, '$MB'),
                    $this->sw('enableThumbnailForRemoteFiles'),
                    $this->input('thumbnailWidth', null, 1, 2000, false, '$pt', desc: true),
                ]),
            ]]),
            $this->section('$PictureinnPicture', [[
                $this->item('$WhenEnteringPIP', symbol: 'pip.enter', sub: [
                    $this->popup('windowBehaviorWhenPip', null, [0, 1, 2]),
                    $this->sw('pauseWhenPip'),
                ]),
                $this->sw('togglePipByMinimizingWindow', 'pip.swap', sub: [
                    $this->sw('togglePipByMinimizingWindowForVideoOnly'),
                ]),
            ]]),
            $this->section('$Accessibility', [[
                $this->sw('disableAnimations', 'accessibility', desc: true, help: 'https://developer.apple.com/design/human-interface-guidelines/accessibility#Cognitive'),
            ]]),
        ], help: MoviePlayerSettings::tr($c, 'more_ui_settings'));
    }

    /**
     * IINA's initial window size or position ($which), a switch over the
     * fields of mpv's geometry (initialWindowSizePosition): the size its
     * side, its value and its unit, points or % of the screen; the position
     * its offsets from a side, each in points or %; turned off, that part
     * of the geometry goes.
     */
    private function geometry(string $which, string $label, string|array $symbol): array
    {
        $g = self::parseGeometry($this->set->get('initialWindowSizePosition'));
        $on = $which === 'size' ? $g['size'] !== null : $g['x'] !== null;
        $save = function (array $g): void { $this->s->setSetting('initialWindowSizePosition', self::geometryString($g)); };
        return $this->item($label, $symbol, id: "geometry.$which", shows: fn(): bool => $on,
            value: function () use ($label, $on, $g, $which, $save): void {
                if (!MoviePlayerSettings::toggle($this->c, MoviePlayerSettings::tr($this->c, $label), $on)) { return; }
                if ($which === 'size') { $g['size'] = $on ? null : ['w', '1280', false]; }
                else { [$g['x'], $g['y']] = $on ? [null, null] : [['+', '20', false], ['-', '20', false]]; }
                $save($g);
            },
            detail: !$on ? null : function () use ($g, $which, $save): void {
                $c = $this->c;
                $tr = fn(string $k) => MoviePlayerSettings::tr($c, $k);
                $units = [0 => $tr('$point'), 1 => $tr('$ofScreen')];
                $c->column(0, 6)->padding(0, 8, 8, MoviePlayerSettings::INDENT)->fillWidth();
                if ($which === 'size') {
                    [$side, $value, $percent] = $g['size'];
                    $c->row(0, 6)->alignItems(Node::CENTER);
                      $s = MoviePlayerSettings::popUp($c, [0 => $tr('$Width'), 1 => $tr('$Height')], $side === 'w' ? 0 : 1, false, true);
                      $n = MoviePlayerSettings::number($c, $this->v, 'geometry.size', (float)$value, 0, 1e5, 0, false, '', true);
                      $u = MoviePlayerSettings::popUp($c, $units, $percent ? 1 : 0, false, true);
                    $c->end();
                    if ($s !== null || $n !== null || $u !== null) {
                        $g['size'] = [$s === null ? $side : ($s === 0 ? 'w' : 'h'), $n === null ? $value : (string)(int)$n, $u === null ? $percent : $u === 1];
                        $save($g);
                    }
                } else {
                    foreach (['x' => ['arrow.left.to.line', '$XOffset', ['+' => '$left', '-' => '$right']], 'y' => ['arrow.up.to.line', '$YOffset', ['-' => '$top', '+' => '$bottom']]] as $axis => [$icon, $name, $sides]) {
                        [$sign, $value, $percent] = $g[$axis];
                        $c->row(0, 6)->alignItems(Node::CENTER);
                          MoviePlayerSettings::sf($c, $icon, 12, $c->theme->textMuted);
                          $c->text($tr($name), 11, $c->theme->textMuted);
                          $n = MoviePlayerSettings::number($c, $this->v, "geometry.$axis", (float)$value, 0, 1e5, 0, false, '', true);
                          $u = MoviePlayerSettings::popUp($c, $units, $percent ? 1 : 0, false, true);
                        $c->end();
                        $c->row(0, 6)->alignItems(Node::CENTER)->paddingStart(18);
                          $c->text($tr('$toThe'), 11, $c->theme->textMuted);
                          $keys = array_keys($sides);
                          $a = MoviePlayerSettings::popUp($c, array_map($tr, array_values($sides)), array_search($sign, $keys, true), false, true);
                          $c->text($tr('$sideOfTheScreen'), 11, $c->theme->textMuted);
                        $c->end();
                        if ($n !== null || $u !== null || $a !== null) {
                            $g[$axis] = [$a === null ? $sign : $keys[$a], $n === null ? $value : (string)(int)$n, $u === null ? $percent : $u === 1];
                            $save($g);
                        }
                    }
                }
                $c->end();
            });
    }

    /**
     * mpv's geometry as IINA's settings write it: a size, "1280" wide or
     * "x720" high, "%" of the screen after it; a position, "+x" from the
     * left or "-x" from the right, "+y" from the bottom or "-y" from the top,
     * each its "%".
     * @return array{size: ?array{0: string, 1: string, 2: bool}, x: ?array{0: string, 1: string, 2: bool}, y: ?array{0: string, 1: string, 2: bool}}
     */
    public static function parseGeometry(string $g): array
    {
        $out = ['size' => null, 'x' => null, 'y' => null];
        if (preg_match('/^(?:(x?)(\d+)(%?))?(?:([+-])(\d+)(%?)([+-])(\d+)(%?))?$/', $g, $m) !== 1 || $g === '') {
            return $out;
        }
        if (($m[2] ?? '') !== '') { $out['size'] = [$m[1] === 'x' ? 'h' : 'w', $m[2], $m[3] === '%']; }
        if (($m[4] ?? '') !== '') { [$out['x'], $out['y']] = [[$m[4], $m[5], $m[6] === '%'], [$m[7], $m[8], $m[9] === '%']]; }
        return $out;
    }

    /** The geometry parseGeometry reads back. @param array{size: ?array, x: ?array, y: ?array} $g */
    public static function geometryString(array $g): string
    {
        $out = '';
        if ($g['size'] !== null) { $out .= ($g['size'][0] === 'h' ? 'x' : '') . $g['size'][1] . ($g['size'][2] ? '%' : ''); }
        if ($g['x'] !== null && $g['y'] !== null) {
            foreach ([$g['x'], $g['y']] as [$sign, $value, $percent]) { $out .= $sign . $value . ($percent ? '%' : ''); }
        }
        return $out;
    }

    private function video(): array
    {
        return $this->page('video', MoviePlayerSettings::iina($this->c, 'sidebar.video'), 'photo.tv', [
            $this->section('$Decoding', [[
                $this->input('videoThreads', 'number', 0, 1e9, false, desc: true),
                $this->item('hardwareDecoder.label', symbol: 'cpu', terms: $this->optionTerms('hardwareDecoder', [0, 1], true), detail: function (): void {
                    $chosen = MoviePlayerSettings::selection($this->c, $this->options('hardwareDecoder', [0, 1], true), $this->set->get('hardwareDecoder'));
                    if ($chosen !== null) { $this->s->setSetting('hardwareDecoder', $chosen); }
                }),
            ]]),
            $this->section('$LiveText', [[
                $this->sw('enableLiveText', 'text.viewfinder', desc: true),
            ]]),
            $this->section('$ColorHDR', [[
                $this->sw('enableHdrSupport', ['sun.lefthalf.filled', 'sun.max'], desc: true),
            ]]),
        ]);
    }

    /**
     * IINA's Subtitles page: auto load (its matching, the priority words,
     * the search path), ASS's override level (the primary's, the
     * secondary's), the text subtitles' style (font, size, bold, italic,
     * color, border style, border, shadow, blur, spacing), their place
     * (alignment, margins, vertical position, scaled with the window, in
     * the black bars), the online subtitles, the preferred languages and
     * the default encoding.
     */
    private function subtitles(): array
    {
        $c = $this->c;
        $tx = $c->texts();
        $mpv = 'https://mpv.io/manual/stable';
        return $this->page('subtitles', MoviePlayerSettings::iina($c, 'sidebar.sub'), 'captions.bubble', [
            $this->section('$AutoLoad', [[
                $this->popup('subAutoLoadIINA', ['bolt.badge.automatic', 'bolt.badge.a'], [0, 1, 2]),
                $this->item('$Advanced', expand: true, sub: [$this->longInput('subAutoLoadPriorityString'), $this->longInput('subAutoLoadSearchPath')]),
            ]]),
            $this->section('$ASSSubtitles', [[
                $this->item('$OverrideLevel', symbol: 'pencil.slash', help: "$mpv/#options-sub-ass-override", id: 'subOverrideLevel', terms: $this->optionTerms('subOverrideLevel', [4, 0, 3, 1, 2], true),
                    value: function (): void {
                        $i = $this->v->assSecondary ? 1 : 0;
                        if ($this->c->changed($this->c->segmented(['Primary', 'Secondary'], $i))) { $this->v->assSecondary = $i === 1; } // IINA's, of no translation
                    },
                    detail: function (): void {
                        $key = $this->v->assSecondary ? 'secondarySubOverrideLevel' : 'subOverrideLevel';
                        $chosen = MoviePlayerSettings::selection($this->c, $this->options('subOverrideLevel', [4, 0, 3, 1, 2], true), $this->set->get($key));
                        if ($chosen !== null) { $this->s->setSetting($key, $chosen); }
                    }),
            ]]),
            $this->section('$TextSubtitles', [[
                $this->item('$Font', symbol: 'textformat', id: 'subTextFont', terms: [MoviePlayerSettings::tr($c, 'subBold.label'), MoviePlayerSettings::tr($c, 'subItalic.label')], value: function (): void {
                    $c = $this->c;
                    $c->row(0, 6)->alignItems(Node::CENTER);
                      $b = $c->row()->height(25)->minWidth(120)->paddingX(8)->radius(6)->center()->role(Role::BUTTON)->label(MoviePlayerSettings::tr($c, '$Font'))->borderWidth(1)->borderColor($c->theme->dark ? 0x4a4a4a : 0xd0d0d0);
                        $c->text($this->set->get('subTextFont'), 13, $c->theme->text)->singleLine();
                      $c->end();
                      if ($c->clicked($b)) { MoviePlayerFontSheet::open($this->set->get('subTextFont'), fn(string $f) => $this->s->setSetting('subTextFont', $f)); }
                      $n = MoviePlayerSettings::number($c, $this->v, 'subTextSize', $this->set->get('subTextSize'), 1, 9000, 0, true);
                      if ($n !== null) { $this->s->setSetting('subTextSize', $n); }
                      foreach (['subBold' => 'bold', 'subItalic' => 'italic'] as $key => $symbol) {
                          if (MoviePlayerSettings::symbolButton($c, $symbol, MoviePlayerSettings::tr($c, "$key.label"), $this->set->get($key))) { $this->s->setSetting($key, !$this->set->get($key)); }
                      }
                    $c->end();
                }),
                $this->item('$Color', symbol: 'paintpalette', id: 'subTextColorString', value: fn() => $this->colorWell('subTextColorString')),
                $this->popup('subBorderStyle', ['character.textbox.badge.sparkles', 'square.dashed.inset.filled', 'square.dashed'], [1, 3, 4]),
                $this->item('$Border', symbol: ['inset.filled.circle.dashed', 'circle.dashed.inset.filled', 'circle.dashed.inset.fill'], id: 'subBorderSize', value: fn() => $this->sizeAndColor('$Size', 'subBorderSize', 'subBorderColorString')),
                $this->item('$Shadow', symbol: ['shadow'], id: 'subShadowSize', value: fn() => $this->sizeAndColor('$Offset', 'subShadowSize', 'subShadowColorString')),
                $this->item('$OtherStyles', symbol: ['textformat.characters.arrow.left.and.right', 'star.leadinghalf.filled', 'star.leadinghalf.fill'], expand: true, sub: [
                    $this->input('subBlur', null, 0, 20, true),
                    $this->input('subSpacing', null, -10, 10, true),
                ]),
            ]]),
            $this->section('$Position', [[
                $this->item('$Align', symbol: 'arrow.up.and.down.and.arrow.left.and.right', id: 'subAlignX', terms: [...$this->optionTerms('subAlignX', [0, 1, 2]), ...$this->optionTerms('subAlignY', [0, 1, 2])], value: function (): void {
                    $c = $this->c;
                    $c->row(0, 6)->alignItems(Node::CENTER);
                      foreach (['$X' => 'subAlignX', '$Y' => 'subAlignY'] as $label => $key) {
                          $c->text(trim(MoviePlayerSettings::tr($c, $label)), 11, $c->theme->text);
                          $picked = MoviePlayerSettings::popUp($c, array_map(fn(array $o) => $o[0], $this->options($key, [0, 1, 2])), $this->set->get($key), small: true);
                          if ($picked !== null) { $this->s->setSetting($key, $picked); }
                      }
                    $c->end();
                }),
                $this->item('$Margin', symbol: 'arrow.down.to.line', id: 'subMarginX', value: function (): void {
                    $c = $this->c;
                    $c->row(0, 6)->alignItems(Node::CENTER);
                      foreach (['$X' => 'subMarginX', '$Y' => 'subMarginY'] as $label => $key) {
                          $c->text(trim(MoviePlayerSettings::tr($c, $label)), 11, $c->theme->text);
                          $n = MoviePlayerSettings::number($c, $this->v, $key, $this->set->get($key), 0, PHP_INT_MAX, 0, false, small: true);
                          if ($n !== null) { $this->s->setSetting($key, $n); }
                      }
                    $c->end();
                }),
                $this->input('subPos', 'arrow.up.and.down', 0, 150, false, '$Percent'),
            ], [
                $this->sw('subScaleWithWindow', ['arrow.up.left.and.arrow.down.right.rectangle', 'arrow.up.backward.and.arrow.down.forward']),
            ], [
                $this->sw('displayInLetterBox', ['inset.filled.bottomthird.rectangle', 'rectangle.bottomthird.inset.filled', 'rectangle.bottomthird.inset.fill']),
            ]]),
            $this->section('$OnlineSubtitles', [[
                $this->item('$SubtitleSource', symbol: 'server.rack', terms: [MoviePlayerSettings::tr($c, '$SubtitleSource.desc'), 'opensubtitles.com', $tx->get('videoOpenSubtitlesAccount')],
                    detail: fn() => $this->source()),
                $this->sw('autoSearchOnlineSub', ['text.magnifyingglass', 'magnifyingglass'], desc: true),
            ]]),
            $this->section('$Other', [[
                $this->languages('subLang', MoviePlayerSettings::tr($c, 'subLang.desc')),
                $this->item('$DefaultEncoding', id: 'defaultEncoding', detail: function (): void {
                    $options = ['auto' => MoviePlayerSettings::iina($this->c, 'subencoding.auto')] + array_column(array_map(fn(array $e) => [$e[1], $e[0]], self::ENCODINGS), 1, 0);
                    $this->c->column()->padding(0, 8, 8, MoviePlayerSettings::INDENT)->fillWidth();
                      $picked = MoviePlayerSettings::popUp($this->c, $options, $this->set->get('defaultEncoding'), fill: true);
                    $this->c->end();
                    if ($picked !== null) { $this->s->setSetting('defaultEncoding', (string)$picked); }
                }),
            ]]),
        ]);
    }

    /** IINA's encodings of subtitles (CharEncoding.list), after its "Auto detect": [title, iconv's name]. */
    private const ENCODINGS = [['Universal (UTF-8)', 'UTF-8'], ['Universal (UTF-16)', 'UTF-16'], ['Universal (UTF-16BE)', 'UTF-16BE'], ['Universal (UTF-16LE)', 'UTF-16LE'],
        ['Arabic (ISO-8859-6)', 'ISO-8859-6'], ['Arabic (WINDOWS-1256)', 'WINDOWS-1256'], ['Baltic (LATIN7)', 'LATIN7'], ['Baltic (WINDOWS-1257)', 'WINDOWS-1257'], ['Celtic (LATIN8)', 'LATIN8'],
        ['Central European (WINDOWS-1250)', 'WINDOWS-1250'], ['Cyrillic (ISO-8859-5)', 'ISO-8859-5'], ['Cyrillic (WINDOWS-1251)', 'WINDOWS-1251'], ['Eastern European (ISO-8859-2)', 'ISO-8859-2'],
        ['Western Languages (WINDOWS-1252)', 'WINDOWS-1252'], ['Greek (ISO-8859-7)', 'ISO-8859-7'], ['Greek (WINDOWS-1253)', 'WINDOWS-1253'], ['Hebrew (ISO-8859-8)', 'ISO-8859-8'],
        ['Hebrew (WINDOWS-1255)', 'WINDOWS-1255'], ['Japanese (SHIFT-JIS)', 'SHIFT-JIS'], ['Japanese (ISO-2022-JP-2)', 'ISO-2022-JP-2'], ['Korean (EUC-KR)', 'EUC-KR'], ['Korean (CP949)', 'CP949'],
        ['Korean (ISO-2022-KR)', 'ISO-2022-KR'], ['Nordic (LATIN6)', 'LATIN6'], ['North European (LATIN4)', 'LATIN4'], ['Russian (KOI8-R)', 'KOI8-R'], ['Simplified Chinese (GBK)', 'GBK'],
        ['Simplified Chinese (GB18030)', 'GB18030'], ['Simplified Chinese (ISO-2022-CN-EXT)', 'ISO-2022-CN-EXT'], ['South European (LATIN3)', 'LATIN3'], ['South-Eastern European (LATIN10)', 'LATIN10'],
        ['Thai (TIS-620)', 'TIS-620'], ['Thai (WINDOWS-874)', 'WINDOWS-874'], ['Traditional Chinese (EUC-TW)', 'EUC-TW'], ['Traditional Chinese (BIG5)', 'BIG5'], ['Traditional Chinese (BIG5-HKSCS)', 'BIG5-HKSCS'],
        ['Turkish (LATIN5)', 'LATIN5'], ['Turkish (WINDOWS-1254)', 'WINDOWS-1254'], ['Ukrainian (KOI8-U)', 'KOI8-U'], ['Vietnamese (WINDOWS-1258)', 'WINDOWS-1258'], ['Vietnamese (VISCII)', 'VISCII'],
        ['Western European (LATIN1)', 'LATIN1'], ['Western European (LATIN-9)', 'LATIN-9']];

    /**
     * IINA's Network page: the cache (whether it waits for it, before it
     * starts too, for how long, how far ahead, how much), its throbbers, the
     * User-Agent and the HTTP proxy, yt-dlp (whether pages are read by it,
     * where it is, its options). (Its RTSP transport is FFmpeg's, of no
     * RTSP here; its advice of the Online Media plugin is of plugins there
     * are none of.)
     */
    private function network(): array
    {
        $mpv = 'https://mpv.io/manual/stable';
        return $this->page('network', MoviePlayerSettings::iina($this->c, 'preference.network'), 'globe', [
            $this->section('$Cache', [[
                $this->sw('enableCache', 'inset.filled.topthird.middlethird.bottomthird.rectangle', sub: [
                    $this->sw('cachePauseInitial', desc: true, help: "$mpv/#options-cache-pause-initial"),
                    $this->input('cachePauseWait', null, 0, 3.4028234663853e+38, true, desc: true, help: "$mpv/#options-cache-pause-wait"),
                    $this->input('secPrefech', null, 0, PHP_INT_MAX, false, desc: true, help: "$mpv/#options-cache-secs"),
                    $this->input('defaultCacheSize', null, 0, 4.5035996273705078125e+15, false, desc: true, help: "$mpv/#options-demuxer-max-bytes"),
                ]),
                $this->sw('showBufferingThrobber', ['progress.indicator']),
                $this->sw('showSeekingThrobber'),
            ]]),
            $this->section('$Network', [[
                $this->longInput('userAgent', ['person.crop.circle'], false),
                $this->longInput('httpProxy', ['rectangle.connected.to.line.below']),
            ]]),
            $this->section('$YTDL', [[
                $this->sw('ytdlEnabled', 'square.and.arrow.down', help: 'https://github.com/rg3/youtube-dl/blob/master/README.md#readme', sub: [
                    $this->longInput('ytdlSearchPath'),
                    $this->longInput('ytdlRawOptions'),
                ]),
            ]]),
        ]);
    }

    /**
     * IINA's Control page: what a pinch and a Force Touch do, what the
     * clicks do (single, double, right, middle), whether the click that
     * brings the window forward clicks too, what the wheel does up and down
     * and across, the seek type, and how sensitive the wheel is.
     */
    private function control(): array
    {
        return $this->page('control', MoviePlayerSettings::iina($this->c, 'preference.control'), 'computermouse', [
            $this->section('$Trackpad', [[
                $this->popup('pinchAction', 'rectangle.fill', [0, 1, 2]),
                $this->popup('forceTouchAction', null, [0, 1, 2, 3]),
            ]]),
            $this->section('$Mouse', [[
                $this->popup('singleClickAction', ['pointer.arrow.click', 'cursorarrow.click'], [0, 2, 3]),
                $this->popup('doubleClickAction', null, [0, 1, 2, 4, 5, 6]),
                $this->popup('rightClickAction', null, [0, 2, 3, 4, 5, 6]),
                $this->popup('middleClickAction', null, [0, 1, 2, 3, 4, 5, 6]),
                $this->sw('videoViewAcceptsFirstMouse', ['macwindow.and.pointer.arrow', 'macwindow.and.cursorarrow']),
            ], [
                $this->popup('verticalScrollAction', 'magicmouse.fill', [0, 1, 2, 4]),
                $this->popup('horizontalScrollAction', null, [0, 1, 2, 4]),
            ], [
                $this->popup('useExactSeek', ['15.arrow.trianglehead.clockwise', 'goforward.15'], [0, 1, 2], desc: true),
                $this->sensitivity('relativeSeekAmount'),
                $this->sensitivity('playbackSpeedScrollAmount'),
                $this->sensitivity('volumeScrollAmount'),
            ]]),
        ]);
    }

    /**
     * IINA's Key Bindings page: the system's media control; the set of key
     * bindings the players take (IINA's locked, the user's), deleted, made
     * (empty, a copy of the one shown, a file imported), shown in the
     * Finder; its bindings, searched, a click choosing one, edited or taken
     * out (the user's sets only), one added; shown raw or in words.
     */
    private function keyBindings(): array
    {
        $c = $this->c;
        return $this->page('key.bindings', MoviePlayerSettings::iina($c, 'preference.keybindings'), 'keyboard', [
            $this->section('', [[
                $this->sw('useMediaKeys', ['playpause.circle', 'playpause'], help: 'https://github.com/iina/iina/wiki/Manage-Key-Bindings'),
            ]]),
            $this->section('', [[
                $this->item('$KeyBindingSet', symbol: ['book.and.wrench', 'wrench.adjustable', 'wrench'], id: 'currentInputConfigName', value: fn() => $this->keySetChooser(),
                    detail: fn() => $this->keyEditor()),
                $this->sw('displayKeyBindingRawValues'),
            ]]),
        ], subsections: false);
    }

    /** The set chosen (a pop-up of them, IINA's locked), its Delete and its menu of new sets. */
    private function keySetChooser(): void
    {
        $c = $this->c;
        $name = $this->set->get('currentInputConfigName');
        $names = MoviePlayerKeys::configNames();
        $options = array_combine($names, array_map(fn(string $n) => (MoviePlayerKeys::editable($n) ? '' : '🔒 ') . $n, $names));
        if (!isset($options[$name])) { $options[$name] = $name; }
        $c->row(0, 6)->alignItems(Node::CENTER);
          $picked = MoviePlayerSettings::popUp($c, $options, $name);
          if ($picked !== null && $picked !== $name) { $this->chooseKeySet((string)$picked); }
          $del = $c->row();
          if (!MoviePlayerKeys::editable($name)) { $del->disabled(); }
            if (MoviePlayerSettings::symbolButton($c, 'trash', 'Delete Configuration') && MoviePlayerKeys::editable($name) && $this->ask('delete_keybindingset')) {
                if (!@unlink((string)MoviePlayerKeys::path($name))) { $this->alert('error_deleting_file'); }
                $this->chooseKeySet(MoviePlayerKeys::FALLBACK);
            }
          $c->end();
          $add = $c->row()->size(32, 25)->radius(6)->center()->role(Role::BUTTON)->label('Add Configuration')->borderWidth(1)->borderColor($c->theme->dark ? 0x4a4a4a : 0xd0d0d0);
            MoviePlayerSettings::sf($c, 'square.grid.3x1.folder.badge.plus', 13, $c->theme->text);
          $c->end();
          $add->menu(function (Menu $m) use ($c, $name): void {
              $m->header(MoviePlayerSettings::tr($c, '$NewKeyBindingSet'));
              $valid = function (string $n): ?string {
                  if ($n === '') { return MoviePlayerSettings::iina($this->c, 'input.value_is_empty'); }
                  return MoviePlayerKeys::path($n) !== null ? MoviePlayerSettings::iina($this->c, 'input.already_exists') : null;
              };
              if ($m->item(MoviePlayerSettings::tr($c, '$CreateAnEmptySet'))->chosen()) {
                  MoviePlayerAlert::prompt($c, 'config.new', '', $valid, function (string $n): void { $this->newKeySet($n, ''); });
              }
              if ($m->item(MoviePlayerSettings::tr($c, '$DuplicateCurrentSet'))->chosen()) {
                  MoviePlayerAlert::prompt($c, 'config.duplicate', '', $valid, function (string $n) use ($name): void { $this->newKeySet($n, (string)@file_get_contents((string)MoviePlayerKeys::path($name))); });
              }
              if ($m->item(MoviePlayerSettings::tr($c, '$ImportAnExistingConfigFile'))->chosen()) {
                  $paths = Dialog::open(new OpenDialogOptions(parent: Window::focused(), title: 'Select Config File to Import', filters: [new \MyPHP\FileFilter('Config', ['conf'])]));
                  if ($paths !== [] && str_ends_with($paths[0], '.conf')) { $this->newKeySet(pathinfo($paths[0], PATHINFO_FILENAME), (string)@file_get_contents($paths[0])); }
              }
              $m->separator();
              if ($m->item(MoviePlayerSettings::tr($c, '$ShowTheConfigFileIn'))->chosen()) { \MyPHP\Shell::showItemInFolder((string)MoviePlayerKeys::path($name)); }
          });
        $c->end();
    }

    /** Set $name the players' (IINA's loadConfigFile): one that does not read told, IINA's default instead. */
    private function chooseKeySet(string $name): void
    {
        $path = MoviePlayerKeys::path($name);
        if ($path === null || MoviePlayerKeys::read($path) === null) {
            Dialog::message(new MessageOptions(parent: Window::focused(), type: 'warning', message: sprintf(str_replace('%@', '%s', MoviePlayerSettings::iina($this->c, 'alert.keybinding_config.error')), $name)));
            $name = MoviePlayerKeys::FALLBACK;
        }
        $this->s->setSetting('currentInputConfigName', $name);
        $this->v->kbChosen = null;
    }

    /** A set of the user's named $name, of $text, made and chosen; one of that name there already shown in the Finder (IINA's file_existing). */
    private function newKeySet(string $name, string $text): void
    {
        $path = MoviePlayerKeys::userDir() . "/$name.conf";
        if (is_file($path)) {
            if ($this->ask('config.file_existing')) { \MyPHP\Shell::showItemInFolder($path); }
            return;
        }
        if (@file_put_contents($path, $text) === false) {
            Dialog::message(new MessageOptions(parent: Window::focused(), type: 'warning', message: sprintf(str_replace('%@', '%s', MoviePlayerSettings::iina($this->c, 'alert.config.cannot_create')), error_get_last()['message'] ?? '')));
            return;
        }
        $this->chooseKeySet($name);
    }

    /** The bindings of the set chosen: the search and the add button over them, each its action, its key, and, chosen, its buttons. */
    private function keyEditor(): void
    {
        $c = $this->c;
        $t = $c->theme;
        $k = MoviePlayerSettings::colors($t);
        $name = $this->set->get('currentInputConfigName');
        $editable = MoviePlayerKeys::editable($name);
        $raw = $this->set->get('displayKeyBindingRawValues');
        $all = MoviePlayerKeys::current($this->set);
        $this->v->kbSearch ??= new TextState();
        $q = mb_strtolower(trim($this->v->kbSearch->text));
        $c->column(0, 0)->padding(0, 0, 8, 16)->fillWidth();
          $c->row(0, 8)->fillWidth()->alignItems(Node::CENTER)->padding(8, 16, 0, 0);
            $c->searchField($this->v->kbSearch, 0);
            $plus = $c->column()->size(26, 26)->radius(13)->center()->role(Role::BUTTON)->label('Add')->background($k['control'], $k['controlAlpha']);
              MoviePlayerSettings::sf($c, $editable ? 'plus' : 'lock', 13, $t->text);
            $c->end();
            if ($c->clicked($plus)) {
                $editable ? MoviePlayerKeyRecord::open($c, '', '', function (string $key, string $action) use ($all): void { $this->saveKeys([...$all, MoviePlayerKeys::mapping($key, $action)]); }) : $this->alert('duplicate_config');
            }
          $c->end();
          foreach ($all as $i => $m) {
              $action = MoviePlayerKeys::displayAction($c, $m, $raw);
              if ($q !== '' && !str_contains(mb_strtolower(MoviePlayerKeys::rawAction($m)), $q) && !str_contains(mb_strtolower(MoviePlayerKeys::readable($c, $m['action'], $m['iina'])), $q)) { continue; }
              $on = $this->v->kbChosen === $i;
              $r = $c->key("binding:$i")->row(0, 6)->height(32)->fillWidth()->paddingX(4)->radius(6)->alignItems(Node::CENTER);
              if ($on) { $r->background($t->accent, 0.2); }
                $text = $c->text($action, 13, $t->text)->singleLine()->ellipsis('…')->grow(1);
                if ($raw) { $text->font('monospace'); }
                if ($on && $editable) {
                    if (MoviePlayerSettings::symbolButton($c, 'trash.fill', 'Remove', false, 20)) { $this->saveKeys(array_values(array_filter($all, fn($_, $j) => $j !== $i, ARRAY_FILTER_USE_BOTH))); $this->v->kbChosen = null; }
                    if (MoviePlayerSettings::symbolButton($c, 'pencil', 'Edit', false, 20)) {
                        MoviePlayerKeyRecord::open($c, $m['key'], MoviePlayerKeys::rawAction($m), function (string $key, string $act) use ($all, $i, $m): void {
                            $all[$i] = MoviePlayerKeys::mapping($key, $act, false, $m['comment']);
                            $this->saveKeys($all);
                        });
                    }
                } elseif ($on && MoviePlayerSettings::symbolButton($c, 'lock', 'Help', false, 20)) {
                    $this->alert('duplicate_config');
                }
                $c->row()->height(28)->minWidth(28)->paddingX(4)->radius(4)->center()->borderWidth(1)->borderColor($k['line'])->background($t->dark ? 0x2a2a2a : 0xffffff);
                  $key = $c->text(MoviePlayerKeys::displayKey($m, $raw), 13, $t->text)->singleLine();
                  if ($raw) { $key->font('monospace'); }
                $c->end();
              $c->end();
              if ($c->clicked($r)) { $this->v->kbChosen = $i; }
          }
        $c->end();
    }

    /** The bindings $mappings written to the user's set chosen (IINA's saveToConfFile), told where it cannot be. @param list<array<string, mixed>> $mappings */
    private function saveKeys(array $mappings): void
    {
        $path = MoviePlayerKeys::path($this->set->get('currentInputConfigName'));
        if ($path === null || @file_put_contents($path, MoviePlayerKeys::generate($mappings)) === false) { $this->alert('config.cannot_write'); }
    }

    /** IINA's question alert.$what (OK, or Cancel). */
    private function ask(string $what): bool
    {
        $c = $this->c;
        return Dialog::message(new MessageOptions(parent: Window::focused(), type: 'warning', message: MoviePlayerSettings::iina($c, "alert.$what.title"),
            detail: MoviePlayerSettings::iina($c, "alert.$what.message"), buttons: [$c->texts()->get('ok'), $c->texts()->get('cancel')]))->response === 0;
    }

    /** IINA's alert alert.$what. */
    private function alert(string $what): void
    {
        Dialog::message(new MessageOptions(parent: Window::focused(), type: 'warning', message: MoviePlayerSettings::iina($this->c, "alert.$what")));
    }

    /**
     * IINA's Advanced page: the advanced settings on (all below held while
     * off), the log's level, logging to a file and its folder, the log
     * viewer. (Its mpv OSD, mpv's config folder and options are mpv's, of
     * no mpv here.)
     */
    private function advanced(): array
    {
        $c = $this->c;
        $on = fn(): bool => $this->set->get('enableAdvancedSettings');
        $held = function (array $item) use ($on): array { // enabled while the advanced settings are on, as IINA's setControlsEnabled
            $item['holds'] = $on;
            return $item;
        };
        $logging = $this->sw('enableLogging');
        $toggle = $logging['value'];
        $logging['value'] = function () use ($c, $toggle): void {
            $c->row(0, 8)->alignItems(Node::CENTER);
              if (MoviePlayerSettings::pushButton($c, MoviePlayerSettings::tr($c, '$OpenLogDirectory'))) { \MyPHP\Shell::openPath(\MyPHP\Logger::directory()); }
              $toggle();
            $c->end();
        };
        return $this->page('advanced', MoviePlayerSettings::iina($c, 'preference.advanced'), 'flask', [
            $this->section('', [[
                $this->sw('enableAdvancedSettings', ['flask'], desc: true, help: 'https://github.com/iina/iina/wiki/MPV-Options-and-Properties'),
            ]]),
            $this->section('$Logging', [[
                $held($this->popup('logLevel', 'cylinder.split.1x2', [0, 1, 2, 3], desc: true)),
                $held($logging),
            ], [
                $held($this->item('$OpenLogWindow', symbol: 'macwindow', value: function () use ($c): void {
                    if (MoviePlayerSettings::symbolButton($c, 'arrow.right', MoviePlayerSettings::tr($c, '$OpenLogWindow'))) { LogViewer::open(); }
                })),
            ]]),
        ], subsections: false);
    }

    /**
     * IINA's Utilities page: the app made the default of its files (its
     * sheet), the suppressed alerts restored, the saved places, the history
     * and the thumbnails cleared (asked first, told after). (Its browser
     * extensions are IINA's own, sending iina:// links.)
     */
    private function utilities(): array
    {
        $c = $this->c;
        $go = fn(string $symbol, string $label, Closure $then): Closure => function () use ($c, $symbol, $label, $then): void { // IINA's button after the row: its symbol (trash, else an arrow)
            if (MoviePlayerSettings::symbolButton($c, $symbol, MoviePlayerSettings::tr($c, $label))) { $then(); }
        };
        $done = function (string $what): void { // IINA's informational alert
            Dialog::message(new MessageOptions(parent: Window::focused(), message: MoviePlayerSettings::iina($this->c, "alert.$what")));
        };
        $ask = fn(string $what): bool => Dialog::message(new MessageOptions(parent: Window::focused(), type: 'warning', message: MoviePlayerSettings::iina($c, "alert.$what.title"),
            detail: MoviePlayerSettings::iina($c, "alert.$what.message"), buttons: [$c->texts()->get('ok'), $c->texts()->get('cancel')]))->response === 0;
        return $this->page('utilities', MoviePlayerSettings::iina($c, 'preference.utilities'), 'wrench.and.screwdriver', [
            $this->section('$DefaultApplication', [[
                $this->item('$SetIINAAsTheDefault', symbol: ['app.badge.checkmark.fill', 'app'], value: $go('arrow.right', '$SetIINAAsTheDefault', fn() => MoviePlayerDefaultAppSheet::open())),
            ]]),
            $this->section('$RestoreAlerts', [[
                $this->item('$RestoreSuppressedAlerts', symbol: 'arrow.counterclockwise', desc: MoviePlayerSettings::tr($c, '$RestoreAllAlertsThat'),
                    value: $go('arrow.right', '$RestoreSuppressedAlerts', function () use ($ask, $done): void { if ($ask('restore_alerts')) { $done('restored'); } })), // as IINA's: none is suppressed
            ]]),
            $this->section('$ClearCache', [[
                $this->item('$ClearSavedPlaybackProgress', symbol: 'clock', desc: MoviePlayerSettings::tr($c, '$DeleteAllWatchLater'),
                    value: $go('trash', '$ClearSavedPlaybackProgress', function () use ($ask, $done): void { if ($ask('clear_watch_later')) { $this->s->clearPositions(); $done('cleared'); } })),
                $this->item('$ClearPlaybackHistory', symbol: ['document.badge.clock', 'doc.badge.clock', 'doc'], desc: MoviePlayerSettings::tr($c, '$DeleteAllPlaybackHistories'),
                    value: $go('trash', '$ClearPlaybackHistory', function () use ($ask, $done): void { if ($ask('clear_history')) { $this->s->clearHistory(); $done('cleared'); } })),
                $this->item('$ClearThumbnailCache', symbol: 'photo', value: function () use ($c, $ask): void {
                    $c->row(0, 8)->alignItems(Node::CENTER);
                      $this->v->thumbBytes ??= $this->s->thumbnailBytes(); // as the page opens, as IINA's label
                      $c->text(MoviePlayerState::byteCount($this->v->thumbBytes, true) . 'B', 11, $c->theme->textMuted);
                      if (MoviePlayerSettings::symbolButton($c, 'trash', MoviePlayerSettings::tr($c, '$ClearThumbnailCache')) && $ask('clear_cache')) { $this->v->thumbBytes = $this->s->thumbnailBytes(true); }
                    $c->end();
                }),
            ]]),
        ], subsections: false);
    }

    /** IINA's slider of a sensitivity, 1 to 4 at its 4 ticks, labeled "$key.label" (its SliderView). */
    private function sensitivity(string $key): array
    {
        return $this->item("$key.label", id: $key, value: function () use ($key): void {
            $c = $this->c;
            $t = $c->theme;
            $v = (float)max(1, min(4, $this->set->get($key)));
            $slider = $c->sliderBase($v, 1, 4, 1)->size(100, 20);
            $slider->draw(static function (Painter $p, array $r) use ($slider, $t): void {
                $x0 = $r[0] + 6; $w = $r[2] - 12; $cy = $r[1] + 8;
                $p->fill([$x0, $cy - 2, $w, 4], $t->dark ? 0xffffff : 0x000000, 2, 0.15);
                $p->fill([$x0, $cy - 2, $w * $slider->fraction, 4], $t->accent, 2);
                for ($i = 0; $i < 4; $i++) { $p->fill([$x0 + $w * $i / 3 - 0.5, $cy + 5, 1, 4], $t->dark ? 0xffffff : 0x000000, 0, 0.35); } // its ticks, under it
                $p->fill([$x0 + $w * $slider->fraction - 6, $cy - 6, 12, 12], 0xffffff, 6);
            });
            if ($c->changed($slider)) { $this->s->setSetting($key, (int)round($v)); }
        });
    }

    /** A field of text of setting $key, labeled "$key.label" over it and described under it where $desc (SettingsItem.LongInput, small). */
    private function longInput(string $key, string|array|null $symbol = null, bool $desc = true): array
    {
        return $this->item("$key.label", $symbol, desc: $desc ? MoviePlayerSettings::tr($this->c, "$key.desc") : '', id: $key, detail: function () use ($key): void {
            $this->c->column()->padding(0, 8, 8, MoviePlayerSettings::INDENT)->fillWidth();
              $text = MoviePlayerSettings::textField($this->c, $this->v, $key, $this->set->get($key));
            $this->c->end();
            if ($text !== null) { $this->s->setSetting($key, $text); }
        });
    }

    /** A color well of setting $key, a color as IINA keeps it (r/g/b/a). */
    private function colorWell(string $key): void
    {
        [$rgb, $alpha] = MoviePlayerSubtitleStyle::color($this->set->get($key));
        if ($this->c->changed($this->c->colorWell($rgb, $alpha))) { $this->s->setSetting($key, MoviePlayerSubtitleStyle::colorString($rgb, $alpha)); }
    }

    /** IINA's border and shadow rows: "$label" and a field of $sizeKey, "Color" and a well of $colorKey. */
    private function sizeAndColor(string $label, string $sizeKey, string $colorKey): void
    {
        $c = $this->c;
        $c->row(0, 6)->alignItems(Node::CENTER);
          $c->text(MoviePlayerSettings::tr($c, $label), 11, $c->theme->text);
          $n = MoviePlayerSettings::number($c, $this->v, $sizeKey, $this->set->get($sizeKey), $sizeKey === 'subBorderSize' ? 0 : -PHP_INT_MAX, PHP_INT_MAX, 0, true, small: true);
          if ($n !== null) { $this->s->setSetting($sizeKey, $n); }
          $c->text(MoviePlayerSettings::tr($c, '$Color'), 11, $c->theme->text);
          $this->colorWell($colorKey);
        $c->end();
    }

    /** IINA's preferred language (SettingsAccessory.LanguageSelector) of setting $key: its languages, the system's offered. */
    private function languages(string $key, string $desc = ''): array
    {
        $c = $this->c;
        $tx = $c->texts();
        return $this->item('$PreferredLanguage', symbol: 'character.book.closed', desc: $desc, id: $key, terms: [$tx->get('videoPreferredLanguageHelp')], detail: function () use ($c, $tx, $key): void {
            $c->column(0, 6)->padding(-4, 8, 8, MoviePlayerSettings::INDENT)->fillWidth();
              $languages = $this->set->get($key);
              $f = $c->tokenField($languages, array_values(array_diff(\MyPHP\App::preferredLanguages(), $languages)))->label(MoviePlayerSettings::tr($c, '$PreferredLanguage'));
              if ($c->changed($f)) { $this->s->setSetting($key, array_values(array_unique(array_filter(array_map('trim', $languages), fn(string $l) => $l !== '')))); }
              $c->text($tx->get('videoPreferredLanguageHelp'), 11, $c->theme->textMuted);
            $c->end();
        });
    }

    /**
     * IINA's Audio page: the preferred output device, the volume (initial,
     * maximum), ReplayGain, gapless audio, the preferred languages. (Its
     * audio threads, its driver choice and S/PDIF are mpv's, of no
     * equivalent here: the system's renderer decodes and outputs.)
     */
    private function audio(): array
    {
        $c = $this->c;
        return $this->page('audio', MoviePlayerSettings::iina($c, 'sidebar.audio'), 'waveform', [
            $this->section('$Hardware', [[
                $this->item('preferredAudioDevice.label', symbol: 'hifispeaker.and.homepod', id: 'audioDevice', detail: function (): void {
                    $this->v->audioDevices ??= \MyPHP\App::running() ? AudioDevices::outputs() : []; // listed as the page opens, as IINA's pop-up
                    $options = ['auto' => '[Autoselect device] auto']; // mpv's
                    foreach ($this->v->audioDevices as $uid => $name) { $options["coreaudio/$uid"] = "[$name] coreaudio/$uid"; }
                    $chosen = $this->set->get('audioDevice');
                    if (!isset($options[$chosen])) { $options[$chosen] = '[' . $this->set->get('audioDeviceDesc') . " (missing)] $chosen"; } // as IINA's: not connected now
                    $this->c->column()->padding(0, 8, 8, MoviePlayerSettings::INDENT)->fillWidth();
                      $picked = MoviePlayerSettings::popUp($this->c, $options, $chosen, fill: true);
                    $this->c->end();
                    if ($picked !== null && $picked !== $chosen) {
                        $this->s->setSetting('audioDeviceDesc', $picked === 'auto' ? 'Autoselect device' : $this->v->audioDevices[substr((string)$picked, strlen('coreaudio/'))] ?? $this->set->get('audioDeviceDesc'));
                        $this->s->setSetting('audioDevice', (string)$picked);
                    }
                }),
            ]]),
            $this->section('$Volume', [[
                $this->swInput('enableInitialVolume.label', 'enableInitialVolume', 'initialVolume', 'speaker.wave.3', 0, 1000, false),
                $this->input('maxVolume', null, 100, 1000, false, desc: true),
            ], [
                $this->popup('replayGain', 'speaker.plus', [0, 1, 2], disabledOn: 0, desc: true, sub: [
                    $this->input('replayGainPreamp', null, -150, 150, false, '$dB', desc: true),
                    $this->sw('replayGainClip', null, desc: true),
                ]),
                $this->input('replayGainFallback', 'square.dotted', -200, 60, false, '$dB', desc: true),
            ]]),
            $this->section('$Other', [[
                $this->item('gaplessAudio.label', symbol: 'waveform+arrow.trianglehead.2.clockwise.rotate.90', id: 'gaplessAudio', terms: $this->optionTerms('gaplessAudio', [0, 1, 2], true), detail: function (): void {
                    $chosen = MoviePlayerSettings::selection($this->c, $this->options('gaplessAudio', [0, 1, 2], true), $this->set->get('gaplessAudio'));
                    if ($chosen !== null) { $this->s->setSetting('gaplessAudio', $chosen); }
                }),
            ], [
                $this->languages('audioLanguage'),
            ]]),
        ]);
    }

    /** The subtitles' source, as IINA's: a pop-up of the sources (OpenSubtitles'), what it is; and the OpenSubtitles account, Login or Logout (IINA's legacy window's). */
    private function source(): void
    {
        $c = $this->c;
        $s = $this->s;
        $t = $c->theme;
        $tx = $c->texts();
        $c->column(0, 8)->padding(0, 8, 8, MoviePlayerSettings::INDENT)->fillWidth();
          MoviePlayerSettings::popUp($c, ['opensubtitles.com'], 0, true);
          $c->text(MoviePlayerSettings::tr($c, '$SubtitleSource.desc'), 11, $t->textMuted);
          $c->row(0, 8)->alignItems(Node::CENTER);
            $user = $this->set->get('openSubUsername');
            $c->text($tx->get('videoOpenSubtitlesAccount'), 11, $t->textMuted);
            $b = $c->row();
            if ($s->loggingIn) { $b->disabled(); }
              $clicked = MoviePlayerSettings::pushButton($c, $tx->get($user === '' ? 'videoLogin' : 'videoLogout'));
            $c->end();
            $c->text($user === '' ? $tx->get('videoNotLoggedIn') : $tx->get('videoLoggedInAs', ['user' => $user]), 11, $t->textMuted);
            if ($s->loggingIn) { $c->spinner(); }
          $c->end();
        $c->end();
        if (!$clicked) {
            return;
        }
        if ($user !== '') {
            $s->logOutOpenSubtitles();
        } elseif (($got = Dialog::login(Window::focused(), $tx->get('videoOpenSubtitlesLogin'), $tx->get('videoOpenSubtitlesLoginMessage'), $tx->get('videoUsername'), $tx->get('videoPassword'))) !== null && $got[0] !== '' && $got[1] !== '') {
            $s->logInOpenSubtitles($got[0], $got[1], static function (string $why) use ($tx): void { // IINA's alert, its reason under
                Dialog::message(new MessageOptions(parent: Window::focused(), type: 'warning', message: $tx->get('videoCannotLoginDetail', ['why' => $why])));
            });
        }
    }

    /** A page: its sections; $subsections, whether the sidebar lists them under it (IINA's showSubSections); $help, its ⓘ's. */
    private function page(string $id, string $title, string $symbol, array $sections, bool $subsections = true, string $help = ''): array
    {
        return ['id' => $id, 'title' => $title, 'symbol' => $symbol, 'sections' => $sections, 'subsections' => $subsections, 'help' => $help];
    }

    /** A section titled IINA's string $title ('' for none), of its lists, each a card. */
    private function section(string $title, array $lists): array
    {
        return ['title' => $title === '' ? '' : MoviePlayerSettings::tr($this->c, $title), 'lists' => $lists];
    }

    /**
     * A row labeled IINA's string $label (SettingsItem.General; of its
     * strings outside the settings' where $iina): its icon
     * (MoviePlayerSettings::glyph's), description, help link, what else a
     * search finds it by, its control ($value), what it has under it
     * ($detail, $sub), shown at a click ($expand) or while $shows says,
     * enabled while $enables says.
     * @param list<array<string, mixed>> $sub
     */
    private function item(string $label, string|array|null $symbol = null, string $desc = '', string $help = '', array $terms = [], ?Closure $value = null, ?Closure $detail = null,
        array $sub = [], bool $expand = false, ?Closure $shows = null, ?Closure $enables = null, string $id = '', bool $iina = false): array
    {
        $text = $iina ? MoviePlayerSettings::iina($this->c, $label) : MoviePlayerSettings::tr($this->c, $label);
        return ['id' => $id !== '' ? $id : $label, 'symbol' => $symbol, 'label' => $text, 'desc' => $desc, 'help' => $help, 'terms' => $terms,
            'value' => $value, 'detail' => $detail, 'sub' => $sub, 'expand' => $expand, 'shows' => $shows, 'enables' => $enables];
    }

    /**
     * A switch of setting $key (SettingsItem.Switch): its label "$key.label"
     * (or $label), its description "$key.desc"; what is under it enabled
     * while it is on, or shown ($expandOnSwitch); $extra before it.
     */
    private function sw(string $key, string|array|null $symbol = null, bool $desc = false, array $sub = [], bool $expandOnSwitch = false, ?Closure $extra = null, ?string $label = null, bool $iina = false, string $help = ''): array
    {
        $on = fn(): bool => $this->set->get($key);
        $label ??= "$key.label";
        $item = $this->item($label, $symbol, $desc ? MoviePlayerSettings::tr($this->c, "$key.desc") : '', $help, id: $key, sub: $sub, shows: $expandOnSwitch ? $on : null, enables: $on, iina: $iina);
        $text = $item['label'];
        $item['value'] = function () use ($key, $extra, $text): void {
            if ($extra !== null) { $extra(); }
            if (MoviePlayerSettings::toggle($this->c, $text, $this->set->get($key))) { $this->s->setSetting($key, !$this->set->get($key)); }
        };
        return $item;
    }

    /** A pop-up of setting $key (SettingsItem.PopupButton): its options $values, titled "$key.items.<value>"; what is under it enabled unless it is $disabledOn; $detail under it. */
    private function popup(string $key, string|array|null $symbol, array $values, array $sub = [], ?int $disabledOn = null, bool $desc = false, ?Closure $detail = null, array $terms = []): array
    {
        return $this->item("$key.label", $symbol, $desc ? MoviePlayerSettings::tr($this->c, "$key.desc") : '', id: $key, sub: $sub, terms: [...$this->optionTerms($key, $values), ...$terms], detail: $detail,
            enables: $disabledOn === null ? null : fn(): bool => $this->set->get($key) !== $disabledOn,
            value: function () use ($key, $values): void {
                $picked = MoviePlayerSettings::popUp($this->c, array_map(fn(array $o) => $o[0], $this->options($key, $values)), $this->set->get($key));
                if ($picked !== null) { $this->s->setSetting($key, $picked); }
            });
    }

    /** A field of a number of setting $key (SettingsItem.Input): its stepper, $unit after it ("$s", "$pt"); in [$min, $max], of fractions where $floats. */
    private function input(string $key, string|array|null $symbol, float $min, float $max, bool $floats, string $unit = '', ?string $label = null, bool $desc = false, string $help = ''): array
    {
        return $this->item($label ?? "$key.label", $symbol, $desc ? MoviePlayerSettings::tr($this->c, "$key.desc") : '', $help, id: $key,
            value: function () use ($key, $min, $max, $floats, $unit): void {
                $n = MoviePlayerSettings::number($this->c, $this->v, $key, (float)$this->set->get($key), $min, $max, 1, $floats, $unit === '' ? '' : MoviePlayerSettings::tr($this->c, $unit));
                if ($n !== null) { $this->s->setSetting($key, $floats ? $n : (int)$n); }
            });
    }

    /** A field of setting $inputKey and a switch of $switchKey after it (SettingsItem.SwitchWithInput), labeled $label: the field held while the switch is off. */
    private function swInput(string $label, string $switchKey, string $inputKey, string|array|null $symbol, float $min, float $max, bool $floats, string $unit = ''): array
    {
        $item = $this->item($label, $symbol, id: $inputKey);
        $text = $item['label'];
        $item['value'] = function () use ($switchKey, $inputKey, $min, $max, $floats, $unit, $text): void {
            $on = $this->set->get($switchKey);
            $box = $this->c->row(0, 6)->alignItems(Node::CENTER);
            if (!$on) { $box->disabled(); }
              $n = MoviePlayerSettings::number($this->c, $this->v, $inputKey, (float)$this->set->get($inputKey), $min, $max, 0, $floats, $unit === '' ? '' : MoviePlayerSettings::tr($this->c, $unit));
            $this->c->end();
            if ($n !== null) { $this->s->setSetting($inputKey, $floats ? $n : (int)$n); }
            if (MoviePlayerSettings::toggle($this->c, $text, $on)) { $this->s->setSetting($switchKey, !$on); }
        };
        return $item;
    }

    /** A switch of $switchKey with a pop-up of $popupKey before it (SettingsItem.SwitchWithPopupButton), labeled $label, the pop-up's options "$items.items.<value>" (of $popupKey's by default), held while the switch is off. */
    private function swPopup(string $label, string $switchKey, string $popupKey, array $values, string|array|null $symbol, string $items = ''): array
    {
        $items = $items !== '' ? $items : $popupKey;
        return $this->item($label, $symbol, id: $switchKey, terms: $this->optionTerms($items, $values), enables: fn(): bool => $this->set->get($switchKey),
            value: function () use ($switchKey, $popupKey, $values, $items, $label): void {
                $on = $this->set->get($switchKey);
                $p = $this->c->row();
                if (!$on) { $p->disabled(); }
                  $picked = MoviePlayerSettings::popUp($this->c, array_map(fn(array $o) => $o[0], $this->options($items, $values)), $this->set->get($popupKey));
                $this->c->end();
                if ($picked !== null) { $this->s->setSetting($popupKey, $picked); }
                if (MoviePlayerSettings::toggle($this->c, MoviePlayerSettings::tr($this->c, $label), $on)) { $this->s->setSetting($switchKey, !$on); }
            });
    }

    /**
     * The options of $key: [title, description] by value, their titles
     * "$key.items.<value>" (and descriptions "….desc" where $descs).
     * @param list<int> $values
     * @return array<int, array{0: string, 1: string}>
     */
    private function options(string $key, array $values, bool $descs = false): array
    {
        $out = [];
        foreach ($values as $value) {
            $out[$value] = [MoviePlayerSettings::tr($this->c, "$key.items.$value"), $descs ? MoviePlayerSettings::tr($this->c, "$key.items.$value.desc") : ''];
        }
        return $out;
    }

    /** What a search finds a row of options by: their titles and descriptions. @return list<string> */
    private function optionTerms(string $key, array $values, bool $descs = false): array
    {
        return array_values(array_filter(array_merge(...array_map(fn(array $o) => $o, array_values($this->options($key, $values, $descs)))), fn(string $s) => $s !== ''));
    }
}
