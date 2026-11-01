<?php
declare(strict_types=1);

namespace MyPHP\UI;

use MyPHP\PlayerPlugins\DevTool;
use MyPHP\PlayerPlugins\Instance;
use MyPHP\Window;
use MyPHP\WindowOptions;

/**
 * IINA's window of a plugin instance's developer tool (JSDevToolWindow,
 * 500 by 400, "DevTool: <name>"): what was run and what came of it, the
 * log and exceptions among them, the end shown as they come; under them
 * the console: run (Cmd+Return), print what is defined, clear, the code
 * typed (the up arrow on an empty one recalling what was run). One window
 * for each instance, brought forward where it shows already.
 *
 * @internal MediaPlayerMenus
 */
final class MediaPlayerDevTool
{
    /** @var array<int, Window> the windows, by their instance */
    private static array $windows = [];

    /** Shows $instance's developer tool, titled "DevTool: $title". */
    public static function show(Instance $instance, string $title): void
    {
        $key = spl_object_id($instance);
        if (($w = self::$windows[$key] ?? null) !== null && !$w->isDestroyed()) { $w->show(); $w->focus(); return; }
        $tool = DevTool::for($instance);
        $st = (object)['input' => new TextState(''), 'list' => new ListState(), 'recall' => null, 'expanded' => []];
        $st->list->followEnd = true;
        $w = null;
        $w = self::$windows[$key] = new Window(new WindowOptions(title: "DevTool: $title", width: 500, height: 400,
            content: Content::view(function (Context $c) use ($tool, $st): void { self::view($c, $tool, $st); })));
        $tool->changed = function () use (&$w): void { $w?->invalidate(); };
        $w->on('closed', function () use ($key, $tool): void { unset(self::$windows[$key]); $tool->changed = null; });
        $w->center();
    }

    /** The menu items' titles of $instance: its plugin's name, " (Global)" after a global instance's (IINA's). */
    public static function title(Instance $instance): string
    {
        return $instance->plugin->name . ($instance->isGlobal ? ' (Global)' : '');
    }

