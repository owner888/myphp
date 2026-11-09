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
    foreach ($files as $f => $text) { @mkdir(dirname("$d/$f"), 0777, true); file_put_contents("$d/$f", $text); }
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

// The Plugin menu: Manage Plugins…, under the plugin's name its first five items and More…, Developer Tool, Reload All Plugins.
$menu = MediaPlayerMenus::plugin($s);
same(['Manage Plugins…', 'Show Plugins Panel', '', 'Alpha', 'Say', 'More', 'Item 1', 'Item 2', 'Item 3', 'More…', '', 'Developer Tool', 'Reload All Plugins'], array_map(fn(MenuItem $m) => $m->label, $menu), 'the Plugin menu');
same(['Alpha', 'Beta'], array_map(fn(MenuItem $m) => $m->label, $find($menu, 'iina.developerTool')->submenu), 'Developer Tool: each instance\'s');
same(['Cmd+y', true, true], [$menu[4]->accelerator, $menu[6]->checked, $menu[7]->disabled], 'an item\'s key, check, enabled');
($menu[1]->click)();
same(['plugins', 'Hide Plugins Panel'], [$s->sidebar, MediaPlayerMenus::plugin($s)[1]->label], 'Show Plugins Panel: the plugins\' sidebar, then Hide');
$s->sidebar = null;
($menu[5]->submenu[0]->click)();
same('Inner', end($GLOBALS['pluginEvents']), 'a submenu\'s item, its action called with it');
($menu[4]->click)();
same(['said', 'From plugin Alpha'], [$s->message[0], $s->message[1]], 'core.osd: the message, from the plugin');
$iina->menu->items()[0]->keyBinding = 'Meta+s';
$conflicted = MediaPlayerMenus::plugin($s);
same(['Conflicting key shortcuts…', ['Alpha: Meta+s'], ''], [$conflicted[0]->label, array_map(fn(MenuItem $m) => $m->label, $conflicted[0]->submenu), $conflicted[5]->accelerator], 'a key a binding has: left off, listed');
$iina->menu->items()[0]->keyBinding = 'Meta+y';

// The views: an overlay of the video-overlay permission, none before the window is there; a page's clickable parts alone taking the pointer.
$threw = '';
try { $iina->overlay->loadFile('overlay.html'); } catch (PluginError $e) { $threw = $e->getMessage(); }
check(str_contains($threw, 'must declare permission "video-overlay"'), 'overlay: of its permission');
$threw = '';
try { $iina->sidebar->loadFile('side.html'); } catch (PluginError $e) { $threw = $e->getMessage(); }
check(str_contains($threw, 'window is not available'), 'sidebar: once the window is there');
$web = (new \ReflectionClass(\MyPHP\WebView::class))->newInstanceWithoutConstructor();
$areas = [];
$hv = new Headless(function (Context $c) use ($web, &$areas): void {
    $c->column(0, 0, 1)->size(400, 300);
      $c->button('Under')->absolute()->at(0, 0)->size(400, 300);
      $c->webView($web)->absolute()->left(0)->top(0)->right(0)->bottom(0)->hitAreas($areas);
    $c->end();
}, 400, 300);
$hv->frame(); $hv->paint();
same(null, $hv->rt->webViewAt(200, 150), 'an overlay of nothing clickable: the pointer through it');
$areas = [[10, 10, 50, 20]];
$hv->frame(); $hv->paint();
check($hv->rt->webViewAt(20, 15) === $web && $hv->rt->webViewAt(200, 150) === null, 'its clickable part alone the page\'s');

