<?php
declare(strict_types=1);

namespace MyPHP\UI;

use MyPHP\Clipboard;
use MyPHP\Dialog;
use MyPHP\Logger;
use MyPHP\MessageOptions;
use MyPHP\SaveDialogOptions;
use MyPHP\Window;
use MyPHP\WindowOptions;

/**
 * The app's log viewer (of Logger's), as IINA's (LogWindowController): its toolbar's
 * Now (following the latest), the level shown (and those above it), the
 * subsystems shown (all, or those chosen), Save (all, or those shown) and
 * a filter of the messages; the log in rows of its level's dot, its time,
 * its subsystem and its message (wrapped), chosen with a click (Command
 * and Shift more), copied with Command-C or the menu of a right click.
 * Its subtitle says what is shown. Built by Context::logViewer, in a
 * window of a hidden title bar, 800 by 500; the video player's settings'
 * button opens one (open()).
 *
 * @internal UI\Context, UI\MoviePlayerSettingsPages
 */
final class LogViewer
{
    private const BAR = 52, ROW = 18, LEVEL = 18, TIME = 90, SUBSYSTEM = 120;
    /** The levels' colors, the system's gray, green, yellow and red. */
    private const COLORS = [0x8e8e93, 0x34c759, 0xffcc00, 0xff3b30];
    private static ?Window $window = null;

    /** The window of the log viewer, opened, or brought forward (the settings' Open the log viewer, IINA's Window > Log Viewer). */
    public static function open(): void
    {
        if (self::$window !== null && !self::$window->isDestroyed()) {
            self::$window->show();
            self::$window->focus();
            return;
        }
        self::$window = new Window(new WindowOptions(title: 'Log Viewer', width: 800, height: 500, minWidth: 800, minHeight: 500, stateKey: 'log viewer', titleBarStyle: 'hiddenInset',
            content: Content::view(fn(Context $c) => $c->logViewer())));
    }

    public static function build(Context $c): Node
    {
        $t = $c->theme;
        $k = MoviePlayerSettings::colors($t);
        $root = $c->column(0, 0, 1)->background($k['page']);
        $make = fn() => (object)['level' => Logger::level(), 'subsystems' => [], 'filter' => new TextState(), 'list' => self::listState(), 'chosen' => [], 'anchor' => null];
        $v = $c->runtime?->local($root->id, $make) ?? $make();
        $q = mb_strtolower(trim($v->filter->text));
        $all = Logger::entries();
        $shown = array_values(array_filter($all, fn(array $e) => $e['level'] >= $v->level && ($v->subsystems === [] || in_array($e['subsystem'], $v->subsystems, true))
            && ($q === '' || str_contains(mb_strtolower($e['message']), $q))));
        $c->runtime?->askWindow('title', MoviePlayerSettings::iina($c, 'logwindow.title'));
          self::toolbar($c, $v, $all, $shown);
          $c->row()->height(24)->fillWidth()->paddingX(16)->alignItems(Node::CENTER)->borderWidth(0, 0, 1, 0)->borderColor($k['line']); // the columns' headers
            $c->box(self::LEVEL, 1, 0)->color = null;
            $c->text(MoviePlayerSettings::iina($c, 'logwindow.time'), 11, $t->textMuted)->width(self::TIME);
            $c->text(MoviePlayerSettings::iina($c, 'logwindow.subsystem'), 11, $t->textMuted)->width(self::SUBSYSTEM);
            $c->text(MoviePlayerSettings::iina($c, 'logwindow.message'), 11, $t->textMuted)->grow(1);
          $c->end();
          $c->column(0, 0, 1)->padding(0, 8);
            $c->list($v->list, count($shown), function (int $i) use ($c, $t, $v, $shown): void {
                $e = $shown[$i];
                $on = in_array($i, $v->chosen, true);
                $r = $c->row(0, 0)->fillWidth()->paddingX(8)->radius(5)->alignItems(Node::START);
                $r->background($on ? $t->accent : ($t->dark ? 0xffffff : 0x000000), $on ? 1.0 : ($i % 2 === 1 ? 0.03 : 0.0)); // the rows alternating, as IINA's table
                $ink = $on ? $t->accentText : $t->text;
                  $c->column()->size(self::LEVEL, self::ROW)->center();
                    $c->box(8, 8, self::COLORS[$e['level']])->radius(4);
                  $c->end();
                  $c->text($e['time'], 11, $ink)->font('monospace')->width(self::TIME)->singleLine()->margin(2, 0, 2, 0);
                  $c->text($e['subsystem'], 11, $ink)->font('monospace')->width(self::SUBSYSTEM)->singleLine()->ellipsis('…')->margin(2, 0, 2, 0);
                  $c->text($e['message'], 11, $ink)->font('monospace')->grow(1)->margin(2, 0, 2, 0);
                $c->end();
                if ($c->clicked($r)) { self::choose($c, $v, $i); }
                $r->contextMenu(function (Menu $m) use ($c, $v, $shown, $i): void {
                    if ($m->item(MoviePlayerSettings::iina($c, 'logwindow.copy'))->chosen()) { self::copy($v->chosen !== [] && in_array($i, $v->chosen, true) ? $v->chosen : [$i], $shown); }
                });
            });
          $c->end();
        $c->end();
        if ($c->shortcut('c', Key::COMMAND) && $v->chosen !== []) { self::copy($v->chosen, $shown); }
        if ($c->shortcut('a', Key::COMMAND)) { $v->chosen = array_keys($shown); }
        $c->after(0.1); // what came shown every tenth of a second, as IINA flushes it (at the end, while it follows)
        return $root;
    }

