<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Sqlite;

use FFI;
use FFI\CData;
use InvalidArgumentException;

/**
 * A connection to the plugin's SQLite library (mygo's sqlite.DB). Each
 * operation runs at once, to its end: a statement waits for another
 * connection's lock as long as OpenOptions::$busyTimeout says. Use
 * transaction() for atomic groups of statements.
 */
final class DB
{
    private const ROW = 100, DONE = 101;

    /** The connection, null once closed. */
    private ?CData $handle;
    /** The operation running now, whose busy handler and authorizer the shim keeps. */
    private ?CData $token = null;

    /** @internal Sqlite::open() makes it */
    public function __construct(CData $handle, private readonly int $timeout)
    {
        $this->handle = $handle;
    }

    /** Closes the connection. Repeated calls are harmless. */
    public function close(): void
    {
        if ($this->handle === null) {
            return;
        }
        $rc = Lib::ffi()->sqlite3_close_v2($this->handle);
        if ($rc !== 0) {
            throw self::error($this->handle, $rc);
        }
        $this->handle = null;
    }

    /**
     * Runs exactly one statement with positional parameters. Supported
     * values are null, bool, int, finite floats, strings (UTF-8 text) and
     * Blob. SQL text may not contain NUL. Transaction control, ATTACH and
     * VACUUM INTO are rejected; use transaction() for groups of statements.
     */
    public function execute(string $sql, mixed ...$args): Result
    {
        return $this->operation(fn() => $this->run($sql, array_values($args), false)[0]);
    }

    /** Runs exactly one statement and copies its rows. It also supports statements with RETURNING. For large results, select a bounded page. */
    public function query(string $sql, mixed ...$args): Rows
    {
        return $this->operation(fn() => $this->run($sql, array_values($args), true)[1]);
    }

    /**
     * Runs statements under BEGIN IMMEDIATE, without interleaving other
     * operations on this connection. Any error rolls back the batch, and
     * throws; the results come only once COMMIT succeeded.
     *
     * @param list<Statement> $statements
     * @return list<Result>
     */
    public function transaction(array $statements): array
    {
        return $this->operation(function () use ($statements): array {
            if ($statements === []) {
                return [];
            }
            $this->control('BEGIN IMMEDIATE');
            try {
                $results = [];
                foreach (array_values($statements) as $i => $s) {
                    try {
                        $results[] = $this->run($s->sql, array_values($s->args), false)[0];
                    } catch (InvalidArgumentException $e) {
                        throw new InvalidArgumentException('sqlite: statement ' . ($i + 1) . ': ' . $e->getMessage(), 0, $e); // an Error SQLite gave stays itself, with its codes
                    }
                }
                $this->control('COMMIT');
                return $results;
            } catch (\Throwable $e) {
                // Cleanup runs without the operation's authorizer.
                Lib::ffi()->mygo_sqlite_operation_end($this->handle);
                try {
                    $this->control('ROLLBACK');
                } catch (Error $rollback) {
                    if (Lib::ffi()->sqlite3_get_autocommit($this->handle) === 0) {
                        throw new \RuntimeException($e->getMessage() . '; ' . $rollback->getMessage(), $e->getCode(), $e);
                    }
                }
                throw $e;
            }
        });
    }

    /** Runs $fn as one operation of the shim: its busy timeout and its authorizer, which keeps transaction control and ATTACH out of the user's SQL. */
    private function operation(\Closure $fn): mixed
    {
        if ($this->handle === null) {
            throw new ErrClosed;
        }
        $f = Lib::ffi();
        $op = $f->mygo_sqlite_operation_new($this->timeout);
        if (FFI::isNull($op)) {
            throw new Error(7, 7, 'out of memory');
        }
        $this->token = $op;
        $f->mygo_sqlite_operation_start($this->handle, $op);
        try {
            return $fn();
        } finally {
            $f->mygo_sqlite_operation_end($this->handle);
            $f->sqlite3_free($op);
            $this->token = null;
        }
    }

    /** The error of a result code, with the connection's message. */
    public static function error(?CData $db, int $rc): Error
    {
        $code = $rc;
        $message = 'could not open database';
        if ($db !== null && !FFI::isNull($db)) {
            $code = Lib::ffi()->sqlite3_extended_errcode($db);
            $message = (string)Lib::ffi()->sqlite3_errmsg($db);
        }
        return new Error($code & 255, $code, $message);
    }

    /** Prepares $sql, which must hold exactly one statement: the rest is compiled first, so that extra SQL, even invalid after a valid first statement, never runs a partial operation. */
    private function prepare(string $sql, bool $control): CData
    {
        if (strlen($sql) > 0x7ffffffe || str_contains($sql, "\0") || !mb_check_encoding($sql, 'UTF-8')) {
            throw new InvalidArgumentException('sqlite: SQL must be UTF-8 without NUL and fit in a C int');
        }
        $f = Lib::ffi();
        $n = strlen($sql) + 1;
        $buf = $f->new("char[$n]");
        FFI::memcpy($buf, $sql . "\0", $n);
        $base = Lib::addr(FFI::addr($buf[0]));
        $call = function (int $offset, CData $stmt, CData $tail) use ($f, $buf, $n, $control): int {
            $at = FFI::addr($buf[$offset]);
            return $control
                ? $f->sqlite3_prepare_v2($this->handle, $at, $n - $offset, FFI::addr($stmt), $f->cast('const char**', FFI::addr($tail)))
                : $f->mygo_sqlite_prepare($this->handle, $at, $n - $offset, FFI::addr($stmt), $f->cast('const char**', FFI::addr($tail)), $this->token);
        };
        $stmt = $f->new('sqlite3_stmt*');
        $tail = $f->new('intptr_t');
        $rc = $call(0, $stmt, $tail);
        if ($rc !== 0) {
            $e = self::error($this->handle, $rc);
            if (!FFI::isNull($stmt)) { $f->sqlite3_finalize($stmt); }
            throw $e;
        }
        if (FFI::isNull($stmt)) {
            throw new InvalidArgumentException('sqlite: SQL contains no statement');
        }
        for ($offset = $tail->cdata - $base; $offset < strlen($sql); $offset = $tail->cdata - $base) {
            $extra = $f->new('sqlite3_stmt*');
            $rc = $call($offset, $extra, $tail);
            if ($rc !== 0 || !FFI::isNull($extra)) {
                $e = $rc !== 0 ? self::error($this->handle, $rc) : new InvalidArgumentException('sqlite: expected exactly one statement');
                if (!FFI::isNull($extra)) { $f->sqlite3_finalize($extra); }
                $f->sqlite3_finalize($stmt);
                throw $e;
            }
            if ($tail->cdata - $base <= $offset) {
                break;
            }
        }
        return $stmt;
    }