// input: a key's listeners in its normalized name; the high before the player's handling, the low after; one returning true stops it.
$order = [];
$iina->input->onKeyDown('Shift+a', function (array $a) use (&$order) { $order[] = 'low'; return false; });
$iina->input->onKeyDown('A', function (array $a) use (&$order) { $order[] = 'high'; return false; }, \MyPHP\PlayerPlugins\InputApi::PRIORITY_HIGH);
$took = \MyPHP\PlayerPlugins\InputApi::handle($s, 'A', \MyPHP\PlayerPlugins\InputApi::KEY_DOWN, ['isRepeat' => false], function () use (&$order) { $order[] = 'player'; return true; });
same([['high', 'player'], true], [$order, $took], 'Shift+a is A: one listener, the later in its place, high before the player');
$order = [];
$iina->input->onKeyDown('A', function (array $a) use (&$order) { $order[] = 'high'; return true; }, 200);
$took = \MyPHP\PlayerPlugins\InputApi::handle($s, 'A', \MyPHP\PlayerPlugins\InputApi::KEY_DOWN, [], function () use (&$order) { $order[] = 'player'; return true; });
same([['high'], true], [$order, $took], 'returning true: the player handles it not');
$order = [];
$iina->input->onMouseUp($iina->input::MOUSE, function (array $a) use (&$order) { $order[] = $a['clickCount']; return false; });
$took = \MyPHP\PlayerPlugins\InputApi::handle($s, '*mouse', \MyPHP\PlayerPlugins\InputApi::MOUSE_UP, ['clickCount' => 2]);
same([[2], false], [$order, $took], 'a low one told after; nothing handled it: the click goes on');
$iina->input->onKeyDown('A', null); $iina->input->onMouseUp('*mouse', null);
same(false, \MyPHP\PlayerPlugins\InputApi::listened($s, 'A', \MyPHP\PlayerPlugins\InputApi::KEY_DOWN), 'null: none');
same(['Ctrl+Meta+SHARP', 'SPACE'], [$iina->input->normalizeKeyCode('Meta+Ctrl+#'), $iina->input->normalizeKeyCode('space')], 'normalizeKeyCode: mpv\'s one form');
$kb = $iina->input->getAllKeyBindings();
check(isset($kb['SPACE']) && $kb['SPACE']['action'] === 'cycle pause' && $kb['SPACE']['isIINACommand'] === false, 'getAllKeyBindings: by key, its action');

// The playlist's menu: an entry's items, IINA's; the plugins' builders' under Plugin, their actions the plugin's.
$s->playlist = new \MyPHP\Media\Playlist(['/films/a.mkv', 'https://example.com/b.m3u8']);
$built = []; $acted = [];
$iina->playlist->registerMenuItemBuilder(function (array $rows) use ($iina, &$built, &$acted) {
    $built[] = $rows;
    return [$iina->menu->item('Tag It', function () use (&$acted, $rows) { $acted[] = $rows; }), $iina->menu->separator()];
});
if (PHP_OS_FAMILY === 'Darwin') { // the list's symbols are the system's
    $pl = new Headless(function (Context $c) use ($s): void {
        $side = $c->column()->size(360, 400);
        \MyPHP\UI\MediaPlayer::playlistPane($c, $s, $side, 360, 400, 96, true);
        $c->end();
    }, 360, 400);
    $pl->frame();
    $pl->rightClick(150, 53 + 16 + 32);
    same(['b.m3u8', '-', '# Playback', 'Play Next', 'Remove', '-', '# Network Resources', 'Open in Browser', 'Copy URL', '-', 'Plugin', 'Tag It', '-', '-', '# Playlist', 'Add File…', 'Add URL…', 'Clear Playlist'],
        $pl->menuLabels(), 'a URL\'s: its title, playback, the network\'s, the plugin\'s, the playlist\'s; no Play in New Window where the app makes no windows');
    check($pl->chooseMenuItem('Tag It') && end($acted) === [1], 'a plugin\'s item: its action, of the entry');
    $pl->rightClick(150, 53 + 16);
    check(in_array('Move to Trash', $pl->menuLabels() ?? [], true) && in_array('Show in Finder', $pl->menuLabels() ?? [], true), 'a file\'s: its operations');
    $pl->closeMenu();
}
$iina->playlist->registerMenuItemBuilder(fn(array $rows) => []);
$s->playlist = null;

