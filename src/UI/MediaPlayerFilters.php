<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;
use MyPHP\Dialog;
use MyPHP\L10n;
use MyPHP\Media\Filter;
use MyPHP\Media\FilterPresets;
use MyPHP\MessageOptions;
use MyPHP\Window;
use MyPHP\WindowOptions;

/**
 * IINA's Video Filters and Audio Filters windows (FilterWindowController),
 * Video > Video Filters… (Shift+Cmd+F) and Audio > Audio Filters…
 * (Shift+Cmd+G), of the player last in front, laid out as IINA's xib: 640
 * by 382, the filters on (#, the filter's string, which a double click
 * edits, and Save) over a bar of + and −; under them the saved filters, a
 * row each of its checkbox (on while the filter is), name, string, key,
 * and its edit and delete buttons. Its sheets, as IINA's: New Filter
 * (IINA's presets, each its parameters, FilterPresets), Save Filter (a
 * name and a key recorded) and Edit Filter. A filter MyPHP cannot do is
 * IINA's alert, "Error occurred when setting filters".
 *
 * @internal examples/video (Video > Video Filters…, Audio > Audio Filters…)
 */
final class MediaPlayerFilters
{
    public const WIDTH = 640, HEIGHT = 382;
    /** @var array<string, Window> the windows open, 'video' and 'audio' */
    private static array $windows = [];
    /** @var array<string, object> each window's state: what is chosen and typed, its sheet's */
    private static array $views = [];

    /**
     * Opens the video's filters ($video) or the audio's, or brings them
     * forward (IINA's showVideoFilterWindow), of the player $player gives
     * (IINA's PlayerCore.lastActive).
     * @param Closure(): ?MediaPlayerState $player
     */
    public static function open(bool $video, Closure $player): void
    {
        $k = $video ? 'video' : 'audio';
        $w = self::$windows[$k] ?? null;
        if ($w !== null && !$w->isDestroyed()) {
            $w->show();
            $w->focus();
            return;
        }
        $v = self::$views[$k] ??= self::view($video);
        self::$windows[$k] = new Window(new WindowOptions(title: self::t($video ? 'iinaFilterVideoFilters' : 'iinaFilterAudioFilters'), width: self::WIDTH, height: self::HEIGHT,
            minWidth: 480, minHeight: 300, stateKey: "filters-$k",
            content: Content::view(fn(Context $c) => self::build($c, $player(), $v))));
    }

    /** The filters' windows open, and their sheets: they leave the menus the player's, as IINA's. @return list<Window> */
    public static function windows(): array
    {
        $all = [...array_values(self::$windows), ...array_filter(array_map(fn(object $v) => $v->sheetWindow, self::$views))];
        return array_values(array_filter($all, fn(Window $w) => !$w->isDestroyed()));
    }

    /** A window's state, of the video's filters or the audio's. @internal tests */
    public static function view(bool $video): object
    {
        return (object)['video' => $video, 'chosen' => -1, 'editing' => -1, 'edit' => new TextState(''), 'fields' => [],
            'sheet' => null, 'sheetWindow' => null, 'preset' => array_key_first(FilterPresets::all($video)), 'values' => [], 'filter' => null,
            'name' => new TextState(''), 'string' => new TextState(''), 'key' => '', 'mods' => '', 'saved' => -1, 'alert' => null];
    }

    /** The window's view of player $s's filters. @internal tests */
    public static function build(Context $c, ?MediaPlayerState $s, object $v): Node
    {
        $t = $c->theme;
        $line = $t->dark ? 0x3a3a3a : 0xd8d8d8;
        $root = $c->column()->fill()->background($t->background);
          // The filters on.
          $c->column()->fillWidth()->grow(1)->minHeight(120);
            $c->row()->height(24)->fillWidth()->alignItems(Node::CENTER)->borderColor($line)->borderWidth(0, 0, 1, 0);
              $c->text('#', 11, $t->textMuted)->width(36)->textAlign(Node::END);
              $c->text(self::t('iinaFilterWindowFilterString'), 11, $t->textMuted)->marginStart(12)->grow(1);
            $c->end();
            $c->scroll()->grow(1)->fillWidth();
              foreach ($s?->filters($v->video) ?? [] as $i => $f) {
                  self::filterRow($c, $s, $v, $i, $f);
              }
            $c->end();
            $c->row(0, 0)->height(23)->fillWidth()->alignItems(Node::CENTER)->borderColor($line)->borderWidth(1, 0, 0, 0);
              $add = self::iconButton($c, 'plus', '+', $s !== null);
              $remove = self::iconButton($c, 'minus', '−', $s !== null && $v->chosen >= 0);
            $c->end();
          $c->end();
          // The saved filters.
          $c->column()->fillWidth()->height(176)->borderColor($line)->borderWidth(1, 0, 0, 0);
            $c->text(self::t('iinaFilterWindowSavedFilters'), 13, $t->text)->margin(4, 0, 4, 8);
            $c->scroll()->grow(1)->fillWidth();
              foreach ($s?->savedFilters($v->video) ?? [] as $i => $saved) {
                  self::savedRow($c, $s, $v, $i, $saved);
              }
            $c->end();
          $c->end();
        $c->end();
        if ($s !== null && $c->clicked($add)) { self::openSheet($c, $s, $v, 'new'); }
        if ($s !== null && $c->clicked($remove) && $v->chosen >= 0) {
            if ($s->removeFilter($v->video, $v->chosen)) { $v->chosen = -1; }
        }
        if ($v->alert !== null && \MyPHP\App::running()) { // IINA's alert ($alert stays for a test's eyes, which has no app)
            [$text, $v->alert] = [$v->alert, null];
            Dialog::message(new MessageOptions(parent: Window::focused(), type: 'warning', message: $text));
        }
        return $root;
    }