    /** @param list<mixed> $args @return array{0: Result, 1: Rows} */
    private function run(string $sql, array $args, bool $query): array
    {
        $f = Lib::ffi();
        $stmt = $this->prepare($sql, false);
        try {
            if (($count = $f->sqlite3_bind_parameter_count($stmt)) !== count($args)) {
                throw new InvalidArgumentException("sqlite: statement needs $count parameters, got " . count($args));
            }
            foreach ($args as $i => $arg) {
                try {
                    $this->bind($stmt, $i + 1, $arg);
                } catch (InvalidArgumentException $e) {
                    throw new InvalidArgumentException('sqlite: parameter ' . ($i + 1) . ': ' . $e->getMessage(), 0, $e);
                }
            }
            $columns = [];
            $values = [];
            if ($query) {
                for ($i = 0, $n = $f->sqlite3_column_count($stmt); $i < $n; $i++) {
                    $columns[] = (string)$f->sqlite3_column_name($stmt, $i);
                }
            }
            $before = $f->sqlite3_total_changes64($this->handle);
            while (true) {
                $rc = $f->sqlite3_step($stmt);
                if ($rc === self::ROW) {
                    if ($query) {
                        $row = [];
                        for ($i = 0, $n = count($columns); $i < $n; $i++) { $row[] = self::column($stmt, $i); }
                        $values[] = $row;
                    }
                } elseif ($rc === self::DONE) {
                    $changes = $f->sqlite3_total_changes64($this->handle) !== $before ? $f->sqlite3_changes64($this->handle) : 0;
                    return [new Result($changes, $f->sqlite3_last_insert_rowid($this->handle)), new Rows($columns, $values)];
                } else {
                    throw self::error($this->handle, $rc);
                }
            }
        } finally {
            $f->sqlite3_finalize($stmt);
        }
    }

    /** Runs a statement of transaction control. */
    private function control(string $sql): void
    {
        $f = Lib::ffi();
        $stmt = $this->prepare($sql, true);
        try {
            if (($rc = $f->sqlite3_step($stmt)) !== self::DONE) {
                throw self::error($this->handle, $rc);
            }
        } finally {
            $f->sqlite3_finalize($stmt);
        }
    }

    private function bind(CData $stmt, int $index, mixed $value): void
    {
        $f = Lib::ffi();
        $transient = $f->cast('void*', -1); // SQLITE_TRANSIENT: SQLite copies the value
        if ($value === null) {
            $rc = $f->sqlite3_bind_null($stmt, $index);
        } elseif ($value instanceof Blob) {
            if (strlen($value->bytes) > 0x7fffffff) {
                throw new InvalidArgumentException('BLOB is too large');
            }
            // A pointer, never NULL, for an empty BLOB, which is not SQL NULL.
            $rc = $f->sqlite3_bind_blob($stmt, $index, $value->bytes === '' ? "\0" : $value->bytes, strlen($value->bytes), $transient);
        } elseif (is_bool($value)) {
            $rc = $f->sqlite3_bind_int64($stmt, $index, $value ? 1 : 0);
        } elseif (is_int($value)) {
            $rc = $f->sqlite3_bind_int64($stmt, $index, $value);
        } elseif (is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException('number must be finite');
            }
            $rc = $f->sqlite3_bind_double($stmt, $index, $value);
        } elseif (is_string($value)) {
            if (strlen($value) > 0x7fffffff || !mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidArgumentException('text must be UTF-8 and fit in a C int (bytes are a Blob)');
            }
            $rc = $f->sqlite3_bind_text($stmt, $index, $value, strlen($value), $transient);
        } else {
            throw new InvalidArgumentException('unsupported value ' . get_debug_type($value));
        }
        if ($rc !== 0) {
            throw self::error($this->handle, $rc);
        }
    }

    /** A cell of the current row, copied. */
    private static function column(CData $stmt, int $i): null|int|float|string|Blob
    {
        $f = Lib::ffi();
        switch ($f->sqlite3_column_type($stmt, $i)) {
            case 1: return $f->sqlite3_column_int64($stmt, $i);
            case 2: return $f->sqlite3_column_double($stmt, $i);
            case 3:
                $p = $f->sqlite3_column_text($stmt, $i);
                $n = $f->sqlite3_column_bytes($stmt, $i);
                return $n === 0 ? '' : FFI::string($p, $n);
            case 4:
                $p = $f->sqlite3_column_blob($stmt, $i);
                $n = $f->sqlite3_column_bytes($stmt, $i);
                return new Blob($n === 0 ? '' : FFI::string($p, $n));
            default:
                return null;
        }
    }
}