    private static function listState(): ListState
    {
        $s = new ListState();
        $s->estimate = self::ROW + 4;
        $s->followEnd = true; // at the end, it stays there as more come: IINA's following
        return $s;
    }

    /** A click on row $i: it alone, Command another, Shift those from the last. */
    private static function choose(Context $c, object $v, int $i): void
    {
        $mods = $c->modifiers();
        if (($mods & Key::COMMAND) !== 0) {
            $v->chosen = in_array($i, $v->chosen, true) ? array_values(array_diff($v->chosen, [$i])) : [...$v->chosen, $i];
        } elseif (($mods & Key::SHIFT) !== 0 && $v->anchor !== null) {
            $v->chosen = range(min($v->anchor, $i), max($v->anchor, $i));
        } else {
            $v->chosen = [$i];
        }
        $v->anchor = $i;
    }

    /** The rows $rows of $shown on the clipboard, as the log file has them. @param list<int> $rows */
    private static function copy(array $rows, array $shown): void
    {
        sort($rows);
        Clipboard::writeText(implode('', array_map(fn(int $i) => Logger::line($shown[$i]), array_filter($rows, fn(int $i) => isset($shown[$i])))));
    }

    /** The toolbar: the title and what is shown under it; Now, the level, the subsystems, Save, the filter, as IINA's. */
    private static function toolbar(Context $c, object $v, array $all, array $shown): void
    {
        $t = $c->theme;
        $bar = $c->titleBar();
        $c->row(0, 8)->height(self::BAR)->fillWidth()->paddingStart(max(80.0, $bar['left'] + 12))->paddingEnd(12)->alignItems(Node::CENTER)->dragWindow();
          $c->column(0, 0)->grow(1);
            $c->text(MoviePlayerSettings::iina($c, 'logwindow.title'), 15, $t->text, Font::BOLD)->singleLine();
            $c->text(($v->subsystems === [] ? MoviePlayerSettings::iina($c, 'logwindow.all_subsystems') : '[' . implode(', ', $v->subsystems) . ']') . ' - ' . Logger::NAMES[$v->level], 11, $t->textMuted)->singleLine()->ellipsis('…');
          $c->end();
          $following = $v->list->atEnd;
          $now = self::button($c, $following ? 'arrow.up.left.circle.fill' : 'arrow.up.left.circle', MoviePlayerSettings::iina($c, 'logwindow.now.desc'), $following);
          if ($c->clicked($now)) { $v->list->scrollToEnd(); }
          $level = self::button($c, 'circle.fill', MoviePlayerSettings::iina($c, 'logwindow.log_level'), false, self::COLORS[$v->level]);
          $level->menu(function (Menu $m) use ($v): void {
              foreach (Logger::NAMES as $l => $name) {
                  if ($m->item($name)->checked($v->level === $l)->chosen()) { $v->level = $l; $v->chosen = []; }
              }
          });
          $subs = self::button($c, 'square.stack.3d.up', MoviePlayerSettings::iina($c, 'logwindow.subsystem'), $v->subsystems !== []);
          $subs->menu(function (Menu $m) use ($c, $v): void {
              if ($m->item(MoviePlayerSettings::iina($c, 'logwindow.clear_subsystem_selections'))->chosen()) { $v->subsystems = []; }
              $m->separator();
              foreach (Logger::subsystems() as $name) {
                  $on = in_array($name, $v->subsystems, true);
                  if ($m->item((string)$name)->checked($on)->chosen()) { $v->subsystems = $on ? array_values(array_diff($v->subsystems, [$name])) : [...$v->subsystems, (string)$name]; $v->chosen = []; }
              }
          });
          $save = self::button($c, 'square.and.arrow.down', MoviePlayerSettings::iina($c, 'logwindow.save'), false);
          if ($c->clicked($save)) { self::save($c, $all, \MyPHP\App::name() . '.log'); }
          $save->contextMenu(function (Menu $m) use ($c, $v, $shown): void { // IINA's menu of the button: the logs shown
              if ($m->item(MoviePlayerSettings::iina($c, 'logwindow.save_filtered'))->chosen()) {
                  self::save($c, $shown, ($v->subsystems === [] ? MoviePlayerSettings::iina($c, 'logwindow.all_subsystems') : '[' . implode(', ', $v->subsystems) . ']') . ' - ' . Logger::NAMES[$v->level] . ' ' . \MyPHP\App::name() . '.log');
              }
          });
          $c->row()->width(180)->height(28)->radius(14)->systemMaterial('glass')->alignItems(Node::CENTER)->paddingX(4);
            $f = $c->searchField($v->filter, 0);
          $c->end();
          $f->label(MoviePlayerSettings::iina($c, 'logwindow.filter'));
        $c->end();
    }

