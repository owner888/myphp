<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Fetch;

use MyPHP\Plugin;

/**
 * The fetch plugin (mygo's plugins/fetch): pages make HTTP requests from
 * PHP with the fetch of its TypeScript client (client/index.ts,
 * @myphp-plugins/fetch), which takes and returns the standard Request
 * options and Response. Requests made this way are not subject to CORS,
 * may set any header (Origin, Cookie, User-Agent, Host…) and read every
 * response header but Set-Cookie, which a Response cannot hold.
 *
 *     App::use(Fetch::plugin());
 *
 *     // the page
 *     import { fetch } from "@myphp-plugins/fetch";
 *     const items = await (await fetch("https://api.example.com/items", { headers: { authorization: `Bearer ${token}` } })).json();
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
