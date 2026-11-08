<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use Closure;
use MyPHP\App;
use MyPHP\Logger;
use MyPHP\Media\PlayerSettings;
use MyPHP\PathName;
use MyPHP\UI\MediaPlayerState;
use RuntimeException;

/**
 * The player's plugins, as IINA's JavascriptPlugin.plugins and its
 * PluginManager: those in the plugins' folder (dir()), in the order the
 * user put them, the ones on running for each player (an Instance of its
 * entry for each MediaPlayerState, one of its globalEntry for the app);
 * installed from a package (.iinaplgz, a zip of the folder), its
 * permissions agreed to first, reinstalled in place of one of the same
 * identifier; turned on and off; removed; reloaded all at once.
 */
final class Plugins
{
    /** The log's subsystem of the plugins' loading. */
    public const LOG = 'plugins';

    private static ?string $dir = null;
    private static bool $active = false;
    private static ?PlayerSettings $settings = null;
    /** @var ?list<Plugin> */
    private static ?array $plugins = null;
    /** @var \WeakMap<MediaPlayerState, true> the players whose plugins run */
    private static ?\WeakMap $players = null;
    /** Opens a new player's window and gives its state (IINA's PlayerCore for global.createPlayerInstance), the app's; null where it has none. @var ?Closure(): MediaPlayerState */
    public static ?Closure $newPlayer = null;
    /** @var list<Closure(): void> what to tell as the list changes (IINA's iinaPluginChanged) */
    private static array $listeners = [];

    /** Where the plugins are (IINA's pluginsURL): "plugins" of the app's user data, unless set (a test's). */
    public static function dir(): string
    {
        $d = self::$dir ??= App::path(PathName::UserData) . '/plugins';
        if (!is_dir($d)) { @mkdir($d, 0777, true); }
        return $d;
    }

    /**
     * Runs the plugins (the players' load theirs from now on): those of
     * $dir (dir()'s by default), which are on and in what order kept in
     * $settings (the app's by default); read again.
     */
    public static function configure(?string $dir = null, ?PlayerSettings $settings = null): void
    {
        self::$active = true;
        self::$dir = $dir;
        self::$settings = $settings;
        self::unloadAll();
        self::$plugins = null;
    }

    /** Stops running plugins: all unloaded, the players' and the global ones; none load till configure() again. */
    public static function stop(): void
    {
        self::unloadAll();
        self::$plugins = null;
        self::$players = null;
        self::$active = false;
        self::changed();
    }

    /** Whether the app runs plugins (configure()). */
    public static function active(): bool
    {
        return self::$active;
    }

    /** @internal Plugin: the settings that keep which are on and their order. */
    public static function settings(): PlayerSettings
    {
        return self::$settings ?? PlayerSettings::default();
    }

    /** The plugins installed, in their order. @return list<Plugin> */
    public static function all(): array
    {
        return self::$plugins ??= self::read();
    }

    /** Has $fn called as the plugins change. */
    public static function onChange(Closure $fn): void
    {
        self::$listeners[] = $fn;
    }

    /** Bumped as the plugins or their menus change: the menus are built again for another (MediaPlayerMenus::key). */
    public static int $revision = 0;

    private static function changed(): void
    {
        self::$revision++;
        foreach (self::$listeners as $fn) { $fn(); }
    }

    /** @internal MenuApi: a plugin's menu items changed. */
    public static function menuChanged(): void
    {
        self::changed();
    }

    /** @return list<Plugin> those of the folder, in the order kept, duplicates of an identifier told (IINA's loadPlugins) */
    private static function read(): array
    {
        $order = array_flip(array_values(array_unique(self::settings()->get('pluginOrder'))));
        $ids = [];
        $out = [];
        foreach (scandir(self::dir()) ?: [] as $f) {
            if ($f[0] === '.') { continue; }
            $path = self::dir() . "/$f";
            $dev = str_ends_with($f, '.iinaplugin-dev');
            if (!$dev && !(str_ends_with($f, '.iinaplugin') && is_dir($path))) { continue; }
            $p = Plugin::load($dev ? (string)realpath($path) : $path, $dev);
            if ($p === null) { continue; }
            if (isset($ids[$p->identifier])) {
                self::alert(self::t('iinaAlertDuplicatedPluginId', ['id' => $p->identifier]));
                $p->identifier .= '.' . bin2hex(random_bytes(8));
            }
            $ids[$p->identifier] = true;
            $out[] = self::refreshBuiltIn($p);
        }
        usort($out, fn(Plugin $a, Plugin $b): int => ($order[$a->identifier] ?? PHP_INT_MAX) <=> ($order[$b->identifier] ?? PHP_INT_MAX));
        self::saveOrder($out);
        return $out;
    }

