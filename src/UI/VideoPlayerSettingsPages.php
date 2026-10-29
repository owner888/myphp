<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;
use MyPHP\Dialog;
use MyPHP\Media\PlayerSettings;
use MyPHP\MessageOptions;
use MyPHP\OpenDialogOptions;
use MyPHP\Window;

/**
 * The pages of the player's settings window, as IINA's SettingsPage
 * subclasses build theirs (SettingsPageGeneral and the rest): their
 * sections, lists and rows, each row bound to a setting of
 * Media\PlayerSettings by IINA's name of it, IINA's strings its label,
 * description and options (VideoPlayerSettings::tr). A page is
 * ['id', 'title', 'symbol', 'help', 'subsections', 'sections'], a section
 * ['title', 'lists'], a row VideoPlayerSettings::row's.
 *
 * @internal UI\VideoPlayerSettings
 */
final class VideoPlayerSettingsPages
{
    private readonly PlayerSettings $set;

    public function __construct(private readonly Context $c, private readonly VideoPlayerState $s, private readonly object $v)
    {
        $this->set = $s->settings();
    }

    /** @return array<string, array<string, mixed>> the pages by id, in IINA's order */
    public function all(): array
    {
        $pages = [$this->general(), $this->video(), $this->subtitles()];
        return array_column($pages, null, 'id');
    }

