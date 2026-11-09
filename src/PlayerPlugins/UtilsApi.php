<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use Closure;
use MyPHP\Dialog;
use MyPHP\FileFilter;
use MyPHP\MessageOptions;
use MyPHP\Native\CurlLoop;
use MyPHP\OpenDialogOptions;
use MyPHP\SecretNotFound;
use MyPHP\Secrets;
use MyPHP\Shell;
use MyPHP\Window;

/**
 * IINA's iina.utils: a program found and run (the file system's
 * permission), the user asked (a question, a text, a file), the Keychain
 * of the plugin's (its services "<id> - <service>"), a link or a file
 * opened, the languages the user prefers.
 */
final class UtilsApi extends Api
{
    /** exec()'s errors' codes: no such program, or it cannot be run. */
    public const ERROR_BINARY_NOT_FOUND = -1, ERROR_RUNTIME = -2;

    /** Whether program $file is found (on the PATH, or at its path): the file system's permission, false without. */
    public function fileInPath(string $file): bool
    {
        if (!$this->permitted(Plugin::FILE_SYSTEM) || $file === '') { return false; }
        if (self::which($file) !== null) { return true; }
        return file_exists($this->parsePath($file, false)[0]);
    }

    /** $path with IINA's names expanded (the file system's permission, null without). */
    public function resolvePath(string $path): ?string
    {
        return $this->permitted(Plugin::FILE_SYSTEM) ? $this->parsePath($path)[0] : null;
    }

    /** The program $name on the PATH (and Homebrew's), null for none. */
    private static function which(string $name): ?string
    {
        if (str_contains($name, '/')) { return null; }
        foreach ([...explode(':', (string)getenv('PATH')), '/opt/homebrew/bin', '/usr/local/bin'] as $dir) {
            if ($dir !== '' && is_file("$dir/$name") && is_executable("$dir/$name")) { return "$dir/$name"; }
        }
        return null;
    }

    /**
     * Runs program $file with $args (the file system's permission), in $cwd
     * where given: its output given to $stdoutHook and $stderrHook as it
     * comes, and once it ends, [status, stdout, stderr]. A program not
     * found throws a PluginError of code ERROR_BINARY_NOT_FOUND, one that
     * cannot be run ERROR_RUNTIME.
     * @param list<string> $args
     * @return ?array{status: int, stdout: string, stderr: string}
     */
    public function exec(string $file, array $args = [], ?string $cwd = null, ?Closure $stdoutHook = null, ?Closure $stderrHook = null): ?array
    {
        if (!$this->permitted(Plugin::FILE_SYSTEM)) { return null; }
        if (!str_contains($file, '/')) {
            $path = self::which($file) ?? throw new PluginError("Cannot find the binary $file", self::ERROR_BINARY_NOT_FOUND);
        } else {
            $path = \MyPHP\Native\Paths::isAbsolute($file) ? $file : $this->parsePath($file)[0];
            if (!file_exists($path)) { throw new PluginError("Cannot find the binary $file", self::ERROR_BINARY_NOT_FOUND); }
        }
        $p = $this->instance->plugin;
        if (!is_executable($path) && (str_starts_with($path, $p->dataDir()) || str_starts_with($path, $p->tmpDir())) && !@chmod($path, 0755)) { // the plugin's own, made executable, as IINA's
            throw new PluginError('The binary is not executable, and execute permission cannot be added', self::ERROR_RUNTIME);
        }
        $dir = $cwd !== null ? $this->parsePath($cwd)[0] : null;
        $this->log("Executing $path " . implode(' ', $args));
        $proc = @proc_open([$path, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, ['LC_ALL' => 'en_US.UTF-8', 'PATH' => (string)getenv('PATH'), 'HOME' => (string)getenv('HOME')]);
        if ($proc === false) { throw new PluginError("Execution failed reporting: cannot run $path", self::ERROR_RUNTIME); }
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $out = ['', ''];
        $status = -1;
        $read = function () use ($pipes, &$out, $stdoutHook, $stderrHook): void {
            foreach ([1 => $stdoutHook, 2 => $stderrHook] as $i => $hook) {
                $chunk = (string)stream_get_contents($pipes[$i]);
                if ($chunk === '') { continue; }
                $out[$i - 1] .= $chunk;
                if ($hook !== null) { $this->instance->call($hook, $chunk); }
            }
        };
        CurlLoop::waitUntil(function () use ($proc, $pipes, $read, &$status): bool {
            $read();
            $st = proc_get_status($proc);
            if ($st['running']) { return false; }
            $status = $st['exitcode'];
            return feof($pipes[1]) && feof($pipes[2]);
        });
        $read();
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($proc);
        return ['status' => $status >= 0 ? $status : $code, 'stdout' => $out[0], 'stderr' => $out[1]];
    }

    /** Asks $title, OK or Cancel: whether OK. */
    public function ask(string $title): bool
    {
        return Dialog::message(new MessageOptions(parent: Window::focused(), message: $title, buttons: [Plugins::t('ok'), Plugins::t('cancel')], cancelButton: 1))->button === 0;
    }

    /** Asks $title for a text: it, null where cancelled. */
    public function prompt(string $title): ?string
    {
        return Dialog::prompt(Window::focused(), $title, '');
    }

    /** A file chosen, titled $title; $options: chooseDir, allowedFileTypes (extensions). Null where cancelled. @param array<string, mixed> $options */
    public function chooseFile(string $title, array $options = []): ?string
    {
        $types = array_values(array_filter((array)($options['allowedFileTypes'] ?? []), 'is_string'));
        $paths = Dialog::open(new OpenDialogOptions(parent: Window::focused(), title: $title, directory: (bool)($options['chooseDir'] ?? false), filters: $types === [] ? [] : [new FileFilter($title, $types)]));
        return $paths[0] ?? null;
    }

    /** Keeps $password of $name in the Keychain, under the plugin's $service: whether it could. */
    public function keychainWrite(string $service, string $name, string $password): bool
    {
        if ($service === '') { return false; }
        try {
            Secrets::service("{$this->instance->plugin->identifier} - $service")->set($name, $password);
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    /** The password of $name kept under the plugin's $service, false for none. */
    public function keychainRead(string $service, string $name): string|false
    {
        if ($service === '') { return false; }
        try {
            return Secrets::service("{$this->instance->plugin->identifier} - $service")->get($name);
        } catch (SecretNotFound|\RuntimeException) {
            return false;
        }
    }

    /** Opens $url: a link of the web in the browser, a file in its app (its own, or of the file system's permission). Whether it did. */
    public function open(string $url): bool
    {
        if (preg_match('~^https?://~i', $url) === 1) {
            Shell::openExternal($url);
            return true;
        }
        try {
            [$path, $local] = $this->parsePath($url);
        } catch (PluginError) {
            $this->log('utils.open: path cannot be found', \MyPHP\Logger::ERROR);
            return false;
        }
        if (!$local && !$this->permitted(Plugin::FILE_SYSTEM)) { return false; }
        Shell::openPath($path);
        return true;
    }

    /** @return list<string> the languages the user prefers, the app's first */
    public function preferredLocalizations(): array
    {
        return array_values(array_unique([\MyPHP\App::locale(), ...\MyPHP\App::preferredLanguages()]));
    }
}
