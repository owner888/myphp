<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;
use MyPHP\DateFormatter;
use MyPHP\DateOptions;
use MyPHP\Dialog;
use MyPHP\Media\PlaybackHistory;
use MyPHP\Media\PlaybackPositions;
use MyPHP\MessageOptions;
use MyPHP\Shell;
use MyPHP\Style;
use MyPHP\Window;

/**
 * The player's playback history window, as IINA's (HistoryWindowController):
 * its toolbar's Group By (the day played, or the folder or website), Expand
 * All and Collapse All, Clear Playback History and a search (in the films'
 * names or their whole paths); the films played in groups that open and
 * close, the latest first, each its icon, its title over its file's name,
 * where it was left (a bar of how far) and when it was played; chosen
 * with a click (Command and Shift more), played with a double click or
 * Return, deleted from the history with Delete, the menu of a right
 * click to play (in a new window), show in the Finder, delete or move to
 * the Trash. Built by Context::videoPlayerHistory, in a window of a hidden
 * title bar, 800 by 600.
 *
 * @internal UI\Context
 */
final class VideoPlayerHistory
{
    /** The toolbar's height, a row's (a film's, a group's), the columns' least widths (IINA's). */
    private const BAR = 52, ROW = 38, GROUP = 26, PROGRESS = 110, TIME = 60;

    /** @param Closure(list<string>, bool): void $open plays films, in a new window if asked */
    public static function build(Context $c, Closure $open): Node
    {
        $t = $c->theme;
        $root = $c->column(0, 0, 1)->background(VideoPlayerSettings::colors($t)['page']);
        $make = fn() => (object)['byFolder' => false, 'closed' => [], 'chosen' => [], 'search' => new TextState(), 'inPath' => true, 'anchor' => null];
        $v = $c->runtime?->local($root->id, $make) ?? $make();
        $history = PlaybackHistory::default();
        $positions = PlaybackPositions::default();
        $q = trim($v->search->text);
        $entries = array_values(array_filter($history->all(), fn(array $e) => $q === '' || mb_stripos($v->inPath ? $e['path'] : $e['name'], $q) !== false));
        $groups = self::groups($c, $entries, $v->byFolder);
        $order = array_merge(...array_values(array_map(fn(array $g) => array_column($g, 'path'), $groups)) ?: [[]]);
          self::toolbar($c, $v, $groups, $history);
          $c->row()->height(24)->fillWidth()->paddingX(16)->align(Node::CENTER)->borderWidth(0, 0, 1, 0)->borderColor(VideoPlayerSettings::colors($t)['line']); // the columns' headers
            $c->text(VideoPlayerSettings::iina($c, 'history_window.col.filename'), 11, $t->textMuted)->grow(1);
            $c->text(VideoPlayerSettings::iina($c, 'history_window.col.progress'), 11, $t->textMuted)->width(self::PROGRESS);
            $c->text(VideoPlayerSettings::iina($c, 'history_window.col.played_at'), 11, $t->textMuted)->width($v->byFolder ? 145 : self::TIME);
          $c->end();
          $list = $c->scroll(0, 0, 1)->padding(4, 8);
            foreach ($groups as $name => $group) {
                $closed = $v->closed[$name] ?? false;
                $g = $c->key("group:$name")->row(0, 4)->height(self::GROUP)->paddingX(4)->align(Node::CENTER)->role(Role::DISCLOSURE)->label($name)->checked(!$closed);
                  VideoPlayerSettings::sf($c, $closed ? 'chevron.right' : 'chevron.down', 10, $t->textMuted, 0.4);
                  $c->text($name, 13, $t->text, Font::BOLD)->singleLine()->ellipsis('…');
                $c->end();
                if ($c->clicked($g)) { $v->closed[$name] = !$closed; }
                if ($closed) { continue; }
                foreach ($group as $e) { self::row($c, $v, $e, $positions, $order, $open); }
            }
          $c->end();
        $c->end();
        $list->role(Role::TREE)->label(VideoPlayerSettings::iina($c, 'history_window.title'));
        self::keys($c, $v, $order, $history, $open);
        return $root;
    }