    /** Keeps the order of $plugins (IINA's savePluginOrder). @param list<Plugin> $plugins */
    private static function saveOrder(array $plugins): void
    {
        self::settings()->set('pluginOrder', array_map(fn(Plugin $p) => $p->identifier, $plugins));
    }

    /** Moves the plugin at $from to before the one at $to (the list's drag, IINA's acceptDrop): the players' in that order. */
    public static function move(int $from, int $to): void
    {
        $all = self::all();
        if (!isset($all[$from]) || $from === $to) { return; }
        $p = array_splice($all, $from, 1)[0];
        array_splice($all, $from < $to ? $to - 1 : $to, 0, [$p]);
        self::$plugins = $all;
        self::saveOrder($all);
        foreach (self::players() as $s) { $s->sortPlugins(); }
        self::changed();
    }

    /** The identifier's plugin, null for none. */
    public static function byId(string $id): ?Plugin
    {
        foreach (self::all() as $p) { if ($p->identifier === $id) { return $p; } }
        return null;
    }

    /**
     * Turns $p on or off (IINA's enabled): its subtitle providers with it,
     * its global instance, the players' instances of it.
     */
    public static function setEnabled(Plugin $p, bool $on): void
    {
        $ids = array_values(array_diff(self::settings()->get('pluginsEnabled'), [$p->identifier]));
        if ($on) { $ids[] = $p->identifier; }
        self::settings()->set('pluginsEnabled', $ids);
        self::reloadGlobal($p);
        foreach (self::players() as $s) { $s->reloadPlugin($p); }
        self::changed();
    }

    /** The plugin's global instance made, or gone as it is turned off ($forced: made again). */
    public static function reloadGlobal(Plugin $p, bool $forced = false): void
    {
        if ($p->globalEntryURL === null) { return; }
        if ($p->globalInstance !== null && (!$p->enabled() || $forced)) {
            $p->globalInstance->cleanUp();
            $p->globalInstance = null;
        }
        if ($p->globalInstance === null && $p->enabled()) {
            $p->globalInstance = new Instance($p, null);
        }
    }

    /** Runs the global instances of those on (IINA's loadGlobalInstances), once the app starts. */
    public static function loadGlobalInstances(): void
    {
        foreach (self::all() as $p) { self::reloadGlobal($p); }
    }

    /** @internal MediaPlayerState: a player whose plugins run. */
    public static function register(MediaPlayerState $s): void
    {
        self::$players ??= new \WeakMap();
        self::$players[$s] = true;
    }

    /** @internal MediaPlayerState: a player gone. */
    public static function unregister(MediaPlayerState $s): void
    {
        unset(self::$players[$s]);
    }

    /** The players whose plugins run. @return list<MediaPlayerState> */
    public static function players(): array
    {
        $out = [];
        foreach (self::$players ?? [] as $s => $_) { $out[] = $s; }
        return $out;
    }

    /** All the plugins read again and run again, each player's and the global ones (IINA's Reload All Plugins). */
    public static function reloadAll(): void
    {
        self::unloadAll();
        self::$plugins = null;
        self::loadGlobalInstances();
        foreach (self::players() as $s) { $s->loadPlugins(); $s->replayPluginEvents(); }
        self::changed();
    }

    private static function unloadAll(): void
    {
        foreach (self::players() as $s) { $s->clearPlugins(); }
        foreach (self::$plugins ?? [] as $p) { $p->globalInstance?->cleanUp(); $p->globalInstance = null; }
    }