    /** A row of the filters on: its place, its string (a double click edits it, Return sets it), its Save. */
    private static function filterRow(Context $c, MediaPlayerState $s, object $v, int $i, Filter $f): void
    {
        $t = $c->theme;
        $chosen = $v->chosen === $i;
        $row = $c->key("filter $i")->row(0, 0)->height(26)->fillWidth()->alignItems(Node::CENTER)->paddingEnd(8)->role(Role::RADIO)->label($f->string())->checked($chosen); // a row of IINA's table, chosen one at a time
        if ($chosen) { $row->background($t->accent, 0.25); }
          $c->text((string)$i, 13, $t->text)->width(36)->textAlign(Node::END);
          if ($v->editing === $i) {
              $c->row()->grow(1)->marginStart(12)->marginEnd(8);
                $field = $c->key('edit field')->input($v->edit, '', 0, 13)->grow(1);
              $c->end();
              if ($c->submitted($field)) {
                  $new = Filter::parse(trim($v->edit->text));
                  $all = $s->filters($v->video);
                  $all[$i] = $new ?? $f;
                  if ($new === null || !$s->setFilters($v->video, $all)) { $v->alert = self::t('iinaAlertFilterIncorrect'); }
                  $v->editing = -1;
              } elseif ($c->shortcut(Key::ESCAPE)) {
                  $v->editing = -1;
              }
          } else {
              $text = $c->text($f->string(), 13, $t->text)->marginStart(12)->grow(1)->noWrap()->ellipsis('…');
          }
          $save = $c->row()->height(20)->paddingX(8)->radius(5)->center()->role(Role::BUTTON)->label(self::t('iinaFilterWindowSave'))->background($t->dark ? 0xffffff : 0x000000, 0.08);
            $c->text(self::t('iinaFilterWindowSave'), 11, $t->text);
          $c->end();
        $c->end();
        if ($c->clicked($save)) {
            $v->filter = $f;
            self::openSheet($c, $s, $v, 'save');
        } elseif ($c->doubleClicked($row) && $v->editing !== $i) {
            [$v->editing, $v->chosen] = [$i, $i];
            $v->edit = new TextState($f->string());
        } elseif ($c->clicked($row)) {
            $v->chosen = $i;
        }
    }

    /** A row of the saved filters: its checkbox, name over its string, its key, edit and delete. @param array{name: string, filterString: string, shortcutKey: string, shortcutKeyModifiers: string} $saved */
    private static function savedRow(Context $c, MediaPlayerState $s, object $v, int $i, array $saved): void
    {
        $t = $c->theme;
        $on = $s->filterOn($v->video, $saved['filterString']);
        $was = $on;
        $c->key("saved $i")->row(0, 0)->height(44)->fillWidth()->alignItems(Node::CENTER)->paddingX(6);
          $c->checkbox('', $on)->label($saved['name']);
          $c->column(0, 2)->grow(1)->marginStart(6)->minWidth(0);
            $c->text($saved['name'], 13, $t->text)->noWrap()->ellipsis('…');
            $c->text($saved['filterString'], 13, $t->textMuted)->noWrap()->ellipsis('…');
          $c->end();
          $c->text(self::readableKey($saved['shortcutKey'], $saved['shortcutKeyModifiers']), 13, $t->textMuted, Font::MONO)->marginEnd(8);
          $edit = self::iconButton($c, 'gearshape', self::t('iinaFilterWindowEditFilter'), true);
          $delete = self::iconButton($c, 'xmark.circle.fill', 'Delete', true);
        $c->end();
        if ($on !== $was && !$s->toggleFilter($v->video, $saved['filterString'], $saved['name'])) {
            $v->alert = self::t('iinaAlertFilterIncorrect');
        }
        if ($c->clicked($edit)) {
            $v->saved = $i;
            $v->name = new TextState($saved['name']);
            $v->string = new TextState($saved['filterString']);
            [$v->key, $v->mods] = [$saved['shortcutKey'], $saved['shortcutKeyModifiers']];
            self::openSheet($c, $s, $v, 'edit');
        }
        if ($c->clicked($delete)) {
            $all = $s->savedFilters($v->video);
            array_splice($all, $i, 1);
            $s->setSavedFilters($v->video, $all);
        }
    }