    /** The films in groups by the day played (Medium date) or their folder (a URL's host), as IINA's: the days the latest first, the folders by name. @return array<string, list<array<string, mixed>>> */
    private static function groups(Context $c, array $entries, bool $byFolder): array
    {
        $day = new DateFormatter(\MyPHP\App::locale(), new DateOptions(date: Style::Medium));
        $out = [];
        foreach ($entries as $e) {
            $key = $byFolder ? (preg_match('~^https?://~i', $e['path']) === 1 ? (string)(parse_url($e['path'], PHP_URL_HOST) ?: '-') : dirname($e['path'])) : $day->format((new \DateTimeImmutable("@{$e['added']}"))->setTimezone(new \DateTimeZone(date_default_timezone_get())));
            $out[$key][] = $e;
        }
        if ($byFolder) { uksort($out, 'strnatcasecmp'); }
        return $out;
    }

    /** The toolbar: the title after the window's controls; Group By, Expand All/Collapse All, Clear Playback History, the search, as IINA's toolbar has them. */
    private static function toolbar(Context $c, object $v, array $groups, PlaybackHistory $history): void
    {
        $t = $c->theme;
        $bar = $c->titleBar();
        $c->row(0, 8)->height(self::BAR)->fillWidth()->paddingStart(max(80.0, $bar['left'] + 12))->paddingEnd(12)->align(Node::CENTER)->dragWindow();
          $c->text(VideoPlayerSettings::iina($c, 'history_window.title'), 15, $t->text, Font::BOLD)->singleLine()->grow(1);
          // Group By: by date, by folder and website
          $c->row(0, 0)->round(14)->systemMaterial('glass')->role(Role::RADIO_GROUP)->label(VideoPlayerSettings::iina($c, 'history_window.toolbar.group_by'));
            foreach ([false => ['calendar.badge.clock', 'history_window.toolbar.group_by.date_tooltip'], true => ['folder', 'history_window.toolbar.group_by.location_tooltip']] as $folder => [$symbol, $tip]) {
                $on = $v->byFolder === (bool)$folder;
                $b = $c->column()->size(36, 28)->round(14)->center()->role(Role::RADIO)->checked($on)->label(VideoPlayerSettings::iina($c, $tip))->tooltip(VideoPlayerSettings::iina($c, $tip));
                if ($on) { $b->background($t->dark ? 0xffffff : 0x000000, 0.12); }
                  VideoPlayerSettings::sf($c, $symbol, 14, $t->text);
                $c->end();
                if ($c->clicked($b)) { $v->byFolder = (bool)$folder; }
            }
          $c->end();
          // Expand All, Collapse All
          $names = array_keys($groups);
          $anyClosed = array_filter($names, fn($n) => $v->closed[$n] ?? false) !== [];
          $anyOpen = array_filter($names, fn($n) => !($v->closed[$n] ?? false)) !== [];
          $c->row(0, 0)->round(14)->systemMaterial('glass');
            foreach ([['chevron.down.circle', 'history_window.toolbar.expand_all', $anyClosed, false], ['chevron.up.circle', 'history_window.toolbar.collapse_all', $anyOpen, true]] as [$symbol, $tip, $enabled, $close]) {
                $b = $c->column()->size(36, 28)->round(14)->center()->role(Role::BUTTON)->label(VideoPlayerSettings::iina($c, $tip))->tooltip(VideoPlayerSettings::iina($c, $tip));
                if (!$enabled) { $b->disabled(); }
                  VideoPlayerSettings::sf($c, $symbol, 14, $t->text);
                $c->end();
                if ($c->clicked($b)) { foreach ($names as $n) { $v->closed[$n] = $close; } }
            }
          $c->end();
          $c->box(8, 0, 0)->color = null;
          $clear = $c->column()->size(36, 28)->round(14)->center()->systemMaterial('glass')->role(Role::BUTTON)->label(VideoPlayerSettings::iina($c, 'history_window.toolbar.clear'))->tooltip(VideoPlayerSettings::iina($c, 'history_window.toolbar.clear'));
            VideoPlayerSettings::sf($c, 'trash', 14, $t->text);
          $c->end();
          if ($c->clicked($clear) && self::ask($c, 'delete_all_history')) { $history->clear(); $v->chosen = []; }
          $c->box(8, 0, 0)->color = null;
          $c->row(0, 4)->size(160, 28)->round(14)->paddingStart(10)->align(Node::CENTER)->systemMaterial('glass');
            $glass = $c->column()->role(Role::MENU_BUTTON)->label(VideoPlayerSettings::iina($c, 'history_window.search.search_in'));
              VideoPlayerSettings::sf($c, 'magnifyingglass', 12, $t->textMuted);
            $c->end();
            $glass->menu(function (Menu $m) use ($c, $v): void { // IINA's search menu: in the names or the paths
                $m->header(VideoPlayerSettings::iina($c, 'history_window.search.search_in'));
                if ($m->item(VideoPlayerSettings::iina($c, 'history_window.search.filename'))->checked(!$v->inPath)->chosen()) { $v->inPath = false; }
                if ($m->item(VideoPlayerSettings::iina($c, 'history_window.search.full_path'))->checked($v->inPath)->chosen()) { $v->inPath = true; }
            });
            $f = $c->input($v->search, $c->texts()->get('search'), 0, 13)->height(28);
            $f->bordered = false;
          $c->end();
        $c->end();
    }