    /**
     * Unpacks package $file (a zip of a plugin's folder, IINA's
     * create(fromPackageURL:)) into a temporary folder of the plugins':
     * the plugin, not yet among them (install() makes it one).
     */
    public static function unpack(string $file): Plugin
    {
        if (!is_file($file)) { throw new PluginInstallError(self::t('iinaPluginInstallErrorFileNotFound')); }
        Logger::debug("Installing plugin from file: $file", self::LOG);
        $tmp = self::dir() . '/.temp.' . bin2hex(random_bytes(8));
        $unzipped = "$tmp-1";
        @mkdir($tmp); @mkdir($unzipped);
        $out = self::run(['/usr/bin/unzip', '-q', $file, '-d', $unzipped]);
        if ($out !== null) {
            self::removeTree($unzipped); self::removeTree($tmp);
            throw new PluginInstallError(self::t('iinaPluginInstallErrorCannotUnpackage', ['output' => $out]));
        }
        foreach (scandir($unzipped) ?: [] as $f) {
            if ($f !== '.' && $f !== '..') { rename("$unzipped/$f", "$tmp/$f"); }
        }
        self::removeTree($unzipped);
        $p = Plugin::load($tmp);
        if ($p === null) {
            self::removeTree($tmp);
            throw new PluginInstallError(self::t('iinaPluginInstallErrorCannotLoad'));
        }
        return $p;
    }

    /**
     * The official plugins, built in: myphp's of PHP in place of IINA's of JavaScript (IINA's defaultPlugins, which it
     * downloads from their repositories), in the framework's plugins/ folder, which the app ships (myphp build); the
     * store installs them from there, and the app's next version brings their next. Their folders, by identifier.
     */
    public const BUILT_IN = ['io.myphp.ytdl' => 'online-media', 'io.myphp.user-script' => 'userscript', 'io.myphp.opensub' => 'opensub'];

    /** The folder of the built-in plugins: plugins/ beside the framework's src/, in the checkout or in the app. */
    public static function builtInDir(): string
    {
        return dirname(__DIR__, 2) . '/plugins';
    }

    /**
     * The built-in plugins as the store lists them: name and description in the app's language (their Info.json's
     * localized), identifier; those the app does not ship left out. @return list<array{name: string, url: string, id: string, desc: string, builtIn: true}>
     */
    public static function official(): array
    {
        $out = [];
        foreach (self::BUILT_IN as $id => $folder) {
            $p = is_dir(self::builtInDir() . "/$folder") ? Plugin::load(self::builtInDir() . "/$folder") : null;
            if ($p !== null) { $out[] = ['name' => $p->name, 'url' => '', 'id' => $id, 'desc' => (string)$p->desc, 'builtIn' => true]; }
        }
        return $out;
    }

    /** Built-in plugin $id copied into a temporary folder of the plugins': the plugin, not yet among them (install() makes it one). */
    public static function fromBuiltIn(string $id): Plugin
    {
        $from = self::builtInDir() . '/' . (self::BUILT_IN[$id] ?? throw new PluginInstallError("$id is no built-in plugin"));
        if (!is_dir($from)) { throw new PluginInstallError(self::t('iinaPluginInstallErrorFileNotFound')); }
        $tmp = self::dir() . '/.temp.' . bin2hex(random_bytes(8));
        self::copyTree($from, $tmp);
        $p = Plugin::load($tmp);
        if ($p === null) {
            self::removeTree($tmp);
            throw new PluginInstallError(self::t('iinaPluginInstallErrorCannotLoad'));
        }
        return $p;
    }

    /** $p as the app ships it, where it is a built-in plugin the app ships a newer version of: its folder replaced, its preferences and data kept (they are apart). */
    private static function refreshBuiltIn(Plugin $p): Plugin
    {
        $folder = self::BUILT_IN[$p->identifier] ?? null;
        if ($folder === null || $p->isExternal) { return $p; }
        $info = json_decode((string)@file_get_contents(self::builtInDir() . "/$folder/Info.json"), true);
        if (!is_string($info['version'] ?? null) || version_compare($info['version'], $p->version, '<=')) { return $p; }
        Logger::debug("Updating the built-in plugin {$p->identifier} from {$p->version} to {$info['version']}", self::LOG);
        $root = $p->root;
        self::removeTree($root);
        self::copyTree(self::builtInDir() . "/$folder", $root);
        return Plugin::load($root) ?? $p;
    }