// ws: a server on a port, a connection's handshake, its message, the plugin's text back (binary, IINA's), a ping's pong, its close.
$wsLog = [];
$iina->ws->onStateUpdate(function (string $state) use (&$wsLog) { $wsLog[] = "state $state"; });
$iina->ws->onNewConnection(function (string $conn, array $info) use (&$wsLog) { $wsLog[] = 'new'; $GLOBALS['wsConn'] = $conn; });
$iina->ws->onConnectionStateUpdate(function (string $conn, string $state) use (&$wsLog) { $wsLog[] = "conn $state"; });
$iina->ws->onMessage(function (string $conn, \MyPHP\PlayerPlugins\WsMessage $m) use (&$wsLog) { $wsLog[] = 'message ' . $m->text(); });
$threw = '';
try { $iina->ws->startServer(); } catch (PluginError $e) { $threw = $e->getMessage(); }
same('ws.startServer: server not created', $threw, 'no server: none to start');
$probe = stream_socket_server('tcp://127.0.0.1:0'); $wsPort = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1); fclose($probe);
$iina->ws->createServer(['port' => $wsPort]);
$iina->ws->startServer();
$wsPoll = function () use ($iina) { (new \ReflectionProperty($iina->ws, 'server'))->getValue($iina->ws)->poll(); };
$client = stream_socket_client("tcp://127.0.0.1:$wsPort");
fwrite($client, "GET / HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n");
usleep(20000); $wsPoll();
$head = fread($client, 4096);
check(str_contains($head, '101 Switching Protocols') && str_contains($head, 's3pPLMBiTxaQ9kYGzzhZRbK+xOo='), 'the handshake: its accept key');
$mask = "\x01\x02\x03\x04"; $text = 'héllo';
fwrite($client, "\x81" . chr(0x80 | strlen($text)) . $mask . ($text ^ str_repeat($mask, 2)));
usleep(20000); $wsPoll();
same(['state ready', 'new', 'conn preparing', 'conn ready', 'message héllo'], $wsLog, 'its state, a connection come, its states, its message');
same('success', $iina->ws->sendText($GLOBALS['wsConn'], 'back'), 'text sent');
usleep(20000);
same("\x82\x04back", fread($client, 64), 'as a binary message, IINA\'s');
same('no_connection', $iina->ws->sendText('nobody', 'x'), 'to no connection: none');
fwrite($client, "\x89\x80" . $mask); usleep(20000); $wsPoll();
same("\x8a\x00", fread($client, 64), 'a ping: its pong');
fwrite($client, "\x88\x80" . $mask); usleep(20000); $wsPoll();
same('conn cancelled', end($wsLog), 'a close: the connection gone');
fclose($client);

// The developer tool: code run in the instance, $iina its Iina, its variables kept; an expression's value, statements' return; what it throws; the log as it comes.
$tool = \MyPHP\PlayerPlugins\DevTool::for($s->plugins()[0]);
$tool->run('$x = 40');
$tool->run('$x + 2');
$tool->run('if ($x > 1) { return $iina->preferences->get("color"); }');
$tool->run('throw new RuntimeException("nope")');
$tool->run('$iina->console->log("hello")');
$kinds = array_map(fn(array $e) => [$e['kind'], $e['text'] ?? $e['value'] ?? $e['message'] ?? null], $tool->entries);
same([['prompt', '$x = 40'], ['result', 40], ['prompt', '$x + 2'], ['result', 42], ['prompt', 'if ($x > 1) { return $iina->preferences->get("color"); }'], ['result', 'blue'],
    ['prompt', 'throw new RuntimeException("nope")'], ['exception', 'nope'], ['prompt', '$iina->console->log("hello")'], ['log', 'hello'], ['result', null]], $kinds, 'its entries');
same(['$iina->console->log("hello")', 'throw new RuntimeException("nope")'], array_slice($tool->history(), 0, 2), 'the code run, the last first');
same(['array', 'Array (2)', [['0', '1'], ['a', 'Array (0)']]], \MyPHP\PlayerPlugins\DevTool::describe([1, 'a' => []]), 'an array as IINA\'s console shows it');
$tool->clear();

// mpv: a property MyPHP gives (none of a player without a film); a command beyond IINA's safe ones of the file system's permission; hooks none.
same([null, false], [$iina->mpv->getString('pause'), $iina->mpv->getFlag('pause')], 'no film: no property');
$iina->mpv->set('speed', 2);
$iina->mpv->command('seek', ['10']);
same([['action' => ['set', 'speed', '2'], 'iina' => false], ['action' => ['seek', '10'], 'iina' => false]], $s->performed(), 'set and command as a key binding\'s');
$threw = '';
try { $iina->mpv->addHook('on_unload', 50, fn() => null); } catch (PluginError $e) { $threw = $e->getMessage(); }
check(str_contains($threw, 'without mpv'), 'addHook: none but of a film opening');
// The hooks of a film opening: on_load's of what opens (the URL in its place, its title, headers, the audio and subtitles added), its stream-open-filename while it runs.
$seen = [];
$iina->mpv->addHook('on_load', 10, function ($next) use ($iina, &$seen) {
    $seen[] = $iina->mpv->getString('stream-open-filename');
    $iina->mpv->set('stream-open-filename', 'https://cdn.example/v.mp4');
    $iina->mpv->set('file-local-options/force-media-title', 'A Video');
    $iina->mpv->set('file-local-options/user-agent', 'UA');
    $iina->mpv->set('file-local-options/http-header-fields', ['Referer: https://example.com/', 'Cookie: a=b']);
    $iina->mpv->command('audio-add', ['https://cdn.example/a.m4a', 'auto', 'medium']);
    $iina->mpv->command('sub-add', ['edl://!no_clip;!delay_open,media_type=sub,codec=webvtt;%26%https://cdn.example/en.vtt', 'auto', 'English', 'en']);
    $iina->mpv->command('sub-add', ['memory://WEBVTT', 'auto', 'x', 'x']);
    $next();
});
$o = (new \ReflectionMethod($s, 'runHooks'))->invoke($s, 'on_load', new \MyPHP\Media\Opening('https://example.com/watch?v=1', 'https://example.com/watch?v=1'));
same([['https://example.com/watch?v=1'], 'https://cdn.example/v.mp4', 'A Video', ['User-Agent' => 'UA', 'Referer' => 'https://example.com/', 'Cookie' => 'a=b'], ['https://cdn.example/a.m4a'],
    [['url' => 'https://cdn.example/en.vtt', 'name' => 'English', 'language' => 'en', 'ext' => 'vtt']], null],
    [$seen, $o->url, $o->title, $o->headers, $o->audio, $o->subtitles, $s->hooking], 'what the hook made of the film; none hooking after it');
