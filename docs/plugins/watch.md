# File Watching (`App::watchFiles`)

MyPHP includes a cross-platform native file and directory watcher (`App::watchFiles()`, backed by FSEvents on macOS and inotify on Linux) that reports file creations, writes, and removals on the run loop without blocking the UI.

## Watching a directory or file in PHP

Call `App::watchFiles($path, new FileWatchOptions(...))` inside an `App::go()` fiber and iterate `$watcher->next()` for batches of `FileEvent` objects:

<!-- no-test -->
```php
use MyPHP\{App, FileWatchOptions};

App::go(function () use ($app, $projectDir) {
    $watcher = App::watchFiles($projectDir, new FileWatchOptions(
        recursive: true,
        debounce: 0.05,
        ignore: fn(string $relPath, bool $isDir): bool =>
            $isDir && in_array(basename($relPath), ['.git', 'vendor', 'node_modules'], true),
    ));

    while (($events = $watcher->next()) !== null) {
        foreach ($events as $ev) {
            // $ev->path (relative to watched root), $ev->kind ('write', 'create', 'remove')
            $app->changedFiles[] = "{$ev->kind}: {$ev->path}";
        }
        // Content::file auto-observes $app->changedFiles and redraws the window!
    }
});
```

### `FileWatchOptions`

- **`recursive`** (`bool`, default `false`): Watch subdirectories as well.
- **`debounce`** (`float`, default `0.05`s): Coalesce rapid consecutive saves into one batch.
- **`maxDelay`** (`float`, default `0.5`s): Maximum wait before delivering a batch during continuous writes.
- **`ignore`** (`?Closure(string $relPath, bool $isDir): bool`): Skip matching files or directories without opening them.