    /** A button of the toolbar, of glass: its symbol (in $rgb), $on in the accent. */
    private static function button(Context $c, string $symbol, string $label, bool $on, ?int $rgb = null): Node
    {
        $t = $c->theme;
        $b = $c->column()->size(36, 28)->radius(14)->center()->systemMaterial('glass')->role(Role::BUTTON)->label($label)->tooltip($label);
          MoviePlayerSettings::sf($c, $symbol, $rgb !== null ? 10 : 14, $rgb ?? ($on ? $t->accent : $t->text));
        $c->end();
        return $b;
    }

    /** The logs $entries saved where the user says, named $name first. */
    private static function save(Context $c, array $entries, string $name): void
    {
        $path = Dialog::save(new SaveDialogOptions(parent: Window::focused(), title: 'Log', defaultPath: $name));
        if ($path === '') { return; }
        if (@file_put_contents($path, implode('', array_map([Logger::class, 'line'], $entries))) === false) { // IINA's alert: the logs, and why
            $why = error_get_last()['message'] ?? 'cannot write';
            Dialog::message(new MessageOptions(parent: Window::focused(), type: 'warning', message: sprintf(str_replace('%@', '%s', MoviePlayerSettings::iina($c, 'alert.error_saving_file')), MoviePlayerSettings::iina($c, 'logwindow.logs'), $why)));
        }
    }
}
