<?php
declare(strict_types=1);

namespace MyPHP\UI;

use MyPHP\App;
use MyPHP\Dialog;
use MyPHP\FileFilter;
use MyPHP\MessageOptions;
use MyPHP\OpenDialogOptions;
use MyPHP\PlayerPlugins\Plugin;
use MyPHP\PlayerPlugins\PluginInstallError;
use MyPHP\PlayerPlugins\Plugins;
use MyPHP\Window;
use MyPHP\WindowOptions;

/**
 * What IINA's Plugins page of its settings shows (SettingsPagePlugin's
 * PluginInstallView and PluginListView, PluginManager's dialogs): how a
 * plugin is installed and its buttons, a local package installed (its
 * permissions agreed to, one of its identifier replaced where the user
 * says so); the plugins, each a row of 60 (its switch, name, version, DEV
 * for a link to a folder elsewhere, its menu of Uninstall and Show in
 * Finder, its description, its info button opening its details), dragged
 * into another order.
 *
 * @internal MediaPlayerSettingsPages
 */
final class MediaPlayerPluginsPage
{
    private const ROW = 60;

    /** The card of how to install a plugin: its text, Get Plugins… and Install Local Package…. */
    public static function install(Context $c, object $v): void
    {
        $t = $c->theme;
        $rt = $c->runtime;
        $win = $rt?->window?->get();
        $c->column(0, 12)->padding(12, 12, 12, 12)->fillWidth()->alignItems(Node::START);
          $c->text(MediaPlayerSettings::tr($c, '$YouCanInstallANew'), 11, $t->textMuted)->fillWidth();
          $c->row(0, 8);
            $get = $c->row()->tooltip($c->texts()->get('videoNotYet'))->disabled(); // the plugin store: not yet
              MediaPlayerSettings::pushButton($c, MediaPlayerSettings::tr($c, '$GetPlugins'));
            $c->end();
            if (MediaPlayerSettings::pushButton($c, MediaPlayerSettings::tr($c, '$InstallLocalPackage'))) {
                App::later(function () use ($rt, $win): void {
                    $paths = Dialog::open(new OpenDialogOptions(parent: $win, title: 'Install from local package', filters: [new FileFilter('IINA plugin', ['iinaplgz'])]));
                    if ($paths !== []) { self::installPackage($paths[0], $win); }
                    $rt?->changed();
                });
            }
          $c->end();
        $c->end();
    }

    /**
     * Installs package $file as IINA's PluginManager does: unpacked, its
     * permissions agreed to, one installed of its identifier replaced where
     * the user says so; what went wrong told (IINA's install_error alert).
     * 'installed', 'reinstalled' or 'cancelled'.
     */
    public static function installPackage(string $file, ?Window $parent): string
    {
        try {
            $p = Plugins::unpack($file);
            return Plugins::install($p, fn(Plugin $p): bool => self::agree($p, $parent), fn(Plugin $p): bool => self::ask($parent, Plugins::t('iinaAlertPluginReinstallTitle', ['name' => $p->name]), Plugins::t('iinaAlertPluginReinstallMessage')));
        } catch (PluginInstallError $e) {
            Plugins::alert(Plugins::t('iinaAlertPluginInstallError', ['message' => $e->getMessage()]));
            return 'cancelled';
        }
    }

    /** IINA's sheet of $p's permissions to agree to before it is installed: Install or Cancel. */
    private static function agree(Plugin $p, ?Window $parent): bool
    {
        $list = implode("\n\n", array_map(fn(array $q) => ($q['isDangerous'] ? '⚠️ ' : '') . "{$q['name']}\n{$q['desc']}", $p->localizedPermissions()));
        return Dialog::message(new MessageOptions(parent: $parent, type: 'warning', message: Plugins::t('iinaAlertTitleWarning'),
            detail: Plugins::t('iinaAlertPluginPermission') . "\n\n" . $list, buttons: [Plugins::t('iinaPluginInstall'), Plugins::t('cancel')], cancelButton: 1))->button === 0;
    }

    /** IINA's ask: $title, $message, OK or Cancel; whether OK. */
    private static function ask(?Window $parent, string $title, string $message): bool
    {
        return Dialog::message(new MessageOptions(parent: $parent, type: 'warning', message: $title, detail: $message, buttons: [Plugins::t('ok'), Plugins::t('cancel')], cancelButton: 1))->button === 0;
    }