    /** A borderless button of SF Symbol $name, as IINA's NSAddTemplate and its kind: whether it was clicked. */
    private static function iconButton(Context $c, string $name, string $label, bool $enabled): Node
    {
        $b = $c->row()->size(22, 22)->center()->role(Role::BUTTON)->label($label);
        if (!$enabled) { $b->disabled(); }
          MediaPlayerSettings::sf($c, $name, 12, $c->theme->text)->opacity($enabled ? 0.85 : 0.3);
        $c->end();
        return $b;
    }

    /**
     * Opens sheet $kind ('new', 'save', 'edit') over the window, as IINA's
     * beginSheet: a window of its own, its parent's sheet (a test's view
     * builds it itself, sheet()).
     */
    private static function openSheet(Context $c, MediaPlayerState $s, object $v, string $kind): void
    {
        $v->sheet = $kind;
        if ($kind === 'new') { $v->values = []; }
        if ($kind === 'save') { [$v->name, $v->key, $v->mods] = [new TextState(''), '', '']; }
        $parent = self::$windows[$v->video ? 'video' : 'audio'] ?? null;
        if ($parent === null || $parent->isDestroyed()) {
            return; // no window: the sheet built by sheet() alone
        }
        [$w, $h] = match ($kind) { 'new' => [450, 300], 'save' => [403, 270], default => [393, 300] }; // IINA's widths; taller, for MyPHP's fields, and New Filter's parameters all in sight
        $v->sheetWindow = new Window(new WindowOptions(width: $w, height: $h, parent: $parent, modal: true,
            content: Content::view(fn(Context $c) => self::sheet($c, $s, $v))));
    }

    /** The sheet open, closed. */
    private static function closeSheet(object $v): void
    {
        $v->sheet = null;
        $w = $v->sheetWindow;
        $v->sheetWindow = null;
        $w?->close();
    }

    /** The view of the sheet open ($v->sheet), IINA's New Filter, Save Filter or Edit Filter. @internal tests */
    public static function sheet(Context $c, MediaPlayerState $s, object $v): Node
    {
        return match ($v->sheet) {
            'new' => self::newSheet($c, $s, $v),
            'save' => self::saveSheet($c, $s, $v),
            default => self::editSheet($c, $s, $v),
        };
    }

