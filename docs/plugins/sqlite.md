# SQLite

The SQLite plugin provides local databases to web pages and PHP, including
native UI apps. It ships prebuilt SQLite libraries and loads them through
FFI, so apps need neither PHP's own SQLite extensions nor a C compiler. It
supports macOS and Linux on amd64 and arm64.

## Using the page plugin

Call `App::use(Sqlite::plugin())` only when the web frontend uses
`@myphp-plugins/sqlite`. It registers the PHP service called by that
TypeScript client. PHP and native UI apps use `Sqlite::open()` directly, as
shown [below](#from-php-or-native-ui).

<!-- no-test -->
```php
use MyPHP\App;
use MyPHP\Plugins\Sqlite\Sqlite;

App::use(Sqlite::plugin());
// Create windows and call App::run() as usual.
```

Install the frontend package with `bun add @myphp-plugins/sqlite`, then:

```ts
import { open } from "@myphp-plugins/sqlite";

const db = await open("app.db");
await db.execute("CREATE TABLE IF NOT EXISTS notes (id INTEGER PRIMARY KEY, title TEXT)");
const result = await db.execute("INSERT INTO notes(title) VALUES (?)", ["Hello SQLite"]);
const { columns, rows } = await db.query("SELECT id, title FROM notes WHERE id = ?", [result.lastInsertId]);
// columns: ["id", "title"], rows: [[1, "Hello SQLite"]]
await db.close();
```

`open` takes a database file name, such as `app.db`, or `:memory:` for a
private in-memory database. Files live in `App::path(PathName::UserData)`.
Absolute paths, directory traversal and symbolic-link database files are
rejected. To choose the directory and lock wait:

<!-- no-test -->
```php
use MyPHP\Plugins\Sqlite\{Options, Sqlite};

App::use(Sqlite::new(new Options(
    directory: '/path/to/databases',
    busyTimeout: 2, // seconds
)));
```

`directory` defaults to `App::path(PathName::UserData)`; a missing
directory is created for writable databases. `busyTimeout` defaults to
five seconds; a negative one disables waiting. `open("app.db", { readOnly:
true })` opens an existing file without allowing writes.

## Queries and values

`execute(sql, params?)` accepts exactly one statement, consumes any
`RETURNING` rows, and returns `{ changes, lastInsertId }`. `changes` counts
directly affected rows; `lastInsertId` is SQLite's last inserted rowid on
that connection, always a `bigint` in TypeScript.

`query(sql, params?)` returns `{ columns, rows }`. Each row is an array of
cells in column order, preserving duplicate column names. It supports
`SELECT`, pragmas and statements with `RETURNING`. Results are buffered;
use `LIMIT` and pagination for large tables.

Parameters bind by their SQLite index: `?`, `?NNN`, `:name`, `$name` and
`@name` are accepted, with values supplied in an array. All parameter
indexes must have values; missing or excess parameters fail. Never
interpolate application values into SQL strings. Multiple statements are
rejected before the first executes; a trailing semicolon and comments are
allowed.

| SQLite storage class | TypeScript | PHP |
| --- | --- | --- |
| NULL | `null` | `null` |
| INTEGER | `number` when safe, otherwise `bigint` | `int` |
| REAL | `number` | `float` |
| TEXT | `string` | `string` (UTF-8) |
| BLOB | `Uint8Array` | `Blob` (its `bytes`) |

Boolean parameters become integers 0 or 1. Bind a `bigint` when an integer
exceeds JavaScript's safe range; values outside SQLite's signed 64-bit
range fail. Nonfinite numeric parameters fail. PHP's strings are bytes, so
a string is TEXT, which must be UTF-8, and bytes are bound as a BLOB by
wrapping them in `new Blob($bytes)`. Empty BLOBs and empty text remain
distinct from NULL. Text and BLOB parameters are copied by SQLite, and
result BLOBs are copied before a statement closes.

## Transactions and lifetime

```ts
await db.transaction([
  { sql: "INSERT INTO notes(title) VALUES (?)", params: ["first"] },
  { sql: "INSERT INTO notes(title) VALUES (?)", params: ["second"] },
]);
```

`transaction` returns one execution result per statement after COMMIT. It
uses `BEGIN IMMEDIATE`, holds the connection for the entire batch, and
rolls back on any error. An empty batch returns `[]`. Transaction-control
statements (`BEGIN`, `COMMIT`, `ROLLBACK`, `SAVEPOINT`, `RELEASE`) are
rejected in user SQL; use the batch API. `ATTACH`, `DETACH` and `VACUUM
INTO` are rejected so SQL cannot open files outside the configured
directory. Ordinary `VACUUM` is supported. SQLite extensions cannot be
loaded.

Each connection belongs to its calling page. Other windows cannot use its
handle. Page navigation and window close close the page's connections; app
quit closes every remaining connection. `close()` is idempotent.

## From PHP or native UI

No `App::use(Sqlite::plugin())` registration is needed. Open a connection
directly and close it when finished:

<!-- no-test -->
```php
use MyPHP\Plugins\Sqlite\{Sqlite, Statement};

$db = Sqlite::open(App::path(PathName::UserData) . '/app.db');
try {
    $db->execute('CREATE TABLE IF NOT EXISTS notes (title TEXT)');
    $db->transaction([
        new Statement('INSERT INTO notes VALUES (?)', ['first']),
        new Statement('INSERT INTO notes VALUES (?)', ['second']),
    ]);
    $rows = $db->query('SELECT title FROM notes');
    // $rows->columns and $rows->values contain copied results.
} finally {
    $db->close();
}
```

`Sqlite::open()` accepts a path directly; its parent directory must exist.
It returns a `DB` (`MyPHP\Plugins\Sqlite\DB`): `execute()` returns a
`Result` (`changes`, `lastInsertId`), `query()` a `Rows` (`columns`, the
column names, and `values`, a list of rows, each a list of cells in column
order), `transaction()` a list of `Result`s, one per `Statement`.

The PHP API is synchronous: each call runs to its end on the calling
thread, which in an app is the main one, the UI waiting meanwhile, and
waits for another connection's lock up to `OpenOptions::$busyTimeout`. It
does not suspend a fiber of `App::go()` (unlike `MyPHP\Http`): keep
queries short, page large results with `LIMIT`, and batch writes in a
`transaction()`, which is much faster than separate statements.
`execute()` and `query()` take the parameters after the SQL, as
`$db->query('SELECT * FROM notes WHERE id = ?', $id)`. The connection's
lifetime belongs to the caller, who must call `close()`. `Error` exposes
the primary (`getCode()`) and extended (`extendedCode`) SQLite error
codes; closed connections throw `ErrClosed`.

## Building and shipping

`myphp build` and `myphp dev` read the plugin's `mygo-plugin.json` and put
the right library among the app's resources, signed with the app on
macOS; macOS universal builds merge both architectures. Packaged apps need
no download at run time. Scripts run outside an app download the matching
library once into a cache and verify its SHA-256. `MYGO_SQLITE_LIBRARY`
names a custom build for development.