$iina->mpv->addHook('on_preloaded', 10, fn($next) => $iina->mpv->set('chapter-list', [['time' => 0, 'title' => 'Intro'], ['time' => 61.5, 'title' => 'Main']]));
$o = (new \ReflectionMethod($s, 'runHooks'))->invoke($s, 'on_preloaded', new \MyPHP\Media\Opening('/f.mkv', '/f.mkv'));
same([['Intro', 0.0], ['Main', 61.5]], array_map(fn($c) => [$c->title, $c->start], $o->chapters), 'on_preloaded: the chapters');
(new \ReflectionProperty($s, 'hooks'))->setValue($s, []);

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
$r = PHP_OS_FAMILY === 'Windows' // no sh there: PHP, by its path
    ? $iina->utils->exec(PHP_BINARY, ['-r', 'echo "out\n"; fwrite(STDERR, "err\n"); exit(3);'])
    : $iina->utils->exec('sh', ['-c', 'echo out; echo err >&2; exit 3']);
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
// An update put in place of the plugin (Plugins::replace): more permissions agreed to, its place and its being on kept; refused, nothing changes.
file_put_contents("$pkg/Info.json", json_encode(['name' => 'Delta', 'author' => ['name' => 'A'], 'identifier' => 'io.test.d', 'version' => '3.0', 'entry' => 'main.php', 'permissions' => ['show-alert', 'show-osd']]));
@unlink("$root/delta3.iinaplgz"); exec('cd ' . escapeshellarg($pkg) . ' && zip -q -r ' . escapeshellarg("$root/delta3.iinaplgz") . ' .');
$old = Plugins::byId('io.test.d'); $at = array_search($old, Plugins::all(), true);
$offered = [];
check(!Plugins::replace($old, Plugins::unpack("$root/delta3.iinaplgz"), function (Plugin $new, Plugin $was) use (&$offered) { $offered[] = [$new->version, $was->version]; return false; }), 'more permissions refused: not updated');
same([[['3.0', '2.0']], '2.0', []], [$offered, Plugins::byId('io.test.d')->version, glob("$dir/.temp.*")], 'asked of the new and the old; the old kept, the new gone');
check(Plugins::replace($old, Plugins::unpack("$root/delta3.iinaplgz"), fn() => true), 'agreed: updated');
$new = Plugins::byId('io.test.d');
same(['3.0', $at, true, "$dir/io.test.d.iinaplugin"], [$new->version, array_search($new, Plugins::all(), true), $new->enabled(), $new->root], 'in its place, on, in its folder');
// GitHub: "owner/repo" or its URL; nothing else.
same(['https://github.com/iina/plugin-x', 'https://github.com/iina/plugin-x'], [Plugins::githubURL('iina/plugin-x'), Plugins::githubURL('https://github.com/iina/plugin-x/')], 'a repository\'s URL');
$threw = '';
try { Plugins::githubURL('https://gitlab.com/a/b'); } catch (\MyPHP\PlayerPlugins\PluginInstallError $e) { $threw = $e->getMessage(); }
same('The URL is invalid.', $threw, 'another: IINA\'s error');
same(null, Plugins::newVersion($new), 'one not of GitHub: no newer version looked for');
same(['Updated 3 days ago', 'Updated 1 hour ago', 'Updated on Jan 2, 2026'], [\MyPHP\UI\MediaPlayerPluginStore::updated(1000000, 1000000 + 3 * 86400 + 5), \MyPHP\UI\MediaPlayerPluginStore::updated(1000000, 1003600), \MyPHP\UI\MediaPlayerPluginStore::updated(gmmktime(12, 0, 0, 1, 2, 2026), gmmktime(12, 0, 0, 3, 2, 2026))], 'the store: when a repository was pushed to, as IINA tells it');
// IINA's plugins of JavaScript: not loaded.
same(null, Plugin::load($plugin('io.test.js.iinaplugin', ['identifier' => 'io.test.js', 'entry' => 'main.js'], '', ['main.js' => 'iina.console.log(1);'])), 'an entry of JavaScript: none');
array_map('unlink', glob("$dir/io.test.js.iinaplugin/*")); @rmdir("$dir/io.test.js.iinaplugin");
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

