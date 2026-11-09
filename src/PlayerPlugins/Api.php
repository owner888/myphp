<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use MyPHP\Logger;
use MyPHP\UI\MediaPlayerState;

/**
 * What IINA's APIs of a plugin share (JavascriptAPI): its instance and
 * player, an error thrown into its code, its log, its permissions checked,
 * a URL it may open, IINA's paths ("@tmp/", "@data/", "@current/", "~/",
 * "@video/1", "@audio/1", "@sub/1").
 */
abstract class Api
{
    public function __construct(protected readonly Instance $instance) {}

    /** The player of the instance (none for a global one's: its APIs have none). */
    protected function player(): MediaPlayerState
    {
        return $this->instance->player ?? throw new PluginError('This API is only available in a player instance');
    }

    /** @return never */
    protected function fail(string $message): never
    {
        throw new PluginError($message);
    }

    protected function log(string $message, int $level = Logger::DEBUG): void
    {
        $this->instance->log($message, $level);
    }

    protected function permitted(string $permission): bool
    {
        return $this->instance->plugin->permitted($permission);
    }

    /** Throws unless the plugin declared $permission (IINA's whenPermitted). */
    protected function require(string $permission): void
    {
        if (!$this->permitted($permission)) {
            $this->fail("To call this API, the plugin must declare permission \"$permission\" in its Info.json.");
        }
    }

    /**
     * $url as the plugin may open it (IINA's shouldOpenURL): data: and
     * memory: as they are, the web's of the network's permission and its
     * allowed hosts, a path as parsePath gives it, others of the file
     * system's permission.
     */
    protected function shouldOpenURL(string $url): string
    {
        $this->log("Attempting to open URL: $url");
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) !== 1) { $scheme = ''; }
        switch ($scheme) {
            case 'data': case 'memory':
                return $url;
            case 'http': case 'https': case 'smb':
                $this->require(Plugin::NETWORK);
                if (!$this->instance->plugin->canAccess($url)) { $this->fail("URL $url is not allowed."); }
                return $url;
            case '': case 'file':
                return $this->parsePath($url, false)[0] ?? $this->fail("Cannot open $url");
            default:
                $this->require(Plugin::FILE_SYSTEM);
                return $url;
        }
    }

    /**
     * $path with IINA's names expanded (parsePath): "@tmp/" and "@data/"
     * the plugin's own folders (local), "@video/<id>", "@audio/<id>",
     * "@sub/<id>" a track's file; the rest of the file system's permission,
     * "@current/" the folder of the film playing, "~/" the home's; an
     * absolute path where $local. [path, whether it is the plugin's own].
     * @return array{0: string, 1: bool}
     */
    protected function parsePath(string $path, bool $absolute = true): array
    {
        $p = $this->instance->plugin;
        foreach (['tmp' => $p->tmpDir(...), 'data' => $p->dataDir(...)] as $name => $dir) {
            if (str_starts_with($path, "@$name/")) { return [$this->expand($path, $name, $dir()), true]; }
        }
        if ($this->instance->player !== null && preg_match('~^@(video|audio|sub)/~', $path, $m) === 1) {
            return [$this->trackPath($path, $m[1]), false];
        }
        $this->require(Plugin::FILE_SYSTEM);
        $abs = $path;
        if (str_starts_with($path, '@current/')) {
            $film = $this->instance->player?->player;
            if ($film === null || $film->remote()) { $this->fail('@current is unavailable when no file playing'); }
            $abs = $this->expand($path, 'current', dirname($film->path()), false);
        }
        if (str_starts_with($path, '~/')) { $abs = rtrim((string)getenv('HOME'), '/') . substr($path, 1); }
        if ($absolute && !\MyPHP\Native\Paths::isAbsolute($abs)) { $this->fail("The path should be an absolute path: $path"); }
        return [$abs, str_starts_with($abs, $p->dataDir()) || str_starts_with($abs, $p->tmpDir())];
    }

    private function expand(string $path, string $name, string $dir, bool $validate = true): string
    {
        $out = Plugin::normalize("$dir/" . substr($path, strlen($name) + 2));
        if ($validate && !str_starts_with($out, rtrim($dir, '/'))) {
            $this->fail("The path does not locate inside the @$name directory: \"$path\"");
        }
        return $out;
    }

    /** The file of the track "@<type>/<id>" (mpv's ids, 1 the first), an external one alone. */
    private function trackPath(string $path, string $type): string
    {
        $id = (int)basename($path);
        $p = $this->player()->player;
        $tracks = match ($type) { 'video' => [$p?->videoTrack()], 'audio' => $p?->audioTracks() ?? [], default => $p?->subtitleTracks() ?? [] };
        $t = array_values(array_filter($tracks))[$id - 1] ?? null;
        $file = $t !== null && $t->type === \MyPHP\Media\Track::SUBTITLE ? $p?->subtitlePath($t) : null; // the subtitle files loaded: MyPHP keeps no file of another track's
        return is_string($file) ? $file : $this->fail("Cannot find the file path of track $path. Perhaps it's an internal stream?");
    }
}