    /** A film's row: its icon (a URL's the network's), its title over its file's name (its name alone where it has no title), dimmed where the file is gone; where it was left; when it was played. */
    private static function row(Context $c, object $v, array $e, PlaybackPositions $positions, array $order, Closure $open): void
    {
        $t = $c->theme;
        $url = preg_match('~^https?://~i', $e['path']) === 1;
        $there = $url || is_file($e['path']);
        $chosen = in_array($e['path'], $v->chosen, true);
        $focused = $c->runtime?->windowFocus ?? true;
        $fg = $chosen && $focused ? $t->accentText : ($there ? $t->text : $t->textMuted);
        $r = $c->key('film:' . $e['path'])->row(0, 8)->height(self::ROW)->paddingX(12)->round(6)->align(Node::CENTER)->role(Role::TREE_ITEM)->level(2)->checked($chosen)->label($e['title'] !== '' ? $e['title'] : $e['name']);
        if ($chosen) { $focused ? $r->background($t->accent) : $r->background($t->dark ? 0xffffff : 0x000000, 0.1); }
          $c->column()->size(18, 18)->center();
            if ($url) { VideoPlayerSettings::sf($c, 'network', 14, $fg); }
            elseif ($there && \MyPHP\App::running()) { $c->image(self::icon($c, $e['path']), 18, 18); } // the Finder's icon: NSWorkspace's, of the running app (none built headless)
          $c->end();
          $c->column(0, 1)->grow(1)->min(200, 0);
            if ($e['title'] !== '' && $e['title'] !== $e['name']) {
                $c->text($e['title'], 13, $fg)->singleLine()->ellipsis('…');
                $c->text($url ? $e['path'] : $e['name'], 11, $chosen && $focused ? $t->accentText : $t->textMuted)->singleLine()->ellipsis('…');
            } else {
                $c->text($url ? $e['path'] : $e['name'], 13, $fg)->singleLine()->ellipsis('…');
            }
          $c->end();
          $c->row(0, 6)->width(self::PROGRESS)->align(Node::CENTER);
            $at = $positions->get($e['path']);
            if ($at !== null && $e['duration'] > 0 && $there) {
                $k = max(0.0, min(1.0, $at / $e['duration']));
                $c->row()->height(5)->grow(1)->min(30, 0)->draw(static function (DisplayList $l, Node $n, Theme $t) use ($k): void { // IINA's continuous capacity indicator, mini
                    $l->rect($n->x, $n->y, $n->w, $n->h, $t->dark ? 0xffffff : 0x000000, 0.15, 2.5);
                    $l->rect($n->x, $n->y, $n->w * $k, $n->h, $t->accent, 1.0, 2.5);
                });
                $c->end();
                $c->text(VideoPlayerState::clock($at), 12, $fg)->fontFeatures('tnum');
            }
          $c->end();
          $c->text(self::when($e['added'], $v->byFolder), 13, $chosen && $focused ? $t->accentText : $t->textMuted)->width($v->byFolder ? 145 : self::TIME)->fontFeatures('tnum')->singleLine();
        $c->end();
        if ($c->doubleClicked($r)) { $open([$e['path']], false); return; }
        if ($c->clicked($r)) {
            $mods = $c->clickModifiers($r);
            if (($mods & Key::COMMAND) !== 0) {
                $v->chosen = $chosen ? array_values(array_diff($v->chosen, [$e['path']])) : [...$v->chosen, $e['path']];
            } elseif (($mods & Key::SHIFT) !== 0 && $v->anchor !== null && ($a = array_search($v->anchor, $order, true)) !== false) {
                $b = (int)array_search($e['path'], $order, true);
                $v->chosen = array_slice($order, min($a, $b), abs($a - $b) + 1);
            } else {
                $v->chosen = [$e['path']];
            }
            $v->anchor = $e['path'];
        }
        $r->contextMenu(function (Menu $m) use ($c, $v, $e, $open, $chosen): void { // IINA's: of the films chosen, the one clicked with them
            $films = $chosen ? $v->chosen : [$e['path']];
            $playable = array_values(array_filter($films, fn(string $p) => preg_match('~^https?://~i', $p) === 1 || is_file($p)));
            $files = array_values(array_filter($films, 'is_file'));
            if ($m->item(VideoPlayerSettings::iina($c, 'history_window.menu.play'))->disabled($playable === [])->chosen()) { $open($playable, false); }
            if ($m->item(VideoPlayerSettings::iina($c, 'history_window.menu.play_new_window'))->disabled($playable === [])->chosen()) { $open($playable, true); }
            $m->separator();
            if ($m->item(VideoPlayerSettings::iina($c, 'history_window.menu.show_in_finder'))->disabled($files === [])->chosen()) { foreach ($files as $f) { Shell::showItemInFolder($f); } }
            $m->separator();
            if ($m->item(VideoPlayerSettings::iina($c, 'history_window.menu.delete'))->chosen() && self::ask($c, 'delete_history')) { PlaybackHistory::default()->remove($films); $v->chosen = []; }
            if ($m->item(VideoPlayerSettings::iina($c, 'history_window.menu.delete_file'))->disabled($files === [])->chosen() && self::ask($c, 'delete_file')) { foreach ($files as $f) { Shell::trashItem($f); } }
        });
    }

