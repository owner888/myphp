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

    /** IINA's bridge of a plugin's settings page (IINABridgeScript): window.iina.preferences' get and set, its log and its errors, myphp's messages. */
    private const PREFERENCES_BRIDGE = <<<'JS'
(() => {
  let counter = 0;
  const post = (n, d) => window.webkit.messageHandlers.myphp.postMessage(JSON.stringify({ t: "post", n, d }));
  window.onerror = (msg, url, line, col, error) => { window.iina._post("error", [msg, url, line, col, String(error)]); };
  window.iina = {
    log(message) { this._post("log", [message]); },
    _post(name, data) { post("pref:" + name, data); },
    _callbacks: {},
    _call(id, data) { this._callbacks[id].call(null, data); delete this._callbacks[id]; },
  };
  window.iina.preferences = {
    set(name, value) { window.iina._post("set", [name, value]); },
    get(name, callback) {
      counter++;
      window.iina._post("get", [name, counter]);
      if (typeof callback !== "function") throw Error("Callback is not provided.");
      window.iina._callbacks[counter] = callback;
    },
  };
  window.myphp.on("iina-pref", (m) => window.iina._call(m.id, m.value));
})();
JS;
    /** IINA's PreferenceSyncScript and its CSS, once the page's document is there: its inputs of data-pref-key kept as the preferences. */
    private const PREFERENCES_SYNC = <<<'JS'
