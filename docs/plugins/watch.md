# Watch

The watch plugin lets pages follow changes to the directories and files
the app lets them watch, such as a project open in an editor. PHP code and
native UI don't need it: they call `App::watchFiles()` directly (see
[Watching files](../native.md#watching-files)), which the plugin uses too.

## Set up

Use the plugin in PHP with the directories and files pages may watch, each
under a name:

<!-- no-test -->
```php
use MyPHP\App;
use MyPHP\Plugins\Watch\{Options, Root, Watch};

App::use(Watch::new(new Options(roots: [
    'project' => new Root($projectDir, recursive: true),
    'settings' => new Root($settingsFile),
])));
// ...
```

and add its package to the frontend:

```sh
bun add @myphp-plugins/watch
```

Pages name a root by its name, never by a path, so they can't watch
anything else. A name is lowercase letters, digits and dashes, starting
with a letter. A path must be absolute and a directory or a file when
`App::use()` runs, which throws otherwise; it resolves the path's
symlinks, as macOS's `/tmp` is one. `Watch::plugin()`, without roots, lets
pages watch nothing.

`recursive` lets pages watch the directories in the directory too, and
what is in them; without it they can watch the directory itself and the
files in it. `ignore` leaves out files and directories, by their path
relative to the root, as `FileWatchOptions::$ignore` does: a directory it
ignores, as `node_modules`, is never opened, and doesn't count in the
entries a watch may cover (8192, or 65536 on macOS).

<!-- no-test -->
```php
'project' => new Root($projectDir, recursive: true, ignore: fn(string $p, bool $isDir): bool =>
    $isDir && in_array(basename($p), ['node_modules', '.git'], true)),
```

Roots can also be granted while the app runs, such as a folder the user
chose: `resolve` is asked for the root of a name that is not in `roots`,
with the call's context, and returns null for names it doesn't know.

<!-- no-test -->
```php
$granted = new ArrayObject(); // name → Root

App::use(Watch::new(new Options(
    resolve: fn(CallContext $ctx, string $name): ?Root => $granted[$name] ?? null,
)));

// After the user picked $dir in a dialog, a bound method returns the name
// the page then watches.
$granted['picked-1'] = new Root($dir, recursive: true);
```

`allow`, when set, is asked before each watch, with the call's context
(`$ctx->window()` is the window asking) and the `Request`: the root's
name, the path in it and whether it is recursive. It can refuse it:

<!-- no-test -->
```php
App::use(Watch::new(new Options(
    roots: $roots,
    allow: fn(CallContext $ctx, Request $r): bool => $ctx->window() === $editorWindow,
)));
```

Each page can watch 8 directories and files at a time
(`maxWatchesPerPage`), and all pages 128 (`maxWatches`).

## Watching

`watch` starts a watch and resolves once everything is watched. Iterate it
for the changes, a batch of events each time they stop:

```ts
import { watch } from "@myphp-plugins/watch";

const watcher = await watch("project", { recursive: true });
for await (const events of watcher) {
  for (const event of events) console.log(event.op, event.path, event.oldPath);
}
```

`path` watches a directory or a file in the root rather than the root
itself, relative to it and separated by slashes (`"src"`,
`"package.json"`); it can't leave the root, nor go through a symlink.

An event has `op`, one of `create`, `write`, `remove` and `rename`, `path`,
relative to what is watched and separated by slashes (`.` is what is
watched itself), `oldPath`, where a rename moved from, and `isDir`. What is
already there when the watch starts is not reported. A file watched is
watched through its directory: a file replaced, as editors save, is a
`write` of `.`, one removed a `remove`, and one that appears again a
`create`. When what is watched, or the directory of a file watched, is
removed, renamed or replaced, the last batch has a `remove` of `.`, and the
iteration ends.

Changes wait for 50 ms of quiet before they are reported, and never more
than 500 ms while more keep coming; `debounceMs` sets the quiet, as
`FileWatchOptions::$debounce` does in PHP: `0` is 50 ms, and a negative
number reports them right away. A watch compares what it sees with what it
saw, so events say what is different, not every step that made it so: a
file written many times in a row is one write, one replaced at its path by
another, as atomic saves replace it, is a write too, and one created and
removed in between is nothing. Treat them as hints to read the files
again. PHP doesn't look again until the page has taken a batch, so nothing
is lost while the page is busy.

`watcher.close()`, `break` out of the loop, or an `AbortSignal` passed as
`signal` stop the watch, and so does the page navigating or closing.
`watcher.closed` settles once PHP has stopped it, and rejects with the
error that stopped it. Errors are `WatchError`s, whose `code` is:

- `denied`: the name is not one of the roots, `allow` refused, the path is
  not in the root, or the page asked for a recursive watch, or one of a
  directory in it, of a root without `recursive`;
- `not-found`: the path doesn't exist;
- `limit`: the page or the app watch too many, or the directory has more
  files and directories than a watch may cover (8192, or 65536 on macOS);
- `unsupported`: the platform can't watch files;
- `path-encoding`: a file's name is not valid UTF-8, which pages can't be
  given;
- `io`: anything else, such as a root replaced since the app started.

## Platforms

Watches follow what is in the directory, not where symlinks in it point.
Only expose directories the app controls: names keep pages from choosing
paths, but another program can still move or replace what is in a
directory.

- macOS watches with FSEvents: a watch is one event stream covering
  everything beneath its root, so it costs no file descriptor per file,
  and a file watched is covered by the stream of its directory.
- Linux watches each directory with inotify, through one instance for the
  app, whose limit is the system's (`fs.inotify.max_user_watches`).
