<?php
declare(strict_types=1);

namespace MyPHP\Platform\Linux;

use FFI\CData;
use MyPHP\Platform;

/**
 * A session's store (mygo's linux dataStore): a WebKitWebContext of its
 * own, with a WebKitWebsiteDataManager whose data and caches are in the
 * session's directories, or in memory for an ephemeral one. Its web views
 * are made with the context, which they hold while they live.
 */
final class DataStore implements Platform\DataStore
{
    private bool $closed = false;

    public function __construct(public readonly CData $ctx) {}

    /** Drops the backend's reference to the context: an ephemeral one's data goes with the last of its web views (mygo's Close). */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        WebKit::ffi()->g_object_unref($this->ctx);
    }
}