    private static function copyTree(string $from, string $to): void
    {
        @mkdir($to, 0755, true);
        foreach (scandir($from) ?: [] as $f) {
            if ($f === '.' || $f === '..' || $f === '.DS_Store') { continue; }
            is_dir("$from/$f") ? self::copyTree("$from/$f", "$to/$f") : (copy("$from/$f", "$to/$f") || throw new PluginInstallError("cannot copy $from/$f"));
        }
    }
    /** Whether the Online Media plugin is on (IINA's hasYTDL): pages are then the plugin's to read, not yt-dlp's of the player. */
    public static function hasYTDL(): bool
    {
        foreach (self::$plugins ?? [] as $p) { if ($p->identifier === 'io.myphp.ytdl' && $p->enabled()) { return true; } }
        return false;
    }

    /** The community's plugins the store lists (IINA's plugins.json of its repository), myphp's repository's. */
    public const COMMUNITY_LIST = 'https://raw.githubusercontent.com/owner888/myphp/refs/heads/main/plugins.json';

    /** IINA's Regex.githubRepo and Regex.githubURL: "owner/repo", "https://github.com/owner/repo". */
    public const GITHUB_REPO = '~^([^/]+)/([^/]+)/?$~', GITHUB_URL = '~^https://github\.com/[\w-]+/[\w-]+/?$~';

    /** $s ("owner/repo" or a GitHub URL) as the repository's URL (IINA's standardizeGithubURL); throws for another. */
    public static function githubURL(string $s): string
    {
        if (preg_match('~^[\w-]+/[\w-]+$~', $s) === 1) { return "https://github.com/$s"; }
        if (preg_match(self::GITHUB_URL, $s) !== 1) { throw new PluginInstallError(self::t('iinaPluginInstallErrorInvalidUrl')); }
        return rtrim($s, '/');
    }

    /**
     * Downloads the plugin of GitHub repository $s (IINA's create(fromGitURL:)):
     * the .iinaplgz of its latest release where it has one, else its
     * source (its main branch's archive); unpacked, not yet among them.
     */
    public static function fromGitHub(string $s): Plugin
    {
        $url = self::githubURL($s);
        $path = (string)parse_url($url, PHP_URL_PATH);
        Logger::debug("Installing plugin from GitHub URL: $url", self::LOG);
        try {
            [$status, $body] = \MyPHP\Media\Https::request('GET', "https://api.github.com/repos$path/releases/latest", ['Accept' => 'application/vnd.github+json'], null, 'plugins');
            $json = $status === 200 ? json_decode($body, true) : null;
            foreach (is_array($json['assets'] ?? null) ? $json['assets'] : [] as $asset) {
                if (!str_ends_with((string)($asset['name'] ?? ''), '.iinaplgz') || !is_string($asset['browser_download_url'] ?? null)) { continue; }
                [$status, $package] = \MyPHP\Media\Https::request('GET', $asset['browser_download_url'], [], null, 'plugins');
                if ($status !== 200) { break; }
                $file = sys_get_temp_dir() . '/' . basename((string)$asset['name']);
                file_put_contents($file, $package);
                try { return self::unpack($file); } finally { @unlink($file); }
            }
        } catch (\RuntimeException $e) {
            Logger::debug("Cannot find an iinaplgz file in the latest release: {$e->getMessage()}", self::LOG);
        }
        Logger::debug('Installing from source', self::LOG);
        try {
            [$status, $zip] = \MyPHP\Media\Https::request('GET', "$url/archive/main.zip", [], null, 'plugins');
        } catch (\RuntimeException $e) {
            throw new PluginInstallError(self::t('iinaPluginInstallErrorCannotDownload', ['output' => $e->getMessage()]));
        }
        if ($status !== 200) { throw new PluginInstallError(self::t('iinaPluginInstallErrorCannotDownload', ['output' => "HTTP $status"])); }
        $tmp = self::dir() . '/.temp.' . bin2hex(random_bytes(8));
        $file = "$tmp.zip"; $unzipped = "$tmp-1";
        file_put_contents($file, $zip);
        @mkdir($tmp); @mkdir($unzipped);
        try {
            $out = self::run(['/usr/bin/unzip', '-q', $file, '-d', $unzipped]);
            $top = array_values(array_filter(array_map(fn(string $f) => "$unzipped/$f", array_diff(scandir($unzipped) ?: [], ['.', '..', '__MACOSX'])), 'is_dir')); // the archive's folder ("repo-main")
            if ($out !== null || count($top) !== 1) {
                self::removeTree($tmp);
                throw new PluginInstallError(self::t('iinaPluginInstallErrorCannotDownload', ['output' => $out ?? 'the archive holds no folder']));
            }
            foreach (scandir($top[0]) ?: [] as $f) {
                if ($f !== '.' && $f !== '..') { rename("$top[0]/$f", "$tmp/$f"); }
            }
        } finally {
            @unlink($file);
            self::removeTree($unzipped);
        }
        $p = Plugin::load($tmp);
        if ($p === null) {
            self::removeTree($tmp);
            throw new PluginInstallError(self::t('iinaPluginInstallErrorCannotLoad'));
        }
        if ($p->githubVersion === null || $p->githubRepo === null) {
            Logger::warn("The plugin {$p->name} doesn't contain a ghVersion field or its ghRepo doesn't match the current requested URL $url.", self::LOG);
        }
        return $p;
    }

