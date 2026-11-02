<?php
declare(strict_types=1);

namespace MyPHP\Platform;

/** What a website data store is (mygo's platform.DataStoreOptions, AppController::newDataStore). */
final class DataStoreOptions
{
    public function __construct(
        /** Identifies a persistent store from one run to the next: a UUID the core derives from the session's name; '' for an ephemeral store. */
        public readonly string $id = '',
        /** Keeps the store's data in memory, where it goes with the store. */
        public readonly bool $ephemeral = false,
        /** Where a persistent store keeps its data and its caches, for the backends that choose (Linux). */
        public readonly string $dataDir = '',
        public readonly string $cacheDir = '',
    ) {}
}
