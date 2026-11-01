<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use MyPHP\Logger;
use RuntimeException;

/**
 * A plugin of the player, as IINA's JavascriptPlugin, of PHP: a folder
 * "<id>.iinaplugin" in the plugins' folder (Plugins::dir), or a link
 * "<name>.iinaplugin-dev" to one elsewhere (a plugin being written, IINA's
 * external one), its Info.json IINA's: name, author {name, email, url},
 * identifier (reverse domain), version, entry (a PHP file returning a
 * function of an Iina, run for each player's window), globalEntry (one run
 * once for the app), description, localized {lang: {name, description,
 * sidebarTab: {name}}}, preferencesPage and helpPage (HTML), allowedDomains,
 * permissions, subtitleProviders [{id, name}], sidebarTab {name}, ghRepo
 * ("user/repo"), ghVersion, preferenceDefaults. Its preferences are kept
 * in ".preferences/<id>.json" (IINA's .plist), its data in ".data/<id>",
 * its temporary files in the system's temporary folder.
 */
final class Plugin
{
    /** IINA's permissions; those a user should think twice of (isDangerous) first. */
    public const NETWORK = 'network-request', FILE_SYSTEM = 'file-system', OSD = 'show-osd', ALERT = 'show-alert', OVERLAY = 'video-overlay';
    public const PERMISSIONS = [self::NETWORK, self::FILE_SYSTEM, self::OSD, self::ALERT, self::OVERLAY];

    public string $identifier;
    public readonly string $name, $authorName, $version, $entryPath;
    public readonly ?string $authorEmail, $authorURL, $desc, $globalEntryPath, $preferencesPage, $helpPage, $githubRepo, $sidebarTabName;
    public readonly ?int $githubVersion;
    /** @var list<string> */
    public readonly array $permissions;
    /** @var list<string> the hosts it may reach: a host, "*.host" for those under it, "*" for any */
    public readonly array $domainList;
    /** @var ?list<array<string, string>> */
    public readonly ?array $subProviders;
    /** @var array<string, mixed> */
    public readonly array $defaultPreferences;
    /** Whether it is a link to a folder elsewhere (IINA's isExternal: the DEV of the list). */
    public readonly bool $isExternal;
    public string $root, $entryURL;
    public ?string $globalEntryURL = null, $preferencesPageURL = null, $helpPageURL = null;
    /** The instance of its globalEntry while it is on, null for none. */
    public ?Instance $globalInstance = null;
    /** @var ?array<string, mixed> its preferences as set, read on first use */
    private ?array $preferences = null;

    /**
     * The plugin in folder $root (a link's target where $external), null
     * for a folder that is none: why logged, as IINA's.
     */
    public static function load(string $root, bool $external = false): ?self
    {
        Logger::debug("Loading plugin from $root", Plugins::LOG);
        if (!is_dir($root)) {
            Logger::warn('The plugin package doesn\'t exist.', Plugins::LOG);
            return null;
        }
        $json = is_file("$root/Info.json") ? json_decode((string)file_get_contents("$root/Info.json"), true) : null;
        if (!is_array($json)) {
            Logger::error('Cannot read plugin package content.', Plugins::LOG);
            return null;
        }
        try {
            return new self($root, $json, $external);
        } catch (RuntimeException $e) {
            Logger::error($e->getMessage(), Plugins::LOG);
            return null;
        }
    }