    /** The version of $p on GitHub newer than its (IINA's checkNewVersion: its repository's Info.json's ghVersion greater), null for none or one not of GitHub; throws where it cannot tell. */
    public static function newVersion(Plugin $p): ?string
    {
        if ($p->githubVersion === null || $p->githubRepo === null) { return null; }
        try {
            [$status, $body] = \MyPHP\Media\Https::request('GET', "https://raw.githubusercontent.com/{$p->githubRepo}/main/Info.json", [], null, 'plugins');
        } catch (\RuntimeException $e) {
            throw new PluginInstallError(self::t('iinaPluginInstallErrorCannotDownload', ['output' => $e->getMessage()]));
        }
        $json = $status === 200 ? json_decode($body, true) : null;
        if (!is_int($json['ghVersion'] ?? null) || !is_string($json['version'] ?? null)) {
            throw new PluginInstallError(self::t('iinaPluginInstallErrorCannotDownload', ['output' => "HTTP $status"]));
        }
        return $json['ghVersion'] > $p->githubVersion ? $json['version'] : null;
    }

    /** $p as downloaded again from its repository (IINA's updated()): of its identifier, else it throws; null for one not of GitHub. */
    public static function downloadUpdate(Plugin $p): ?Plugin
    {
        $url = $p->githubURL();
        if ($url === null) { return null; }
        $new = self::fromGitHub($url);
        if ($new->identifier !== $p->identifier) {
            Logger::error("The updated plugin has an identifier {$new->identifier}, which doesn't match the current one ({$p->identifier})", self::LOG);
            self::removeTree($new->root);
            throw new PluginInstallError(self::t('iinaPluginInstallErrorCannotLoad'));
        }
        return $new;
    }

    /**
     * Puts $new, downloaded, in place of $old (IINA's PluginManager.update
     * once agreed): where it asks for more permissions or other hosts,
     * those agreed to ($agree, of the new and the old); its instances run
     * anew. Whether it went in.
     * @param Closure(Plugin, Plugin): bool $agree
     */
    public static function replace(Plugin $old, Plugin $new, Closure $agree): bool
    {
        $same = array_diff($new->permissions, $old->permissions) === [] && $new->domainList === $old->domainList;
        if (!$same && !$agree($new, $old)) {
            self::removeTree($new->root);
            return false;
        }
        $on = $old->enabled();
        $all = self::all();
        $at = array_search($old, $all, true);
        self::remove($old);
        self::normalizePath($new);
        $all = self::all();
        array_splice($all, $at === false ? count($all) : (int)$at, 0, [$new]);
        self::$plugins = $all;
        self::saveOrder($all);
        if ($on) { self::setEnabled($new, true); }
        self::changed();
        return true;
    }

