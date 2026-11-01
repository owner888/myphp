<?php
// IINA's plugins, of PHP (PlayerPlugins): Info.json read as IINA reads it,
// the plugins' folder, their order and which are on, a package installed
// (its permissions agreed to, one of its identifier replaced) and removed;
// an instance's entry run with its Iina: console, preferences, events,
// menu items (the Plugin menu), mpv's properties and commands, core, the
// file system by IINA's paths, utils' exec, XML-RPC's values, subtitle
// providers, the global instance and its players and messages; the
// settings' Plugins page. On macOS, a player's: its events as a film
// opens and seeks, a provider's subtitles found and loaded, timers.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Media\{Player, PlayerSettings};
use MyPHP\MenuItem;
use MyPHP\PlayerPlugins\{HttpError, Iina, Plugin, PluginError, Plugins, XmlRpc};
use MyPHP\UI\{Context, MediaPlayerMenus, MediaPlayerSettings, MediaPlayerState};

$root = sys_get_temp_dir() . '/myphp-plugins-' . getmypid();
$dir = "$root/plugins";
@mkdir($dir, 0777, true);
$settings = new PlayerSettings("$root/settings.json");
$plugin = function (string $folder, array $info, string $main = '<?php return function ($iina) {};', array $files = []) use ($dir): string {
    $d = "$dir/$folder";
    @mkdir($d, 0777, true);
    file_put_contents("$d/Info.json", json_encode($info + ['name' => 'P', 'author' => ['name' => 'A'], 'version' => '1.0', 'entry' => 'main.php']));
    file_put_contents("$d/main.php", $main);
    foreach ($files as $f => $text) { file_put_contents("$d/$f", $text); }
    return $d;
};
$find = function (array $items, string $id) use (&$find): ?MenuItem {
    foreach ($items as $it) {
        if ($it->id === $id) { return $it; }
        if (($f = $find($it->submenu, $id)) !== null) { return $f; }
    }
    return null;
};

// Info.json, as IINA reads it: its keys, an identifier of reverse domain, its files within its folder.
same(null, Plugin::load($plugin('x.iinaplugin', ['identifier' => 'io.x', 'entry' => '../main.php'])), 'an entry outside its folder: none');
same(null, Plugin::load($plugin('y.iinaplugin', ['identifier' => 'not an id'])), 'an identifier not of reverse domain: none');
@unlink("$dir/x.iinaplugin/Info.json"); @rmdir("$dir/x.iinaplugin"); array_map('unlink', glob("$dir/{x,y}.iinaplugin/*", GLOB_BRACE)); @rmdir("$dir/x.iinaplugin"); @rmdir("$dir/y.iinaplugin");
$a = Plugin::load($plugin('io.test.a.iinaplugin', ['identifier' => 'io.test.a', 'name' => 'Alpha', 'description' => 'The first', 'permissions' => ['show-osd', 'file-system', 'network-request', 'no-such'],
    'allowedDomains' => ['example.com', '*.example.org'], 'subtitleProviders' => [['id' => 'subs', 'name' => 'Subs']], 'preferenceDefaults' => ['color' => 'red'], 'ghRepo' => 'me/alpha', 'ghVersion' => 3,
    'localized' => ['zz' => ['name' => 'Zeta']]]));
same(['io.test.a', 'Alpha', 'The first', ['show-osd', 'file-system', 'network-request'], 'https://github.com/me/alpha', 3], [$a->identifier, $a->name, $a->desc, $a->permissions, $a->githubURL(), $a->githubVersion], 'its fields; a permission unknown left out');
check($a->canAccess('https://example.com/x') && $a->canAccess('https://sub.example.org/') && !$a->canAccess('https://evil.com/') && !$a->canAccess('https://example.com.evil/'), 'its hosts: one, those under one');
same(['Access the file system', 'Network Request', 'Show OSD'], array_column($a->localizedPermissions(), 'name'), 'its permissions as told: the dangerous first');
check(str_contains($a->localizedPermissions()[1]['desc'], "- example.com\n- *.example.org"), 'the network\'s, its hosts');