    /** The card of the plugins installed, in their order: a row each, lines between them; a row dragged to another place. */
    public static function list(Context $c, object $v): void
    {
        $t = $c->theme;
        $k = MediaPlayerSettings::colors($t);
        $plugins = Plugins::all();
        $v->pluginDrag ??= null; // [the row dragged, how far]
        $box = $c->column()->fillWidth();
          foreach ($plugins as $i => $p) {
              if ($i > 0) { $c->box(0, 1, $k['line'])->fillWidth(); }
              $row = self::row($c, $v, $p);
              $d = $c->dragged($row);
              if ($d !== null) { $v->pluginDrag = [$i, ($v->pluginDrag[0] ?? -1) === $i ? $v->pluginDrag[1] + $d[1] : $d[1]]; }
              if (($v->pluginDrag[0] ?? -1) === $i) {
                  $row->translate(0, $v->pluginDrag[1])->background($k['hover'], $k['hoverAlpha']);
                  if ($c->dragEnded($row) !== null || !$c->pressed($row)) { // let go: before the row it is over (IINA's drop above a row)
                      $to = max(0, min(count($plugins), (int)round($i + $v->pluginDrag[1] / (self::ROW + 1)) + ($v->pluginDrag[1] > 0 ? 1 : 0)));
                      $v->pluginDrag = null;
                      Plugins::move($i, $to);
                      $c->runtime?->changed();
                  }
              }
          }
        $c->end();
    }

    /** $p's row (IINA's ItemView). */
    private static function row(Context $c, object $v, Plugin $p): Node
    {
        $t = $c->theme;
        $rt = $c->runtime;
        $win = $rt?->window?->get();
        $row = $c->key('plugin:' . $p->identifier)->row()->height(self::ROW)->fillWidth()->alignItems(Node::CENTER)->paddingStart(8)->paddingEnd(8);
          $c->column(0, 4)->grow(1)->minWidth(0)->paddingEnd(20);
            $c->row(0, 8)->alignItems(Node::CENTER);
              if (self::miniSwitch($c, $p->name, $p->enabled())) { Plugins::setEnabled($p, !$p->enabled()); }
              $c->text($p->name, 13, $t->text, Font::BOLD)->singleLine();
              $c->text($p->version, 11, $t->textMuted)->opacity(0.7)->singleLine();
              if ($p->isExternal) { // a link to a folder elsewhere
                  $c->row()->paddingX(3)->radius(3)->background(0x5856d6);
                    $c->text('DEV', 9, 0x000000, Font::BOLD);
                  $c->end();
              }
              $actions = $c->row()->size(16, 16)->center()->role(Role::BUTTON)->label('…');
                MediaPlayerSettings::sf($c, 'ellipsis', 12, $t->text);
              $c->end();
              $actions->menu(function (Menu $m) use ($p, $rt, $win): void {
                  $uninstall = $m->item(Plugins::t('settingsUninstall'))->disabled($p->isExternal);
                  if ($uninstall->chosen()) {
                      App::later(function () use ($p, $rt, $win): void {
                          if (self::ask($win, Plugins::t('iinaAlertPluginUninstallTitle', ['name' => $p->name]), Plugins::t('iinaAlertPluginUninstallMessage'))) { Plugins::remove($p); }
                          $rt?->changed();
                      });
                  }
                  if ($m->item(Plugins::t('settingsShowInFinder'))->chosen()) { \MyPHP\Shell::showItemInFolder($p->root); }
              });
            $c->end();
            $c->text($p->desc ?? 'No description', 11, $t->textMuted)->singleLine()->ellipsis('…')->fillWidth();
          $c->end();
          $about = $c->row()->size(18, 18)->center()->role(Role::BUTTON)->label(MediaPlayerSettings::tr($c, '$About'));
            MediaPlayerSettings::sf($c, 'info.circle', 15, $t->text);
          $c->end();
          if ($c->clicked($about)) { App::later(fn() => self::details($p, $win)); }
        $c->end();
        return $row;
    }