    /** Runs $argv: null if it went well, else what it wrote. @param list<string> $argv */
    private static function run(array $argv): ?string
    {
        $proc = proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if ($proc === false) { return "cannot run {$argv[0]}"; }
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return proc_close($proc) === 0 ? null : $out;
    }

    /**
     * Installs plugin $p unpacked (IINA's PluginManager.install): its
     * permissions agreed to ($agree, of them), one of its identifier
     * replaced where $reinstall says so; moved to "<id>.iinaplugin" and
     * turned on. 'installed', 'reinstalled' or 'cancelled'.
     * @param Closure(Plugin): bool $agree @param Closure(Plugin): bool $reinstall
     */
    public static function install(Plugin $p, Closure $agree, Closure $reinstall): string
    {
        if ($p->permissions !== [] && !$agree($p)) {
            self::removeTree($p->root);
            return 'cancelled';
        }
        $all = self::all();
        foreach ($all as $i => $old) {
            if ($old->identifier !== $p->identifier) { continue; }
            if (!$reinstall($p)) {
                self::removeTree($p->root);
                return 'cancelled';
            }
            self::setEnabled($old, false);
            self::remove($old);
            self::normalizePath($p);
            $all = self::all();
            array_splice($all, $i, 0, [$p]);
            self::$plugins = $all;
            self::saveOrder($all);
            self::setEnabled($p, true);
            return 'reinstalled';
        }
        self::normalizePath($p);
        self::$plugins = [...$all, $p];
        self::saveOrder(self::$plugins);
        self::setEnabled($p, true);
        return 'installed';
    }

    /** Moves $p's folder to "<id>.iinaplugin", "<id>-2.iinaplugin" where that is taken (IINA's normalizePath). */
    private static function normalizePath(Plugin $p): void
    {
        $dest = self::dir() . "/{$p->identifier}.iinaplugin";
        for ($i = 2; file_exists($dest); $i++) { $dest = self::dir() . "/{$p->identifier}-$i.iinaplugin"; }
        if (!rename($p->root, $dest)) { throw new PluginInstallError("cannot move {$p->root} to $dest"); }
        $old = $p->root;
        $p->root = $dest;
        $re = fn(?string $f): ?string => $f === null || !str_starts_with($f, "$old/") ? $f : $dest . substr($f, strlen($old));
        $p->entryURL = (string)$re($p->entryURL);
        $p->globalEntryURL = $re($p->globalEntryURL);
        $p->preferencesPageURL = $re($p->preferencesPageURL);
        $p->helpPageURL = $re($p->helpPageURL);
    }

    /** Uninstalls $p: off, gone from the list and the disk (a link's folder left, the link gone). */
    public static function remove(Plugin $p): void
    {
        if ($p->enabled()) { self::setEnabled($p, false); }
        self::$plugins = array_values(array_filter(self::all(), fn(Plugin $q) => $q !== $p));
        self::saveOrder(self::$plugins);
        if ($p->isExternal) {
            foreach (scandir(self::dir()) ?: [] as $f) {
                if (str_ends_with($f, '.iinaplugin-dev') && realpath(self::dir() . "/$f") === $p->root) { @unlink(self::dir() . "/$f"); }
            }
        } else {
            self::removeTree($p->root);
        }
        self::changed();
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) { @unlink($path); return; }
        if (!is_dir($path)) { return; }
        foreach (scandir($path) ?: [] as $f) {
            if ($f !== '.' && $f !== '..') { self::removeTree("$path/$f"); }
        }
        @rmdir($path);
    }

    /** IINA's string $key, in the app's locale. @param array<string, string|int> $args */
    public static function t(string $key, array $args = []): string
    {
        return \MyPHP\L10n::in(App::locale())->get($key, $args);
    }

    /** IINA's alert of $message (Utility.showAlert), where the app runs; the log's otherwise. */
    public static function alert(string $message): void
    {
        Logger::error($message, self::LOG);
        if (App::running()) {
            \MyPHP\Dialog::message(new \MyPHP\MessageOptions(parent: \MyPHP\Window::focused(), type: 'warning', message: $message));
        }
    }
}