// The folder: those of it, in the order kept; none on before it is turned on; a duplicate identifier made another.
Plugins::configure($dir, $settings);
$plugin('io.test.b.iinaplugin', ['identifier' => 'io.test.b', 'name' => 'Beta']);
$settings->set('pluginOrder', ['io.test.b', 'io.test.a']);
Plugins::configure($dir, $settings);
same(['io.test.b', 'io.test.a'], array_map(fn(Plugin $p) => $p->identifier, Plugins::all()), 'in the order kept');
same([false, false], array_map(fn(Plugin $p) => $p->enabled(), Plugins::all()), 'off till turned on');
Plugins::move(1, 0);
same(['io.test.a', 'io.test.b'], $settings->get('pluginOrder'), 'one dragged before another: the order kept');

// A player's instance: its entry run with its Iina, console and preferences; one that throws logged under its subsystem.
$main = <<<'PHP'
<?php
use MyPHP\PlayerPlugins\Iina;
return function (Iina $iina) {
    $iina->console->log('hello', ['a' => 1]);
    $GLOBALS['pluginRan'][] = [$iina->preferences->get('color'), $iina->global === null, $iina->core !== null];
    $iina->preferences->set('color', 'blue');
    $iina->preferences->sync();
    $iina->event->on('iina.file-loaded', function (string $url) { $GLOBALS['pluginEvents'][] = $url; });
    $iina->event->on('mpv.pause.changed', function ($v) { $GLOBALS['pluginEvents'][] = ['pause', $v]; });
    $iina->menu->addItem($iina->menu->item('Say', fn() => $iina->core->osd('said'), ['keyBinding' => 'Meta+y']));
    $sub = $iina->menu->item('More');
    $sub->addSubMenuItem($iina->menu->item('Inner', fn($item) => $GLOBALS['pluginEvents'][] = $item->title));
    $iina->menu->addItem($sub);
    foreach (range(1, 4) as $n) { $iina->menu->addItem($iina->menu->item("Item $n", null, ['selected' => $n === 1, 'enabled' => $n !== 2])); }
    $iina->subtitle->registerProvider('subs', [
        'search' => fn() => [$iina->subtitle->item(['n' => 1], ['name' => 'One', 'left' => 'en', 'right' => '1 KB']), $iina->subtitle->item(['n' => 2])],
        'description' => fn($item) => ['name' => 'Two', 'left' => 'zh', 'right' => ''],
        'download' => function ($item) use ($iina) { $iina->file->write("@tmp/sub{$item->data['n']}.srt", "1\n00:00:00,000 --> 00:00:01,000\nHi\n\n"); return ["@tmp/sub{$item->data['n']}.srt"]; },
    ]);
    $GLOBALS['pluginIina'] = $iina;
};
PHP;
file_put_contents("$dir/io.test.a.iinaplugin/main.php", $main);
file_put_contents("$dir/io.test.b.iinaplugin/main.php", '<?php return function ($iina) { throw new RuntimeException("broken"); };');
Plugins::configure($dir, $settings);
[$pa, $pb] = Plugins::all();
Plugins::setEnabled($pa, true);
Plugins::setEnabled($pb, true);
$GLOBALS['pluginRan'] = []; $GLOBALS['pluginEvents'] = [];
$s = new MediaPlayerState(); $s->playerSettings = $settings;
$s->loadPlugins();
same([['red', true, true]], $GLOBALS['pluginRan'], 'its entry run: the preference\'s default, no global API of no globalEntry, a player\'s');
same(['blue', '{"color":"blue"}'], [$pa->preference('color'), file_get_contents("$dir/.preferences/io.test.a.json")], 'a preference set and synced');
same(2, count($s->plugins()), 'an instance of each plugin on');
check(array_filter(\MyPHP\Logger::entries(), fn(array $e) => str_contains($e['subsystem'], ' - Beta') && str_contains($e['message'] ?? '', 'broken')) !== [], 'what one throws logged under its subsystem');
/** @var Iina $iina */
$iina = $GLOBALS['pluginIina'];