    private function general(): array
    {
        return $this->page('general', VideoPlayerSettings::iina($this->c, 'preference.general'), 'gear', [
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
                    if (VideoPlayerSettings::symbolButton($c, 'folder.fill', VideoPlayerSettings::tr($c, '$Source'))) {
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

    private function video(): array
    {
        return $this->page('video', VideoPlayerSettings::iina($this->c, 'sidebar.video'), 'photo.tv', [
            $this->section('$Decoding', [[
                $this->item('hardwareDecoder.label', symbol: 'cpu', terms: $this->optionTerms('hardwareDecoder', [0, 1], true), detail: function (): void {
                    $chosen = VideoPlayerSettings::selection($this->c, $this->options('hardwareDecoder', [0, 1], true), $this->set->get('hardwareDecoder'));
                    if ($chosen !== null) { $this->s->setSetting('hardwareDecoder', $chosen); }
                }),
            ]]),
        ]);
    }

    private function subtitles(): array
    {
        $c = $this->c;
        $tx = $c->texts();
        return $this->page('subtitles', VideoPlayerSettings::iina($c, 'sidebar.sub'), 'captions.bubble', [
            $this->section('$OnlineSubtitles', [[
                $this->item('$SubtitleSource', symbol: 'server.rack', terms: [VideoPlayerSettings::tr($c, '$SubtitleSource.desc'), 'opensubtitles.com', $tx->get('videoOpenSubtitlesAccount')],
                    detail: fn() => $this->source()),
            ]]),
            $this->section('$Other', [[
                $this->item('$PreferredLanguage', symbol: 'character.book.closed', terms: [$tx->get('videoPreferredLanguageHelp')], detail: function () use ($c, $tx): void {
                    $c->column(0, 6)->padding(-4, 8, 8, VideoPlayerSettings::INDENT)->fillWidth();
                      $languages = $this->set->get('subLang');
                      $f = $c->tokenField($languages, array_values(array_diff(\MyPHP\App::preferredLanguages(), $languages)))->label(VideoPlayerSettings::tr($c, '$PreferredLanguage'));
                      if ($c->changed($f)) { $this->s->setSetting('subLang', array_values(array_unique(array_filter(array_map('trim', $languages), fn(string $l) => $l !== '')))); }
                      $c->text($tx->get('videoPreferredLanguageHelp'), 11, $c->theme->textMuted);
                    $c->end();
                }),
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
        $c->column(0, 8)->padding(0, 8, 8, VideoPlayerSettings::INDENT)->fillWidth();
          VideoPlayerSettings::popUp($c, ['opensubtitles.com'], 0, true);
          $c->text(VideoPlayerSettings::tr($c, '$SubtitleSource.desc'), 11, $t->textMuted);
          $c->row(0, 8)->align(Node::CENTER);
            $user = $this->set->get('openSubUsername');
            $c->text($tx->get('videoOpenSubtitlesAccount'), 11, $t->textMuted);
            $b = $c->row();
            if ($s->loggingIn) { $b->disabled(); }
              $clicked = VideoPlayerSettings::pushButton($c, $tx->get($user === '' ? 'videoLogin' : 'videoLogout'));
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
        return ['title' => $title === '' ? '' : VideoPlayerSettings::tr($this->c, $title), 'lists' => $lists];
    }

    /**
     * A row labeled IINA's string $label (SettingsItem.General): its icon
     * (VideoPlayerSettings::glyph's), description, help link, what else a
     * search finds it by, its control ($value), what it has under it
     * ($detail, $sub), shown at a click ($expand) or while $shows says,
     * enabled while $enables says.
     * @param list<array<string, mixed>> $sub
     */
    private function item(string $label, string|array|null $symbol = null, string $desc = '', string $help = '', array $terms = [], ?Closure $value = null, ?Closure $detail = null,
        array $sub = [], bool $expand = false, ?Closure $shows = null, ?Closure $enables = null, string $id = ''): array
    {
        return ['id' => $id !== '' ? $id : $label, 'symbol' => $symbol, 'label' => VideoPlayerSettings::tr($this->c, $label), 'desc' => $desc, 'help' => $help, 'terms' => $terms,
            'value' => $value, 'detail' => $detail, 'sub' => $sub, 'expand' => $expand, 'shows' => $shows, 'enables' => $enables];
    }

    /** A switch of setting $key (SettingsItem.Switch): its label "$key.label", its description "$key.desc"; what is under it enabled while it is on, or shown ($expandOnSwitch); $extra before it. */
    private function sw(string $key, string|array|null $symbol = null, bool $desc = false, array $sub = [], bool $expandOnSwitch = false, ?Closure $extra = null): array
    {
        $on = fn(): bool => $this->set->get($key);
        return $this->item("$key.label", $symbol, $desc ? VideoPlayerSettings::tr($this->c, "$key.desc") : '', id: $key, sub: $sub, shows: $expandOnSwitch ? $on : null, enables: $on,
            value: function () use ($key, $extra): void {
                if ($extra !== null) { $extra(); }
                if (VideoPlayerSettings::toggle($this->c, VideoPlayerSettings::tr($this->c, "$key.label"), $this->set->get($key))) { $this->s->setSetting($key, !$this->set->get($key)); }
            });
    }

    /** A pop-up of setting $key (SettingsItem.PopupButton): its options $values, titled "$key.items.<value>"; what is under it enabled unless it is $disabledOn. */
    private function popup(string $key, string|array|null $symbol, array $values, array $sub = [], ?int $disabledOn = null, bool $desc = false): array
    {
        return $this->item("$key.label", $symbol, $desc ? VideoPlayerSettings::tr($this->c, "$key.desc") : '', id: $key, sub: $sub, terms: $this->optionTerms($key, $values),
            enables: $disabledOn === null ? null : fn(): bool => $this->set->get($key) !== $disabledOn,
            value: function () use ($key, $values): void {
                $picked = VideoPlayerSettings::popUp($this->c, array_map(fn(array $o) => $o[0], $this->options($key, $values)), $this->set->get($key));
                if ($picked !== null) { $this->s->setSetting($key, $picked); }
            });
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
                  $picked = VideoPlayerSettings::popUp($this->c, array_map(fn(array $o) => $o[0], $this->options($items, $values)), $this->set->get($popupKey));
                $this->c->end();
                if ($picked !== null) { $this->s->setSetting($popupKey, $picked); }
                if (VideoPlayerSettings::toggle($this->c, VideoPlayerSettings::tr($this->c, $label), $on)) { $this->s->setSetting($switchKey, !$on); }
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
            $out[$value] = [VideoPlayerSettings::tr($this->c, "$key.items.$value"), $descs ? VideoPlayerSettings::tr($this->c, "$key.items.$value.desc") : ''];
        }
        return $out;
    }

    /** What a search finds a row of options by: their titles and descriptions. @return list<string> */
    private function optionTerms(string $key, array $values, bool $descs = false): array
    {
        return array_values(array_filter(array_merge(...array_map(fn(array $o) => $o, array_values($this->options($key, $values, $descs)))), fn(string $s) => $s !== ''));
    }
}
