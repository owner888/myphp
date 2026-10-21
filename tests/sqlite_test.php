<?php
// SQLite (mygo's plugins/sqlite database_test.go): values in and out,
// SQLite's features, SQL checked before it runs, transactions, read-only
// and busy connections, and closing. The library as Sqlite::load() finds
// it: $MYGO_SQLITE_LIBRARY, or the one in the cache.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Plugins\Sqlite\{Blob, DB, ErrClosed, Error, OpenOptions, Sqlite, Statement};

try {
    Sqlite::load();
} catch (\Throwable $e) {
    echo "  skipped: sqlite: {$e->getMessage()}\n";
    return;
}

/** Whether $fn throws, and what. */
function sqlThrows(\Closure $fn): ?\Throwable
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return $e;
    }
    return null;
}

(function () {
    // Values in and out.
    $db = Sqlite::open(':memory:');
    $values = [null, PHP_INT_MIN, PHP_INT_MAX, -3.125, "中文🙂\0text", new Blob("\0\1\xff"), new Blob(''), '', true];
    $rows = $db->query('SELECT ? AS same, ? AS same, ?, ?, ?, ?, ?, ?, ?', ...$values);
    $want = $values;
    $want[8] = 1;
    check($rows->values == [$want], 'values come back as they went: ' . json_encode(array_map(fn($v) => $v instanceof Blob ? 'blob:' . bin2hex($v->bytes) : $v, $rows->values[0] ?? [])));
    same(['same', 'same'], array_slice($rows->columns, 0, 2), 'duplicate column names');
    $db->execute('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT UNIQUE, bytes BLOB)');
    $r = $db->execute('INSERT INTO items(name, bytes) VALUES (?, ?)', "it's a ?; DROP TABLE items; --", new Blob("\1\2"));
    same([1, 1], [$r->changes, $r->lastInsertId], 'an insert');
    same(0, $db->execute('CREATE TABLE other (n)')->changes, 'DDL changes nothing');
    $got = $db->query('SELECT name, bytes FROM items')->values[0];
    check($got[0] === "it's a ?; DROP TABLE items; --" && $got[1] == new Blob("\1\2"), 'parameters are values, not SQL');
    $db->query('SELECT randomblob(65536)');
    check($got[1] == new Blob("\1\2"), 'a BLOB is a copy');
    $empty = $db->query('SELECT * FROM items WHERE 0');
    same([[], 3], [$empty->values, count($empty->columns)], 'no rows, the columns');
    same(2, $db->query("INSERT INTO items(name) VALUES ('returning') RETURNING id")->values[0][0], 'RETURNING');
    $db->close();
})();

(function () {
    // SQLite's features: the version, foreign keys, FTS5, R*Tree, JSON, VACUUM.
    $db = Sqlite::open(':memory:');
    same('3.53.4', $db->query('SELECT sqlite_version()')->values[0][0], 'the version');
    $db->execute('CREATE TABLE parent (id PRIMARY KEY)');
    $db->execute('CREATE TABLE child (parent REFERENCES parent(id))');
    $e = sqlThrows(fn() => $db->execute('INSERT INTO child VALUES (1)'));
    check($e instanceof Error && $e->getCode() === 19 && $e->extendedCode === 787, 'a foreign key: ' . $e?->getMessage());
    $db->execute('CREATE VIRTUAL TABLE search USING fts5(body)');
    $db->execute("INSERT INTO search VALUES ('hello sqlite')");
    same(1, $db->query("SELECT count(*) FROM search WHERE search MATCH 'sqlite'")->values[0][0], 'FTS5');
    $db->execute('CREATE VIRTUAL TABLE spatial USING rtree(id, x0, x1, y0, y1)');
    same(42, $db->query("SELECT json_extract('{\"a\":42}', '\$.a')")->values[0][0], 'JSON');
    check(sqlThrows(fn() => $db->execute('VACUUM')) === null, 'VACUUM');
    $db->close();
})();

