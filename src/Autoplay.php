<?php
declare(strict_types=1);

namespace MyPHP;

/** Whether a page may play audible media without a user gesture (PageOptions::$autoplay, mygo's Autoplay). */
enum Autoplay
{
    /** The default: the page's web view decides, which on macOS lets audible media start once the user clicked or typed in the page. */
    case UserGesture;
    /**
     * Lets the page start audible media on its own, in a hidden window too,
     * as for a hidden window playing the sounds of an app whose interface
     * is native UI: WKWebViewConfiguration's
     * mediaTypesRequiringUserActionForPlayback set to none.
     */
    case Allow;
}