    private static function view(Context $c, DevTool $tool, object $st): void
    {
        $t = $c->theme;
        $mono = Font::MONO;
        $c->column(0, 0, 1)->background($t->dark ? 0x1e1e1e : 0xffffff);
          $c->list($st->list, count($tool->entries), function (int $i) use ($c, $tool, $st, $t, $mono): void {
              $e = $tool->entries[$i];
              $c->key('e' . $e['id'])->row(0, 8)->fillWidth()->padding(4, 8, 4, 8)->alignItems(Node::START);
              switch ($e['kind']) {
                  case 'prompt': // IINA's PromptView: "[n]:" in green, the code
                      $c->text("[{$e['index']}]:", 13, 0x28cd41, $mono)->width(50)->textAlign(Node::END);
                      $c->text((string)$e['text'], 13, $t->text, $mono)->grow(1)->minWidth(0)->selectable();
                      break;
                  case 'result': // its ReturnValueView: "→", the value
                      $c->text('→', 13, $t->textMuted, $mono)->width(50)->textAlign(Node::END);
                      self::value($c, $e, $st, $t);
                      break;
                  case 'exception':
                      $c->column(0, 4)->grow(1)->padding(8, 8, 8, 8)->background(0xff3b30, 0.1);
                        $c->text('Exception: ' . $e['message'], 13, 0xff3b30)->fillWidth()->selectable();
                        $c->text((string)$e['stack'], 11, $t->textMuted)->fillWidth()->selectable();
                      $c->end();
                      break;
                  default: // a log line, in its level's color
                      $color = DevTool::levelColor((int)$e['level']);
                      $box = $c->column()->grow(1)->padding(8, 8, 8, 8);
                      if ($color !== null) { $box->background($color, 0.1); }
                        $c->text((string)$e['message'], 13, $t->text, $mono)->fillWidth()->selectable();
                      $c->end();
              }
              $c->end();
          })->grow(1)->fillWidth();
          // The console (IINA's ConsoleView over the ultra thick material).
          $c->column(0, 0)->fillWidth()->systemMaterial('hud', $t->dark);
            $c->row(0, 10)->fillWidth()->alignItems(Node::CENTER)->padding(8, 8, 0, 8);
              $run = $c->row()->role(Role::BUTTON)->label('Run code')->tooltip('Run code'); MediaPlayerSettings::sf($c, 'play.circle', 15, $t->accent); $c->end();
              $global = $c->row()->role(Role::BUTTON)->label('Print global objects')->tooltip('Print global objects'); MediaPlayerSettings::sf($c, 'point.3.filled.connected.trianglepath.dotted', 15, $t->textMuted); $c->end();
              $clear = $c->row()->role(Role::BUTTON)->label('Clear history')->tooltip('Clear history'); MediaPlayerSettings::sf($c, 'clear', 15, $t->textMuted); $c->end();
              $c->row()->grow(1); $c->end();
              $c->text('Cmd+Return to run code', 10, $t->textMuted);
            $c->end();
            $editor = $c->textAreaBase($st->input)->font('Menlo')->fillWidth()->height(84)->margin(8, 8, 8, 8);
          $c->end();
        $c->end();
        if ($c->clicked($run) || $c->shortcut(Key::RETURN, Key::COMMAND)) {
            $tool->run($st->input->text);
            $st->input->text = ''; $st->input->setCursor(0);
            $st->recall = null;
        }
        if ($c->clicked($global)) { $tool->run('$global'); }
        if ($c->clicked($clear)) { $tool->clear(); }
        if ($c->focused($editor) && $c->shortcut(Key::UP)) { // the code run before, the last first, as the field is empty (IINA's recallIndex)
            if ($st->input->text === '') { $st->recall = 0; }
            $history = $tool->history();
            if ($st->recall !== null && isset($history[$st->recall])) {
                $st->input->text = $history[$st->recall]; $st->input->setCursor(strlen($st->input->text));
                $st->recall++;
            }
        }
    }

    /** A value, as IINA's ResultView shows it: a number in blue, a string in brown, a boolean in purple, null in gray; an array or an object its title, its members under it as it is clicked (an array's first 100). */
    private static function value(Context $c, array $e, object $st, Theme $t): void
    {
        [$kind, $text, $members] = DevTool::describe($e['value'] ?? null);
        if ($kind !== 'array' && $kind !== 'object') {
            $c->text($text, 13, match ($kind) { 'number' => 0x007aff, 'string' => 0xa2845e, 'boolean' => 0xaf52de, default => $t->textMuted }, Font::MONO)->grow(1)->minWidth(0)->selectable();
            return;
        }
        $open = $st->expanded[$e['id']] ?? false;
        $c->column(0, 2)->grow(1)->minWidth(0);
          $head = $c->row(0, 6)->alignItems(Node::CENTER)->role(Role::BUTTON)->label($text);
            MediaPlayerSettings::sf($c, $open ? 'chevron.down.circle.fill' : 'chevron.forward.circle.fill', 10, $t->text);
            $c->text($text, 13, $t->text, Font::MONO);
          $c->end();
          if ($c->clicked($head)) { $st->expanded[$e['id']] = !$open; }
          if ($open) {
              foreach ($kind === 'array' ? array_slice($members, 0, 100) : $members as [$k, $v]) {
                  $c->row(0, 6)->paddingStart(8);
                    $c->text("$k:", 13, $t->textMuted, Font::MONO);
                    $c->text($v, 13, $t->text, Font::MONO)->singleLine()->ellipsis('…');
                  $c->end();
              }
              if ($kind === 'array' && count($members) > 100) { $c->text('... (' . (count($members) - 100) . ') more', 13, $t->textMuted, Font::MONO); }
          }
        $c->end();
    }
}