// The Plugin menu: Manage Plugins…, under the plugin's name its first five items and More…, Reload All Plugins.
$menu = MediaPlayerMenus::plugin($s);
same(['Manage Plugins…', '', 'Alpha', 'Say', 'More', 'Item 1', 'Item 2', 'Item 3', 'More…', '', 'Reload All Plugins'], array_map(fn(MenuItem $m) => $m->label, $menu), 'the Plugin menu');
same(['Cmd+y', true, true], [$menu[3]->accelerator, $menu[5]->checked, $menu[6]->disabled], 'an item\'s key, check, enabled');
($menu[4]->submenu[0]->click)();
same('Inner', end($GLOBALS['pluginEvents']), 'a submenu\'s item, its action called with it');
($menu[3]->click)();
same(['said', 'From plugin Alpha'], [$s->message[0], $s->message[1]], 'core.osd: the message, from the plugin');
$iina->menu->items()[0]->keyBinding = 'Meta+s';
$conflicted = MediaPlayerMenus::plugin($s);
same(['Conflicting key shortcuts…', ['Alpha: Meta+s'], ''], [$conflicted[0]->label, array_map(fn(MenuItem $m) => $m->label, $conflicted[0]->submenu), $conflicted[4]->accelerator], 'a key a binding has: left off, listed');
$iina->menu->items()[0]->keyBinding = 'Meta+y';

// mpv: a property MyPHP gives (none of a player without a film); a command beyond IINA's safe ones of the file system's permission; hooks none.
same([null, false], [$iina->mpv->getString('pause'), $iina->mpv->getFlag('pause')], 'no film: no property');
$iina->mpv->set('speed', 2);
$iina->mpv->command('seek', ['10']);
same([['action' => ['set', 'speed', '2'], 'iina' => false], ['action' => ['seek', '10'], 'iina' => false]], $s->performed(), 'set and command as a key binding\'s');
$threw = '';
try { $iina->mpv->addHook('on_load', 50, fn() => null); } catch (PluginError $e) { $threw = $e->getMessage(); }
check(str_contains($threw, 'without mpv'), 'addHook: none');

// The file system by IINA's paths: @tmp and @data its own; a file overwritten only of its own; another path of its permission.
$iina->file->write('@data/a.txt', 'one');
$iina->file->write('@data/a.txt', 'two');
same(['two', true, [['filename' => 'a.txt', 'path' => '/a.txt', 'isDir' => false]]], [$iina->file->read('@data/a.txt'), $iina->file->exists('@data/a.txt'), $iina->file->list('@data/')], '@data: written, read, listed');
$outside = "$root/outside.txt";
file_put_contents($outside, 'x');
$threw = '';
try { $iina->file->write($outside, 'y'); } catch (PluginError $e) { $threw = $e->getMessage(); }
check(str_contains($threw, 'Cannot overwrite'), 'another\'s file: not overwritten');
$threw = '';
try { $iina->file->write('@data/../../escape.txt', 'y'); } catch (PluginError $e) { $threw = $e->getMessage(); }
check(str_contains($threw, 'inside the @data directory'), 'out of @data: refused');
$iina->file->delete('@data/a.txt');
check(!$iina->file->exists('@data/a.txt'), 'deleted');

// utils.exec: a program on the PATH, its output and status; one not found.
$r = $iina->utils->exec('sh', ['-c', 'echo out; echo err >&2; exit 3']);
same([3, "out\n", "err\n"], [$r['status'], $r['stdout'], $r['stderr']], 'exec: its status and output');
$threw = 0;
try { $iina->utils->exec('no-such-program-x', []); } catch (PluginError $e) { $threw = $e->getCode(); }
same(\MyPHP\PlayerPlugins\UtilsApi::ERROR_BINARY_NOT_FOUND, $threw, 'a program not found');

// http: of the network's permission and the plugin's hosts.
$threw = '';
try { $iina->http->get('https://evil.com/'); } catch (PluginError $e) { $threw = $e->getMessage(); }
check(str_contains($threw, 'is not allowed'), 'a host not allowed');
$v = [1, 'a', [true, 2.5], ['k' => null]];
same($v, XmlRpc::decode(simplexml_load_string(XmlRpc::encode($v))), 'XML-RPC\'s values, both ways');

