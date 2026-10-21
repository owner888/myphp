<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Watch;

use MyPHP\Plugin;

/**
 * The watch plugin (mygo's plugins/watch): pages watch for changes to the
 * files and directories the app names, by those names, with the watch of
 * its TypeScript client (client/index.ts, @myphp-plugins/watch). PHP code
 * and native UI call App::watchFiles() directly.
 *
 *     App::use(Watch::new(new Options(roots: [
 *         'project' => new Root($projectDir, recursive: true),
 *     ])));
 *
 *     // the page
 *     import { watch } from "@myphp-plugins/watch";
 *     const watcher = await watch("project", { recursive: true });
 *     for await (const events of watcher) for (const e of events) console.log(e.op, e.path);
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
