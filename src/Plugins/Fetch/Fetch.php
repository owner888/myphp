<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Fetch;

use MyPHP\Plugin;

/**
 * The fetch plugin: registers the "plugin:fetch" IPC service so embedded
 * WebView pages can make CORS-free HTTP requests from PHP with custom headers
 * and streaming responses. Native PHP apps use `URLSession::shared()` or
 * `MyPHP\Http` directly.
 *
 *     App::use(Fetch::plugin());
 *
 * The response body streams to the page as it arrives, and aborting the
 * request's signal stops it. The requests are libcurl's, the system's,
 * driven on the run loop. URLs that are not http or https, such as the
 * app's own pages, go to the webview's fetch.
 */
final class Fetch
{
    /** The plugin with the default options (mygo's fetch.Plugin). */
    public static function plugin(): Plugin
    {
        return self::new(new Options);
    }

    /** The plugin with options (mygo's fetch.New). */
    public static function new(Options $opts): Plugin
    {
        return new Plugin('fetch', new Service($opts));
    }
}