// Events: listened to, observed properties' changes; gone with the instance.
$s->events()->emit('iina.file-loaded', 'file:///x.mkv');
same('file:///x.mkv', end($GLOBALS['pluginEvents']), 'iina.file-loaded heard');
$values = ['pause' => 'no'];
$s->events()->poll(fn(string $n) => $values[$n] ?? null);
$values['pause'] = 'yes';
$s->events()->poll(fn(string $n) => $values[$n] ?? null);
$s->events()->poll(fn(string $n) => $values[$n] ?? null);
same([['pause', false], ['pause', true]], array_values(array_filter($GLOBALS['pluginEvents'], 'is_array')), 'mpv.pause.changed: once observed, then as it changes');

// Subtitles: the plugins' providers among the sources; found and described.
same(['plugin:io.test.a:subs' => 'Subs — Alpha'], MediaPlayerState::pluginProviders(), 'a plugin\'s provider: "name — plugin"');
same('Subs', MediaPlayerState::providerName('plugin:io.test.a:subs'), 'its name');
$items = $iina->subtitle->search('subs');
same([['name' => 'One', 'left' => 'en', 'right' => '1 KB'], ['name' => 'Two', 'left' => 'zh', 'right' => '']], array_map(fn($i) => $i->desc, $items), 'found; one of no desc described by description()');
same(["{$pa->tmpDir()}/sub2.srt"], $iina->subtitle->download('subs', $items[1]), 'downloaded: its files, as paths');

// Turned off: its instance unloaded, its listeners gone, its provider not a source.
Plugins::setEnabled($pa, false);
same([1, []], [count($s->plugins()), MediaPlayerState::pluginProviders()], 'off: its instance and provider gone');
$n = count($GLOBALS['pluginEvents']);
$s->events()->emit('iina.file-loaded', 'file:///y.mkv');
same($n, count($GLOBALS['pluginEvents']), 'its listeners gone');

// The global instance: its players made, messages both ways.
$plugin('io.test.g.iinaplugin', ['identifier' => 'io.test.g', 'name' => 'Gamma', 'globalEntry' => 'global.php'], <<<'PHP'
<?php
return function ($iina) {
    $iina->global->onMessage('hi', function ($data) use ($iina) { $GLOBALS['childGot'][] = [$data, $iina->global->getLabel()]; $iina->global->postMessage('back', $data . '!'); });
};
PHP, ['global.php' => <<<'PHP'
<?php
return function ($iina) {
    $GLOBALS['globalApi'] = $iina->global;
    $iina->global->onMessage('back', function ($data, $from) { $GLOBALS['globalGot'][] = [$data, $from]; });
};
PHP]);
Plugins::reloadAll();
$pg = Plugins::byId('io.test.g');
Plugins::setEnabled($pg, true);
$made = [];
Plugins::$newPlayer = function () use (&$made, $settings): MediaPlayerState { $m = new MediaPlayerState(); $m->playerSettings = $settings; return $made[] = $m; };
$GLOBALS['childGot'] = []; $GLOBALS['globalGot'] = [];
$id = $GLOBALS['globalApi']->createPlayerInstance(['label' => 'side']);
same([1, ['io.test.g'], '1-io.test.g'], [$id, array_map(fn($i) => $i->plugin->identifier, $made[0]->plugins()), $made[0]->managedLabel], 'a player made: its own plugin alone loaded, its label');
$GLOBALS['globalApi']->postMessage(1, 'hi', 'yo');
same([[['yo', 'side']], [['yo!', '1-io.test.g']]], [$GLOBALS['childGot'], $GLOBALS['globalGot']], 'a message to its player\'s instance, and one back');