    /** New Filter: IINA's presets on the left, the parameters of the one chosen on the right (a field, a slider, a pop-up each, under its name), Cancel and Add. */
    private static function newSheet(Context $c, MediaPlayerState $s, object $v): Node
    {
        $t = $c->theme;
        $presets = FilterPresets::all($v->video);
        $preset = $v->preset; // the one chosen as the frame began: a click on another takes it at its end
        $params = $presets[$preset];
        $pick = null;
        $root = $c->column(0, 8)->fill()->padding(12)->background($t->background);
          $c->row(0, 8)->grow(1)->fillWidth();
            $c->scroll()->width(160)->fillHeight()->borderWidth(1)->borderColor($t->dark ? 0x3a3a3a : 0xc8c8c8);
              foreach (array_keys($presets) as $name) {
                  $r = $c->key("preset $name")->row()->height(19)->fillWidth()->paddingX(6)->alignItems(Node::CENTER)->role(Role::RADIO)->label(self::presetName($name))->checked($name === $preset); // a row of IINA's table, chosen one at a time
                  if ($name === $preset) { $r->background($t->accent); }
                    $c->text(self::presetName($name), 13, $name === $preset ? 0xffffff : $t->text)->noWrap()->ellipsis('…');
                  $c->end();
                  if ($c->clicked($r) && $name !== $preset) { $pick = $name; }
              }
            $c->end();
            $c->scroll()->grow(1)->fillHeight();
              $c->column(0, 4)->fillWidth()->padding(4);
                foreach ($params as $p => $def) {
                    $c->text(self::presetName("$preset.$p"), 13, $t->text);
                    $value = $v->values[$p] ?? FilterPresets::default($def);
                    if ($def[0] === 'text') {
                        $new = MediaPlayerSettings::textField($c, $v, "param $preset.$p", (string)$value, false);
                        if ($new !== null) { $v->values[$p] = $new; }
                    } elseif ($def[0] === 'choose') {
                        $picked = MediaPlayerSettings::popUp($c, array_combine($def[1], $def[1]), (string)$value, true);
                        if ($picked !== null) { $v->values[$p] = $picked; }
                    } else {
                        $x = (float)$value;
                        $slider = $c->slider($x, (float)$def[1], (float)$def[2], 240)->label(self::presetName("$preset.$p"));
                        if ($c->changed($slider)) { $v->values[$p] = $def[0] === 'int' ? (int)(round(($x - $def[1]) / $def[3]) * $def[3] + $def[1]) : $x; }
                    }
                }
              $c->end();
            $c->end();
          $c->end();
          $custom = str_starts_with($preset, 'custom_');
          [$cancel, $add] = self::buttons($c, self::t('iinaFilterWindowCancel'), self::t('iinaFilterWindowAdd'), !$custom || trim((string)($v->values['name'] ?? '')) !== '');
        $c->end();
        if ($pick !== null) { [$v->preset, $v->values, $v->fields] = [$pick, [], []]; }
        if ($cancel) { self::closeSheet($v); }
        if ($add && $pick === null) {
            $values = [];
            foreach ($params as $p => $def) { $values[$p] = $v->values[$p] ?? FilterPresets::default($def); }
            self::closeSheet($v);
            $f = FilterPresets::filter($preset, $values);
            if ($f === null || !$s->addFilter($v->video, $f)) {
                $v->alert = self::t('iinaAlertFilterIncorrect');
            } else {
                $s->say(self::t('iinaOsdFilterAdded', ['name' => self::presetName($preset)]));
            }
        }
        return $root;
    }

    /** Save Filter: what saving is for, a name, a key recorded, Cancel and Add. */
    private static function saveSheet(Context $c, MediaPlayerState $s, object $v): Node
    {
        $t = $c->theme;
        $root = $c->column(0, 6)->fill()->padding(12)->background($t->background);
          $c->text(self::t('iinaFilterWindowSaveFilter'), 13, $t->text, Font::BOLD);
          $c->text(self::t('iinaFilterWindowSaveFilterMessage'), 13, $t->text);
          $c->text(self::t('iinaFilterWindowName'), 11, $t->text)->margin(4, 0, 0, 0);
          $c->key('name')->input($v->name, '', 0, 13)->fillWidth();
          $c->text(self::t('iinaFilterWindowShortcutKey'), 11, $t->text)->margin(4, 0, 0, 0);
          self::keyRecorder($c, $v);
          $c->column()->grow(1);
          $c->end();
          [$cancel, $add] = self::buttons($c, self::t('iinaFilterWindowCancel'), self::t('iinaFilterWindowAdd'), true);
        $c->end();
        if ($cancel) { self::closeSheet($v); }
        if ($add && $v->filter !== null) {
            $s->setSavedFilters($v->video, [...$s->savedFilters($v->video), ['name' => $v->name->text, 'filterString' => $v->filter->string(), 'shortcutKey' => $v->key, 'shortcutKeyModifiers' => $v->mods]]);
            self::closeSheet($v);
        }
        return $root;
    }

    /** Edit Filter: the saved filter's name, string and key, Cancel and Save. */
    private static function editSheet(Context $c, MediaPlayerState $s, object $v): Node
    {
        $t = $c->theme;
        $root = $c->column(0, 6)->fill()->padding(12)->background($t->background);
          $c->text(self::t('iinaFilterWindowEditFilter'), 13, $t->text, Font::BOLD);
          $c->text(self::t('iinaFilterWindowName'), 11, $t->text)->margin(4, 0, 0, 0);
          $c->key('name')->input($v->name, '', 0, 13)->fillWidth();
          $c->text(self::t('iinaFilterWindowFilterStringLabel'), 11, $t->text)->margin(4, 0, 0, 0);
          $c->key('string')->input($v->string, '', 0, 13)->fillWidth();
          $c->text(self::t('iinaFilterWindowShortcutKey'), 11, $t->text)->margin(4, 0, 0, 0);
          self::keyRecorder($c, $v);
          $c->column()->grow(1);
          $c->end();
          [$cancel, $save] = self::buttons($c, self::t('iinaFilterWindowCancel'), self::t('iinaFilterWindowSave'), true);
        $c->end();
        if ($cancel) { self::closeSheet($v); }
        if ($save) {
            $all = $s->savedFilters($v->video);
            if (isset($all[$v->saved])) {
                $all[$v->saved] = ['name' => $v->name->text, 'filterString' => $v->string->text, 'shortcutKey' => $v->key, 'shortcutKeyModifiers' => $v->mods];
                $s->setSavedFilters($v->video, $all);
            }
            self::closeSheet($v);
        }
        return $root;
    }

