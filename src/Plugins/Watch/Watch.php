<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Watch;

use MyPHP\Plugin;

/**
 * The watch plugin: registers the "plugin:watch" IPC service for embedded
 * WebView pages. Native PHP apps call `App::watchFiles()` directly.
 *
 *     App::use(Watch::new(new Options(roots: [
 *         'project' => new Root($projectDir, recursive: true),
 *     ])));
 */
final class Watch
{
    /** The plugin without roots, which lets pages watch nothing (mygo's watch.Plugin). */
    public static function plugin(): Plugin
    {
        return self::new(new Options);
    }

    /**
     * The plugin with $opts (mygo's watch.New). App::use() throws when a
     * root is not an absolute path to a directory or a file, after
     * resolving its symlinks, as macOS's /tmp is one.
     */
    public static function new(Options $opts): Plugin
    {
        $s = new Service($opts);
        // Its setup is private, out of the pages' reach.
        return new Plugin('watch', $s, \Closure::bind(fn() => $this->setup(), $s, Service::class));
    }
}
