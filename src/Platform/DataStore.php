<?php
declare(strict_types=1);

namespace MyPHP\Platform;

/**
 * A website data store a backend made (mygo's platform.DataStore): the
 * cookies, local and session storage, IndexedDB, caches and service
 * workers that the pages using it share, apart from those of other
 * stores. The default store is null wherever one is taken.
 */
interface DataStore
{
    /**
     * Lets the store go once the backend no longer needs it: the core
     * calls it when no page uses an ephemeral store, whose data then goes,
     * and makes another store for the session's next page.
     */
    public function close(): void;
}