// A plugin's own messages (Info.json's i18n, myphp's): its code says them with $iina->strings in the app's language,
// plural forms and all, a key without a message as it is; its pages with iina.strings, of the same messages, and their
// elements of data-l10n said in it.
$pd = $plugin('io.test.l.iinaplugin', ['identifier' => 'io.test.l', 'name' => 'Lingo'], '<?php return function ($iina) {};', [
    'locales/en.json' => json_encode(['Opening media…' => 'Opening media…', 'files' => '{n, plural, one {# file} other {# files}}', 'hi' => "Hi, {name}! It's '{'ok'}'"]),
    'locales/zh-Hans.json' => json_encode(['Opening media…' => '正在打开媒体…', 'files' => '{n, plural, other {# 个文件}}', 'hi' => '你好，{name}！'], JSON_UNESCAPED_UNICODE),
]);
$lp = Plugin::load($pd);
$saved = \MyPHP\App::locale(); $langs = \MyPHP\App::languages();
\MyPHP\App::setLanguages('en', 'zh-Hans'); // the app's: a plugin says its messages in the language the app shows
\MyPHP\App::setLocale('zh-Hans');
$said = [$lp->string('Opening media…'), $lp->string('files', ['n' => 3]), $lp->string('Not translated {x}', ['x' => 1])];
$script = $lp->stringsScript();
\MyPHP\App::setLocale('en');
$saidEn = [$lp->string('files', ['n' => 1]), $lp->string('files', ['n' => 2])];
$scriptEn = $lp->stringsScript();
\MyPHP\App::setLanguages(...$langs);
\MyPHP\App::setLocale($saved);
same([['正在打开媒体…', '3 个文件', 'Not translated 1'], ['1 file', '2 files']], [$said, $saidEn], 'a plugin says its messages in the app\'s language');
if (\MyPHP\Native\Build\Cmd::look('node') === '') { echo "  iina.strings skipped: no node\n"; } else {
    $js = sys_get_temp_dir() . '/myphp-strings-' . getmypid() . '.js';
    file_put_contents($js, 'const els = [{ dataset: { l10n: "Opening media…" }, textContent: "" }];'
        . 'globalThis.window = globalThis; globalThis.document = { readyState: "complete", documentElement: {}, querySelectorAll: (q) => q === "[data-l10n]" ? els : [] };'
        . $script . ';const zh = [els[0].textContent, iina.strings.get("files", { n: 3 }), iina.strings.get("hi", { name: "Ada" }), document.documentElement.lang];'
        . 'delete window.iina;' . $scriptEn . ';console.log(JSON.stringify([zh, [iina.strings.get("files", { n: 1 }), iina.strings.get("files", { n: 2 }), iina.strings.get("hi", { name: "Ada" }), iina.strings.get("Other {x}", { x: "y" })]]));');
    $nodeOut = [];
    exec('node ' . escapeshellarg($js) . ' 2>&1', $nodeOut);
    unlink($js);
    same([['正在打开媒体…', '3 个文件', '你好，Ada！', 'zh-Hans'], ['1 file', '2 files', "Hi, Ada! It's {ok}", 'Other y']], json_decode(implode('', $nodeOut), true), 'its pages say them alike: ' . implode("\n", $nodeOut));
}

Plugins::$newPlayer = null;
Plugins::stop();
same([false, []], [Plugins::active(), Plugins::players()], 'stopped: none run');
exec('rm -rf ' . escapeshellarg($root));