    /** IINA's keys: Command-A all, Return plays, Command-Return in a new window, Command-L shows in the Finder, Delete deletes from the history. */
    private static function keys(Context $c, object $v, array $order, PlaybackHistory $history, Closure $open): void
    {
        $playable = fn() => array_values(array_filter($v->chosen, fn(string $p) => preg_match('~^https?://~i', $p) === 1 || is_file($p)));
        if ($c->shortcut('a', Key::COMMAND)) { $v->chosen = $order; }
        if ($c->shortcut(Key::RETURN, Key::COMMAND) && $playable() !== []) { $open($playable(), true); }
        elseif ($c->shortcut(Key::RETURN) && $playable() !== []) { $open($playable(), false); }
        if ($c->shortcut('l', Key::COMMAND)) { foreach (array_filter($v->chosen, 'is_file') as $f) { Shell::showItemInFolder($f); } }
        if (($c->shortcut(Key::FORWARD_DELETE) || $c->shortcut(Key::DELETE)) && $v->chosen !== [] && self::ask($c, 'delete_history')) { $history->remove($v->chosen); $v->chosen = []; }
    }

    /** When a film was played, as IINA writes it: the time (by date), or the date and time (by folder), hours of two digits. */
    private static function when(int $at, bool $withDate): string
    {
        $f = new DateFormatter(\MyPHP\App::locale(), new DateOptions(skeleton: $withDate ? 'yyMMddjjmm' : 'jjmm'));
        return $f->format((new \DateTimeImmutable("@$at"))->setTimezone(new \DateTimeZone(date_default_timezone_get())));
    }

    /** IINA's question before deleting ($what: delete_history, delete_all_history, delete_file): OK, or Cancel. */
    private static function ask(Context $c, string $what): bool
    {
        $r = Dialog::message(new MessageOptions(parent: Window::focused(), type: 'warning', message: VideoPlayerSettings::iina($c, "alert.$what.title"), detail: VideoPlayerSettings::iina($c, "alert.$what.message"), buttons: [$c->texts()->get('ok'), $c->texts()->get('cancel')]));
        return $r->response === 0;
    }

    /** The Finder's icon of $path, drawn once. */
    private static function icon(Context $c, string $path): Image
    {
        static $icons = [];
        return $icons[$path] ??= Image::fromBytes(Shell::fileIcon($path, (int)(18 * $c->scale)), 64);
    }
}