    /**
     * IINA's KeyRecordView: a box saying "Press any button to record", or
     * the key recorded; focused (clicked), the next key pressed is recorded
     * (its character without modifiers, and them: IINA's c o s m).
     */
    private static function keyRecorder(Context $c, object $v): void
    {
        $t = $c->theme;
        $box = $c->key('key recorder')->column()->height(48)->shrink(0)->fillWidth()->center()->radius(6)->borderWidth(1)->borderColor($t->dark ? 0x4a4a4a : 0xc8c8c8)
            ->background($t->dark ? 0xffffff : 0x000000, 0.04)->focusable()->role(Role::BUTTON)->label(self::t('iinaFilterWindowPressAnyButton'));
          $c->text($v->key === '' ? self::t('iinaFilterWindowPressAnyButton') : self::readableKey($v->key, $v->mods), 13, $v->key === '' ? $t->textMuted : $t->text);
        $c->end();
        if ($c->focused($box)) {
            $c->takeKeys(function (string $chars, int $code, int $mods, string $plain) use ($v): bool {
                if ($plain === '') { return false; }
                $v->key = strtolower($plain);
                $v->mods = implode('', array_keys(array_filter(['c' => $mods & Key::CONTROL, 'o' => $mods & Key::OPTION, 's' => $mods & Key::SHIFT, 'm' => $mods & Key::COMMAND])));
                return true;
            });
        }
    }

    /** Cancel and the default button ($ok) at the bottom right, as IINA's sheets; whether each was clicked. @return array{0: bool, 1: bool} */
    private static function buttons(Context $c, string $cancel, string $ok, bool $enabled): array
    {
        $t = $c->theme;
        $c->row(0, 8)->fillWidth()->justify(Node::END);
          $no = MediaPlayerSettings::pushButton($c, $cancel, false) || $c->shortcut(Key::ESCAPE);
          $b = $c->row()->height(24)->paddingX(14)->radius(8)->center()->role(Role::BUTTON)->label($ok)->background($t->accent);
          if (!$enabled) { $b->disabled()->opacity(0.5); }
            $c->text($ok, 13, 0xffffff);
          $c->end();
        $c->end();
        return [$no, $enabled && ($c->clicked($b) || $c->shortcut(Key::RETURN))];
    }

    /**
     * Key $key of modifiers $mods (IINA's c o s m) as IINA shows it
     * (KeyCodeHelper.readableString): ⌃⌥⇧⌘ then the key, upper case.
     */
    public static function readableKey(string $key, string $mods): string
    {
        if ($key === '') {
            return '';
        }
        $out = '';
        foreach (['c' => '⌃', 'o' => '⌥', 's' => '⇧', 'm' => '⌘'] as $m => $glyph) {
            if (str_contains($mods, $m)) { $out .= $glyph; }
        }
        return $out . strtoupper($key);
    }

    /**
     * The menu's accelerator of key $key of modifiers $mods (IINA's c o s
     * m), as MenuItem takes one ("Cmd+Shift+x"); '' for none.
     */
    public static function accelerator(string $key, string $mods): string
    {
        if ($key === '') {
            return '';
        }
        $parts = [];
        foreach (['c' => 'Ctrl', 'o' => 'Alt', 's' => 'Shift', 'm' => 'Cmd'] as $m => $name) {
            if (str_contains($mods, $m)) { $parts[] = $name; }
        }
        return implode('+', [...$parts, $key]);
    }

    /** IINA's name of preset $key ("crop", "crop.w"): its FilterPresets.strings' (iinaFilterPresetCropW). */
    private static function presetName(string $key): string
    {
        return self::t('iinaFilterPreset' . implode('', array_map('ucfirst', preg_split('/[._]/', $key) ?: [])));
    }

    private static function t(string $key, array $args = []): string
    {
        return L10n::in(\MyPHP\App::locale())->get($key, $args);
    }
}