    /** @param array<string, mixed> $json */
    private function __construct(string $root, array $json, bool $external)
    {
        $author = $json['author'] ?? null;
        foreach (['name', 'identifier', 'version', 'entry'] as $k) {
            if (!is_string($json[$k] ?? null) || !is_array($author) || !is_string($author['name'] ?? null)) {
                throw new RuntimeException('Info.json must contain these keys: name, author, identifier, version and entry.');
            }
        }
        if (preg_match('/^([\w-]+\.)+[\w-]+$/', $json['identifier']) !== 1) {
            throw new RuntimeException("Plugin ID \"{$json['identifier']}\"should comply with the Reverse domain name notation");
        }
        $this->root = $root;
        $l10n = self::localization(is_array($json['localized'] ?? null) ? $json['localized'] : []);
        $this->name = is_string($l10n['name'] ?? null) ? $l10n['name'] : $json['name'];
        $this->version = $json['version'];
        $this->entryPath = $json['entry'];
        $this->globalEntryPath = is_string($json['globalEntry'] ?? null) ? $json['globalEntry'] : null;
        $this->authorName = $author['name'];
        $this->authorURL = is_string($author['url'] ?? null) ? $author['url'] : null;
        $this->authorEmail = is_string($author['email'] ?? null) ? $author['email'] : null;
        $this->identifier = $json['identifier'];
        $this->desc = is_string($l10n['description'] ?? null) ? $l10n['description'] : (is_string($json['description'] ?? null) ? $json['description'] : null);
        $this->preferencesPage = is_string($json['preferencesPage'] ?? null) ? $json['preferencesPage'] : null;
        $this->helpPage = is_string($json['helpPage'] ?? null) ? $json['helpPage'] : null;
        $this->domainList = array_values(array_filter((array)($json['allowedDomains'] ?? []), 'is_string'));
        $this->subProviders = is_array($json['subtitleProviders'] ?? null) ? array_values($json['subtitleProviders']) : null;
        $this->isExternal = $external;
        $tab = $json['sidebarTab'] ?? null;
        $this->sidebarTabName = is_array($tab) ? (is_string($l10n['sidebarTab']['name'] ?? null) ? $l10n['sidebarTab']['name'] : ($tab['name'] ?? null)) : null;
        $gh = $json['ghRepo'] ?? null;
        if ($gh !== null && (!is_string($gh) || preg_match('~^[\w-]+/[\w-]+$~', $gh) !== 1)) {
            throw new RuntimeException('Invalid ghRepo format');
        }
        $this->githubRepo = $gh;
        $this->githubVersion = is_int($json['ghVersion'] ?? null) ? $json['ghVersion'] : null;
        $permissions = [];
        foreach ((array)($json['permissions'] ?? []) as $p) {
            in_array($p, self::PERMISSIONS, true) ? $permissions[] = $p : Logger::warn("Unknown permission: $p", Plugins::LOG);
        }
        $this->permissions = array_values(array_unique($permissions));
        foreach (array_filter([$this->entryPath, $this->globalEntryPath]) as $entry) {
            if (strtolower(pathinfo($entry, PATHINFO_EXTENSION)) !== 'php') { throw new RuntimeException("The entry file $entry is not of PHP: myphp's plugins are"); } // IINA's of JavaScript do not run
        }
        $this->entryURL = self::resolve($this->entryPath, $root) ?? throw new RuntimeException("The entry file {$this->entryPath} doesn't exist");
        if ($this->globalEntryPath !== null) {
            $this->globalEntryURL = self::resolve($this->globalEntryPath, $root) ?? throw new RuntimeException("The entry file {$this->globalEntryPath} doesn't exist");
        }
        $this->preferencesPageURL = self::resolve($this->preferencesPage, $root);
        $this->helpPageURL = self::resolve($this->helpPage, $root, true);
        if (is_array($json['preferenceDefaults'] ?? null)) {
            $this->defaultPreferences = $json['preferenceDefaults'];
        } else {
            Logger::warn('Unable to read preferenceDefaults', Plugins::LOG);
            $this->defaultPreferences = [];
        }
        Logger::debug("Loaded plugin: {$this->name} {$this->version}", Plugins::LOG);
    }

    /** The strings of the language the app shows of $l10n (IINA's localized: "en" too, Info.json's own the rest). @param array<string, mixed> $l10n @return array<string, mixed> */
    private static function localization(array $l10n): array
    {
        if ($l10n === []) { return []; }
        foreach ([\MyPHP\App::locale(), ...\MyPHP\App::preferredLanguages()] as $lang) {
            foreach ([$lang, explode('-', $lang)[0]] as $tag) {
                if (is_array($l10n[$tag] ?? null)) { return $l10n[$tag]; }
                if ($tag === 'en') { return []; } // Info.json's own: English, as IINA takes it
            }
        }
        return [];
    }

    /** $path within $root, if it is a file there (a page of the web where $network), else null, why logged (IINA's resolvePath). */
    private static function resolve(?string $path, string $root, bool $network = false): ?string
    {
        if ($path === null) { return null; }
        if (preg_match('~^https?://~', $path) === 1) { return $network ? $path : null; }
        $full = self::normalize("$root/$path");
        if (!str_starts_with($full, rtrim($root, '/') . '/')) {
            Logger::error("The file path $path is invalid", Plugins::LOG);
            return null;
        }
        if (!file_exists($full)) {
            Logger::error("The file $path doesn't exist", Plugins::LOG);
            return null;
        }
        return $full;
    }