document.addEventListener("DOMContentLoaded", () => {
  const styleSheet = document.createElement("style");
  styleSheet.innerHTML = `* { box-sizing: border-box; } html { margin: 0; padding: 0; }
body { padding: 16px; margin: 0; font-size: 13px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Helvetica Neue", sans-serif; }
p { margin: 0; } small, .small { font-size: 11px; } .secondary { color: rgba(0, 0, 0, 0.5); } .pref-section, .pd { margin-bottom: 12px; } .pref-help { margin-top: 2px; }
@media (prefers-color-scheme: dark) { body { color-scheme: dark; color: #fff; } .secondary { color: rgba(255, 255, 255, .5); } }`;
  document.head.appendChild(styleSheet);
  const { preferences } = window.iina;
  const inputs = document.querySelectorAll("input[data-pref-key]");
  const radioNames = new Set();
  Array.prototype.forEach.call(inputs, input => {
    const key = input.dataset.prefKey;
    const type = input.type;
    if (type === "radio") {
      radioNames.add(input.name);
    } else {
      preferences.get(key, (value) => {
        if (type === "number") { input.value = parseFloat(value); }
        else if (type === "checkbox") { input.checked = value; }
        else { input.value = value; }
      });
      input.addEventListener("change", () => {
        let value = input.value;
        switch (input.dataset.type) {
          case "int": value = parseInt(value); break;
          case "float": value = parseFloat(value); break;
        }
        preferences.set(key, input.type === "checkbox" ? !!input.checked : value);
      });
    }
  });
  for (const name of radioNames.values()) {
    const inputs = document.getElementsByName(name);
    preferences.get(name, (value) => {
      Array.prototype.forEach.call(inputs, input => {
        if (input.value === value) input.checked = true;
        input.addEventListener("change", () => { if (input.checked) preferences.set(name, input.value); });
      });
    });
  }
});
JS;

    /**
     * IINA's sheet of $p's details (PluginDetailsWindow, 600 by 450): its
     * icon, name and version, its tabs: Settings (its preferencesPage in a
     * web view, its inputs of data-pref-key kept as its preferences, IINA's
     * bridge), About (identifier, author, website, source, subtitle
     * providers, permissions), Help (its helpPage, a file of it or a page
     * of the web); Done, its preferences kept, as they are as the tab
     * changes.
     */
    public static function details(Plugin $p, ?Window $parent): void
    {
        $sheet = null;
        $st = (object)['tab' => 0, 'web' => null, 'shown' => -1, 'failed' => false, 'loading' => false];
        $close = function () use (&$sheet, $p, $st): void { $p->syncPreferences(); App::later(function () use (&$sheet, $st): void { $st->web?->destroy(); $sheet?->close(); }); };
        $sheet = new Window(new WindowOptions(parent: $parent, modal: true, width: 600, height: 450,
            content: Content::view(function (Context $c) use ($p, $close, $st): void { self::detailsView($c, $p, $close, $st); })));
    }

    /** The details' web view, made once, its page's messages its preferences' (the Settings tab's alone), its navigation kept to the plugin's pages but Help's. */
    private static function detailsWeb(Context $c, Plugin $p, object $st): ?\MyPHP\WebView
    {
        if ($st->web !== null) { return $st->web; }
        $w = $c->runtime?->window?->get();
        if ($w === null) { return null; }
        $web = $st->web = $w->newWebView(new \MyPHP\WebViewOptions(page: new \MyPHP\PageOptions(preloadScript: self::PREFERENCES_BRIDGE . "\n" . self::PREFERENCES_SYNC), transparent: true));
        $page = $web->page();
        $page->on('message', function (string $name, mixed $data) use ($p, $st, $page): void {
            if ($st->tab !== 0 || !is_array($data)) { return; } // the settings' alone: not a help page's (IINA's)
            match ($name) {
                'pref:set' => is_string($data[0] ?? null) ? $p->setPreference($data[0], $data[1] ?? null) : null,
                'pref:get' => is_string($data[0] ?? null) ? $page->event('iina-pref', ['id' => $data[1] ?? 0, 'value' => $p->hasPreference($data[0]) ? $p->preference($data[0]) : null]) : null,
                'pref:error' => \MyPHP\Logger::error("JS:{$p->name} Preference page " . implode(' ', array_map(fn($x) => is_scalar($x) ? (string)$x : '', $data)), Plugins::LOG),
                'pref:log' => \MyPHP\Logger::debug("JS:{$p->name} Preference page: " . (is_scalar($data[0] ?? null) ? (string)$data[0] : ''), Plugins::LOG),
                default => null,
            };
        });
        $page->on('willNavigate', function (\MyPHP\NavigateEvent $e) use ($p, $st): void {
            if ($st->tab === 2) { return; } // a help page goes where it goes
            if ($p->preferencesPageURL !== null && str_starts_with($e->url, 'file://' . $p->preferencesPageURL)) { return; }
            $e->preventDefault();
            if (str_starts_with(strtolower($e->url), 'https://')) { \MyPHP\Shell::openExternal($e->url); }
            else { \MyPHP\Logger::error("Loading page from {$e->url} is not allowed", Plugins::LOG); }
        });
        $page->on('didFinishLoad', function () use ($st, $c): void { $st->loading = false; $c->runtime?->changed(); });
        $page->on('didFailLoad', function () use ($st, $c): void { $st->loading = false; $st->failed = true; $c->runtime?->changed(); });
        return $web;
    }

    /** IINA's page of a message, $body in its document. */
    private static function html(Plugin $p, string $body): string
    {
        return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . htmlspecialchars($p->name) . '</title></head><body>' . $body . '</body></html>';
    }

    private static function detailsView(Context $c, Plugin $p, \Closure $close, object $st): void
    {
        $t = $c->theme;
        $k = MediaPlayerSettings::colors($t);
        $c->column(0, 0, 1)->padding(16, 16, 16, 16)->background($k['page']);
          $c->row(0, 8)->alignItems(Node::START)->fillWidth();
            $c->column()->size(24, 24)->margin(4, 0, 0, 0)->center();
              MediaPlayerSettings::glyph($c, ['puzzlepiece.extension', 'puzzlepiece'], $t->text);
            $c->end();
            $c->column(0, 4)->grow(1);
              $c->text($p->name, 13, $t->text, Font::BOLD);
              $c->text($p->version, 11, $t->textMuted);
            $c->end();
            $chosen = self::tabs($c, $st->tab);
          $c->end();
          if ($chosen !== null && $chosen !== $st->tab) {
              $p->syncPreferences(); // kept as the tab changes, IINA's
              $st->tab = $chosen;
          }
          $box = $c->column()->grow(1)->fillWidth()->margin(12, 0, 16, 0);
          if ($st->tab === 1) {
              self::about($c, $p);
          } else {
              $web = self::detailsWeb($c, $p, $st);
              if ($web !== null && $st->shown !== $st->tab) { // the tab's page loaded as it is chosen
                  $st->shown = $st->tab;
                  $st->failed = false;
                  $page = $web->page();
                  $url = $st->tab === 0 ? $p->preferencesPageURL : $p->helpPageURL;
                  $st->loading = $url !== null && preg_match('~^https?://~', $url) === 1;
                  match (true) {
                      $url === null => $page->loadHTML(self::html($p, $st->tab === 0 ? 'This plugin does not have a settings page.' : 'This plugin does not have a help page.')),
                      $st->loading => $page->loadURL($url),
                      default => $page->loadFile($url, $p->root),
                  };
              }
              if ($web !== null) { $c->webView($web)->grow(1); }
              if ($st->loading) { $c->column()->absolute()->top(80)->left(0)->right(0)->center(); $c->spinner(); $c->end(); }
              if ($st->failed) { $c->text(MediaPlayerSettings::tr($c, '$FailedToLoadThePage'), 13, 0xff3b30)->absolute()->top(80)->left(0)->right(0)->textAlign(Node::CENTER); }
          }
          $c->end();
          $c->row()->fillWidth()->justify(Node::END);
            if (MediaPlayerSettings::pushButton($c, Plugins::t('done'), false) || $c->shortcut(Key::ESCAPE)) { $close(); }
          $c->end();
        $c->end();
    }

    /** IINA's tabs of the details (an NSSegmentedControl, textured): Settings, About, Help, each its symbol and name; the one clicked, else null. */
    private static function tabs(Context $c, int $chosen): ?int
    {
        $t = $c->theme;
        $k = MediaPlayerSettings::colors($t);
        $clicked = null;
        $c->row(0, 0)->height(32)->padding(2)->radius(16)->background($k['field'], $k['fieldAlpha'])->alignItems(Node::STRETCH)->role(Role::RADIO_GROUP);
          foreach ([[['gearshape.circle', 'gearshape.fill'], '$Settings'], [['info.circle'], '$About'], [['questionmark.circle'], '$Help']] as $n => [$symbol, $label]) {
              $text = MediaPlayerSettings::tr($c, $label);
              $seg = $c->row(0, 6)->paddingX(12)->alignItems(Node::CENTER)->radius(14)->role(Role::RADIO)->label($text)->checked($n === $chosen);
              if ($n === $chosen) {
                  $seg->background($k['control'], $t->dark ? 0.25 : 1.0);
                  if (!$t->dark) { $seg->shadow(0, 0.5, 1.5, 0, 0x000000, 0.25); }
              }
                MediaPlayerSettings::glyph($c, $symbol, $t->text);
                $c->text($text, 13, $t->text);
              $c->end();
              if ($c->clicked($seg)) { $clicked = $n; }
          }
        $c->end();
        return $clicked;
    }

    /** The About tab: identifier, author, website, source, subtitle providers, permissions (the dangerous in red). */
    private static function about(Context $c, Plugin $p): void
    {
        $t = $c->theme;
          $c->scroll()->grow(1)->fillWidth();
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
    }
}