// A package: installed (its permissions agreed to), turned on; again, replacing it where asked.
$pkg = "$root/pkg";
@mkdir($pkg, 0777, true);
file_put_contents("$pkg/Info.json", json_encode(['name' => 'Delta', 'author' => ['name' => 'A'], 'identifier' => 'io.test.d', 'version' => '2.0', 'entry' => 'main.php', 'permissions' => ['show-alert']]));
file_put_contents("$pkg/main.php", '<?php return function ($iina) {};');
exec('cd ' . escapeshellarg($pkg) . ' && zip -q -r ' . escapeshellarg("$root/delta.iinaplgz") . ' .');
$asked = [];
$agree = function (Plugin $p) use (&$asked): bool { $asked[] = $p->permissions; return true; };
same('installed', Plugins::install(Plugins::unpack("$root/delta.iinaplgz"), $agree, fn() => false), 'installed');
$d = Plugins::byId('io.test.d');
same([[['show-alert']], "$dir/io.test.d.iinaplugin", true], [$asked, $d->root, $d->enabled()], 'its permissions agreed to, in its folder, on');
same('cancelled', Plugins::install(Plugins::unpack("$root/delta.iinaplgz"), $agree, fn() => false), 'again, not replaced');
same('reinstalled', Plugins::install(Plugins::unpack("$root/delta.iinaplgz"), $agree, fn() => true), 'replaced');
same([1, []], [count(array_filter(Plugins::all(), fn(Plugin $p) => $p->identifier === 'io.test.d')), glob("$dir/.temp.*")], 'once, no temporary folder left');
$threw = '';
try { Plugins::unpack("$root/nothing.iinaplgz"); } catch (\MyPHP\PlayerPlugins\PluginInstallError $e) { $threw = $e->getMessage(); }
same('Cannot find the package file.', $threw, 'no package: IINA\'s error');
Plugins::remove(Plugins::byId('io.test.d'));
check(Plugins::byId('io.test.d') === null && !is_dir("$dir/io.test.d.iinaplugin"), 'uninstalled: gone from the list and the disk');

if (PHP_OS_FAMILY === 'Darwin') {
    // Settings > Plugins: the plugins' rows, a switch turning one off.
    $view = new Headless(fn(Context $c) => MediaPlayerSettings::build($c, $s), 600, 480);
    MediaPlayerSettings::$showPage = 'plugins';
    $view->frame();
    check($view->byText('Install Local Package…') !== null && $view->byText('Alpha') !== null && $view->byText('Gamma') !== null && $view->byText('The first') !== null, 'the page: install, the plugins');
    $view->clickOn($view->byLabel('Gamma'));
    check(!Plugins::byId('io.test.g')->enabled(), 'its switch: off');

    // A player's: its events as a film opens and seeks; a provider's subtitles found, chosen, loaded; a timer.
    Plugins::setEnabled($pa, true);
    Player::$autoLoad = [0, '', '', false];
    $GLOBALS['pluginEvents'] = [];
    $f = new MediaPlayerState(); $f->playerSettings = $settings;
    $f->open(__DIR__ . '/testdata/clip.mkv');
    same('file://' . __DIR__ . '/testdata/clip.mkv', $GLOBALS['pluginEvents'][0] ?? null, 'iina.file-loaded as it opens');
    $fi = $GLOBALS['pluginIina'];
    same([false, 1, 160], [$fi->core->status->paused, $fi->core->video->id, $fi->core->status->videoWidth], 'core.status, core.video');
    $f->searchOnlineSubtitles('plugin:io.test.a:subs');
    same(['One', 'Two'], array_column($f->onlineSubtitles ?? [], 'name'), 'Find Online Subtitles from the plugin: its two to choose from');
    $f->downloadOnlineSubtitles([0]);
    same('sub1.srt', $f->player->subtitleTrack()?->name, 'the one chosen downloaded by the plugin and shown');
    $ticked = 0;
    $fi->setTimeout(function () use (&$ticked) { $ticked++; }, 10);
    for ($waited = 0; $ticked === 0 && $waited < 20; $waited++) { // the run loop turning, as the app's
        \MyPHP\Native\ObjC::msg('v:p', \MyPHP\Native\ObjC::msg('p:', \MyPHP\Native\ObjC::cls('NSRunLoop'), 'currentRunLoop'), 'runUntilDate:', \MyPHP\Native\ObjC::msg('p:d', \MyPHP\Native\ObjC::cls('NSDate'), 'dateWithTimeIntervalSinceNow:', 0.05));
    }
    same(1, $ticked, 'a timer');
}

Plugins::$newPlayer = null;
Plugins::stop();
same([false, []], [Plugins::active(), Plugins::players()], 'stopped: none run');
exec('rm -rf ' . escapeshellarg($root));