    /** An NSSwitch of the mini size, labeled $label: whether it was clicked. */
    private static function miniSwitch(Context $c, string $label, bool $on): bool
    {
        $sw = $c->row()->size(26, 15)->role(Role::SWITCH)->label($label)->checked($on)->draw(static function (Painter $p, array $r) use ($on): void {
            $t = $p->theme();
            $p->fill($r, $on ? $t->accent : ($t->dark ? 0xffffff : 0x000000), 7.5, $on ? 1.0 : ($t->dark ? 0.2 : 0.09));
            $p->fill([$on ? $r[0] + $r[2] - 15 : $r[0] + 2, $r[1] + 2, 13, 11], 0xffffff, 5.5);
        });
        $c->end();
        return $c->clicked($sw);
    }

    /**
     * IINA's sheet of $p's details (PluginDetailsWindow, 600 by 450): its
     * icon, name and version; its About (identifier, author, website,
     * source, subtitle providers, permissions); Done, its preferences kept.
     */
    public static function details(Plugin $p, ?Window $parent): void
    {
        $sheet = null;
        $close = function () use (&$sheet, $p): void { $p->syncPreferences(); App::later(function () use (&$sheet): void { $sheet?->close(); }); };
        $sheet = new Window(new WindowOptions(parent: $parent, modal: true, width: 600, height: 450,
            content: Content::view(function (Context $c) use ($p, $close): void { self::detailsView($c, $p, $close); })));
    }

    private static function detailsView(Context $c, Plugin $p, \Closure $close): void
    {
        $t = $c->theme;
        $k = MediaPlayerSettings::colors($t);
        $c->column(0, 0, 1)->padding(16, 16, 16, 16)->background($k['page']);
          $c->row(0, 8)->alignItems(Node::START)->fillWidth();
            $c->column()->size(24, 24)->margin(4, 0, 0, 0)->center();
              MediaPlayerSettings::glyph($c, ['puzzlepiece.extension', 'puzzlepiece'], $t->text);
            $c->end();
            $c->column(0, 4);
              $c->text($p->name, 13, $t->text, Font::BOLD);
              $c->text($p->version, 11, $t->textMuted);
            $c->end();
          $c->end();
          $c->scroll()->grow(1)->fillWidth()->margin(12, 0, 16, 0);
            $c->column(0, 0)->fillWidth();
              $entry = function (string $key, string $value, bool $link = false) use ($c, $t): void {
                  $c->text($key, 11, $t->textMuted);
                  $n = $c->text($value, 13, $link ? $t->accent : $t->text)->margin(0, 0, 12, 0)->selectable();
                  if ($link && $c->clicked($n)) { $c->openURL($value); }
              };
              $entry(MediaPlayerSettings::tr($c, '$Identifier'), $p->identifier);
              $entry(MediaPlayerSettings::tr($c, '$Author'), $p->authorName);
              if (($p->authorURL ?? '') !== '') { $entry(MediaPlayerSettings::tr($c, '$Website'), (string)$p->authorURL, true); }
              $gh = $p->githubURL();
              $gh !== null ? $entry(MediaPlayerSettings::tr($c, '$Source'), $gh, true) : $entry(MediaPlayerSettings::tr($c, '$Source'), Plugins::t('iinaPluginLocal'));
              if ($p->subProviders !== null) { $entry('Registered Subtitle Providers', implode("\n", array_map(fn($s) => is_array($s) ? (string)($s['name'] ?? '?') : '?', $p->subProviders))); }
              $c->text('Permissions', 11, $t->textMuted);
              $perms = $p->localizedPermissions();
              if ($perms === []) { $c->text(Plugins::t('iinaQuicksettingNone'), 13, $t->text); }
              foreach ($perms as $q) {
                  $c->column(0, 2)->fillWidth()->padding(8, 8, 8, 8)->margin(8, 0, 0, 0)->radius(4)->borderWidth(1)
                      ->borderColor($q['isDangerous'] ? 0xc80000 : ($t->dark ? 0xffffff : 0x000000))->background($q['isDangerous'] ? 0xc80000 : ($t->dark ? 0xffffff : 0x000000), 0.1);
                    $c->text($q['name'], 11, $t->text, Font::BOLD);
                    $c->text($q['desc'], 11, $t->text)->opacity(0.8)->fillWidth();
                  $c->end();
              }
            $c->end();
          $c->end();
          $c->row()->fillWidth()->justify(Node::END);
            if (MediaPlayerSettings::pushButton($c, Plugins::t('done'), false) || $c->shortcut(Key::ESCAPE) || $c->shortcut(Key::RETURN)) { $close(); }
          $c->end();
        $c->end();
    }
}
