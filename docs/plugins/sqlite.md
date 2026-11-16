# SQLite

`MyPHP\Plugins\Sqlite` provides a fast, zero-configuration local SQLite 3
database for PHP and native UI apps. It ships prebuilt, thread-safe SQLite
libraries (with FTS5 full-text search, R*Tree spatial indexes, and JSON
functions enabled) and loads them through `ext-ffi`, so apps need neither a C
compiler nor system SQLite setup.

## Opening and querying a database

Open a database file directly (such as in `App::path(PathName::UserData)`) or
use `':memory:'` for an in-memory database:

<!-- no-test -->
```php
use MyPHP\{App, PathName};
use MyPHP\Plugins\Sqlite\{Sqlite, Statement};

$db = Sqlite::open(App::path(PathName::UserData) . '/app.db');
try {
    $db->execute('CREATE TABLE IF NOT EXISTS notes (id INTEGER PRIMARY KEY, title TEXT, body BLOB)');

    // Atomic batch transaction (rolls back automatically on any error)
    $db->transaction([
        new Statement('INSERT INTO notes (title) VALUES (?)', ['First note']),
        new Statement('INSERT INTO notes (title) VALUES (?)', ['Second note']),
    ]);

    $rows = $db->query('SELECT id, title FROM notes WHERE id = ?', 1);
    foreach ($rows->values as [$id, $title]) {
        // $rows->columns = ['id', 'title']
    }
} finally {
    $db->close();
}
```

### Options (`OpenOptions`)

Pass `new OpenOptions(readOnly: true, busyTimeout: 5.0)` to `Sqlite::open()` to
open a database in read-only mode or customize how many seconds a statement
waits when another connection holds a write lock (defaults to 5 seconds).

## Queries, parameters, and types

- **`$db->execute(string $sql, mixed ...$args): Result`**: Runs one statement
  and returns `$result->changes` (affected rows) and `$result->lastInsertId`.
- **`$db->query(string $sql, mixed ...$args): Rows`**: Runs a `SELECT`,
  `PRAGMA`, or `INSERT ... RETURNING` statement and returns `$rows->columns`
  (`list<string>`) and `$rows->values` (`list<list<mixed>>`).
- **`$db->transaction(array $statements): list<Result>`**: Runs a list of
  `Statement` objects inside `BEGIN IMMEDIATE ... COMMIT`. If any statement
  fails, the entire batch rolls back.

| SQLite storage class | PHP type |
| --- | --- |
| `NULL` | `null` |
| `INTEGER` | `int` (`bool` binds as `1` or `0`) |
| `REAL` | `float` (finite numbers only) |
| `TEXT` | `string` (valid UTF-8) |
| `BLOB` | `MyPHP\Plugins\Sqlite\Blob` (`new Blob($rawBytes)`) |

Multiple statements in a single SQL string (`'INSERT ...; DROP ...'`) and manual
transaction/attachment statements (`BEGIN`, `COMMIT`, `ATTACH`, `VACUUM INTO`)
are rejected before execution.

## Building and shipping

`myphp build` and `myphp dev` read the plugin's `myphp-plugin.json` and bundle
the matching native SQLite library into the packaged application automatically.
`MYPHP_SQLITE_LIBRARY` overrides the library path for custom development builds.