    /** $path with its "." and ".." taken out (URL.standardized). */
    public static function normalize(string $path): string
    {
        $out = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') { continue; }
            if ($part === '..') { array_pop($out); continue; }
            $out[] = $part;
        }
        return '/' . implode('/', $out);
    }

    /** Whether it is on (IINA's PluginEnabled.<id>, off till installed or turned on). */
    public function enabled(): bool
    {
        return in_array($this->identifier, Plugins::settings()->get('pluginsEnabled'), true);
    }

    /** Whether it may $permission (Info.json's permissions). */
    public function permitted(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /** Its GitHub page, null for none. */
    public function githubURL(): ?string
    {
        return $this->githubRepo === null ? null : "https://github.com/{$this->githubRepo}";
    }

    /** Preference $key: the one set, else its default, else null (told). */
    public function preference(string $key): mixed
    {
        $set = $this->preferences();
        return array_key_exists($key, $set) ? $set[$key] : ($this->defaultPreferences[$key] ?? null);
    }

    /** Whether preference $key is set or has a default. */
    public function hasPreference(string $key): bool
    {
        return array_key_exists($key, $this->preferences()) || array_key_exists($key, $this->defaultPreferences);
    }

    /** Sets preference $key, kept once synced (syncPreferences). */
    public function setPreference(string $key, mixed $value): void
    {
        $this->preferences();
        $this->preferences[$key] = $value;
    }

    /** @return array<string, mixed> */
    private function preferences(): array
    {
        if ($this->preferences === null) {
            $f = $this->preferencesFile();
            $this->preferences = is_file($f) && is_array($j = json_decode((string)file_get_contents($f), true)) ? $j : [];
        }
        return $this->preferences;
    }

    /** Writes its preferences to its file (IINA's syncPreferences). */
    public function syncPreferences(): void
    {
        $f = $this->preferencesFile();
        if (@file_put_contents($f, json_encode((object)$this->preferences(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)) === false) {
            Logger::error('Unable to write preferences file: ' . $f, Plugins::LOG);
        }
    }

    private function preferencesFile(): string
    {
        $dir = Plugins::dir() . '/.preferences';
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        return "$dir/{$this->identifier}.json";
    }

    /** Its folder of data (@data), made. */
    public function dataDir(): string
    {
        $dir = Plugins::dir() . "/.data/{$this->identifier}";
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        return $dir;
    }

    /** Its folder of temporary files (@tmp), made. */
    public function tmpDir(): string
    {
        $dir = rtrim(sys_get_temp_dir(), '/') . "/myphp-{$this->identifier}";
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        return $dir;
    }

    /**
     * Its permissions as the user is told them (IINA's localizedPermissions):
     * those of $only, else its own, the dangerous first, the network's
     * hosts under it ($newLine between them). @param ?list<string> $only
     * @return list<array{name: string, desc: string, isDangerous: bool}>
     */
    public function localizedPermissions(?array $only = null, string $newLine = "\n"): array
    {
        $list = $only ?? $this->permissions;
        $dangerous = fn(string $p): bool => $p === self::NETWORK || $p === self::FILE_SYSTEM;
        usort($list, fn(string $a, string $b): int => $dangerous($a) === $dangerous($b) ? strcmp($a, $b) : ($dangerous($a) ? -1 : 1));
        return array_map(function (string $p) use ($dangerous, $newLine): array {
            $key = 'iinaPermissions' . str_replace(' ', '', ucwords(str_replace('-', ' ', $p)));
            $desc = Plugins::t("{$key}Desc");
            if ($p === self::NETWORK) {
                $desc .= $newLine . '- ' . (in_array('*', $this->domainList, true) ? Plugins::t("{$key}AnySite") : implode("$newLine- ", $this->domainList));
            }
            return ['name' => Plugins::t("{$key}Name"), 'desc' => $desc, 'isDangerous' => $dangerous($p)];
        }, $list);
    }

    /** Whether it may reach $url (IINA's canAccess): its host one of allowedDomains'. */
    public function canAccess(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') { return false; }
        foreach ($this->domainList as $d) {
            if ($d === '*' || $d === $host || (str_starts_with($d, '*.') && str_ends_with($host, substr($d, 1)))) { return true; }
        }
        return false;
    }
}