(function () {
    // SQL is checked whole before any of it runs.
    $db = Sqlite::open(':memory:');
    $db->execute('CREATE TABLE items (n UNIQUE)');
    foreach (['INSERT INTO items VALUES (1); INSERT INTO items VALUES (2)', 'INSERT INTO items VALUES (3); invalid sql', "INSERT INTO items VALUES (4)\0;", '', '-- only a comment', 'BEGIN', 'SAVEPOINT foo', "ATTACH ':memory:' AS other", "VACUUM INTO 'outside.db'"] as $sql) {
        check(sqlThrows(fn() => $db->execute($sql)) !== null, 'rejected: ' . json_encode($sql));
    }
    same(0, $db->query('SELECT count(*) FROM items')->values[0][0], 'no partial SQL ran');
    check(sqlThrows(fn() => $db->execute("INSERT INTO items VALUES (?) ; -- trailing comment\n /* another */", 1)) === null, 'a trailing semicolon and comments');
    foreach ([[], [1, 2], [NAN], [INF], [new \stdClass], ["\xff"]] as $args) {
        check(sqlThrows(fn() => $db->execute('INSERT INTO items VALUES (?)', ...$args)) !== null, 'rejected the arguments ' . var_export($args === [] ? [] : (is_object($args[0]) ? 'object' : $args), true));
    }
    $db->close();
})();

(function () {
    // Transactions: all or nothing.
    $db = Sqlite::open(':memory:');
    $db->execute('CREATE TABLE items (n UNIQUE)');
    $results = $db->transaction([new Statement('INSERT INTO items VALUES (?)', [1]), new Statement('INSERT INTO items VALUES (?)', [2])]);
    same([1, 1], array_map(fn($r) => $r->changes, $results), 'a batch');
    foreach (['INSERT INTO items VALUES (2)', 'COMMIT', 'SAVEPOINT escape', 'not sql'] as $second) {
        check(sqlThrows(fn() => $db->transaction([new Statement('INSERT INTO items VALUES (3)'), new Statement($second)])) !== null, "a batch failing at $second");
        same(2, $db->query('SELECT count(*) FROM items')->values[0][0], "the batch failing at $second rolled back");
    }
    same([], $db->transaction([]), 'an empty batch');
    $db->close();
})();

(function () {
    // On disk: read-only, and busy while another connection holds the lock.
    $path = sys_get_temp_dir() . '/myphp-sqlite-' . getmypid() . '.db';
    @unlink($path);
    $w = Sqlite::open($path);
    $w->execute('CREATE TABLE items(n)');
    $w->execute('INSERT INTO items VALUES (42)');
    $r = Sqlite::open($path, new OpenOptions(readOnly: true));
    same(42, $r->query('SELECT n FROM items')->values[0][0], 'read-only reads');
    check(sqlThrows(fn() => $r->execute('DELETE FROM items')) !== null, 'read-only does not write');
    $b = Sqlite::open($path, new OpenOptions(busyTimeout: 0.03));
    // The writer holds the write lock, as a transaction under way does.
    $control = \Closure::bind(fn(string $sql) => $this->control($sql), $w, DB::class);
    $control('BEGIN IMMEDIATE');
    $t = microtime(true);
    $e = sqlThrows(fn() => $b->execute('INSERT INTO items VALUES (1)'));
    check($e instanceof Error && $e->getCode() === 5, 'busy: ' . $e?->getMessage());
    check(microtime(true) - $t >= 0.025, 'busy waits for its timeout');
    $control('COMMIT');
    check(sqlThrows(fn() => $b->execute('INSERT INTO items VALUES (1)')) === null, 'once the lock is let go of');
    foreach ([$w, $r, $b] as $db) { $db->close(); }
    @unlink($path);
})();

(function () {
    // A closed connection.
    $db = Sqlite::open(':memory:');
    $db->close();
    check(sqlThrows(fn() => $db->query('SELECT 1')) instanceof ErrClosed, 'a closed database');
    check(sqlThrows(fn() => $db->close()) === null, 'closing twice');
    check(sqlThrows(fn() => Sqlite::open('')) !== null, 'an empty path');
})();
