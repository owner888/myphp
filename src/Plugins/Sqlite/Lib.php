<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Sqlite;

use FFI;
use RuntimeException;

/**
 * The plugin's SQLite library through FFI: uses standard SQLite3 C API
 * symbols directly (`sqlite3_open_v2`, `sqlite3_busy_timeout`, `sqlite3_prepare_v2`, etc.)
 * with zero custom C shim dependency.
 *
 * @internal
 */
final class Lib
{
    private const CDEF = <<<'C'
        typedef struct sqlite3 sqlite3;
        typedef struct sqlite3_stmt sqlite3_stmt;
        int sqlite3_open_v2(const char *filename, sqlite3 **db, int flags, const char *vfs);
        int sqlite3_close_v2(sqlite3 *db);
        int sqlite3_busy_timeout(sqlite3 *db, int ms);
        const char *sqlite3_errmsg(sqlite3 *db);
        int sqlite3_extended_errcode(sqlite3 *db);
        int sqlite3_extended_result_codes(sqlite3 *db, int onoff);
        const char *sqlite3_libversion(void);
        int sqlite3_threadsafe(void);
        int sqlite3_prepare_v2(sqlite3 *db, const char *sql, int size, sqlite3_stmt **stmt, const char **tail);
        int sqlite3_stmt_readonly(sqlite3_stmt *pStmt);
        int sqlite3_step(sqlite3_stmt *stmt);
        int sqlite3_finalize(sqlite3_stmt *stmt);
        int sqlite3_bind_parameter_count(sqlite3_stmt *stmt);
        int sqlite3_bind_null(sqlite3_stmt *stmt, int index);
        int sqlite3_bind_int64(sqlite3_stmt *stmt, int index, int64_t value);
        int sqlite3_bind_double(sqlite3_stmt *stmt, int index, double value);
        int sqlite3_bind_text(sqlite3_stmt *stmt, int index, const char *text, int n, void *destructor);
        int sqlite3_bind_blob(sqlite3_stmt *stmt, int index, const void *data, int n, void *destructor);
        int sqlite3_column_count(sqlite3_stmt *stmt);
        const char *sqlite3_column_name(sqlite3_stmt *stmt, int index);
        int sqlite3_column_type(sqlite3_stmt *stmt, int index);
        int64_t sqlite3_column_int64(sqlite3_stmt *stmt, int index);
        double sqlite3_column_double(sqlite3_stmt *stmt, int index);
        const void *sqlite3_column_text(sqlite3_stmt *stmt, int index);
        const void *sqlite3_column_blob(sqlite3_stmt *stmt, int index);
        int sqlite3_column_bytes(sqlite3_stmt *stmt, int index);
        int64_t sqlite3_changes64(sqlite3 *db);
        int64_t sqlite3_total_changes64(sqlite3 *db);
        int64_t sqlite3_last_insert_rowid(sqlite3 *db);
        int sqlite3_get_autocommit(sqlite3 *db);
        void sqlite3_free(void *p);
        C;

    private static ?FFI $ffi = null;
    private static ?\Throwable $error = null;

    /** Loads the library at $path, once; later calls report how the first went. */
    public static function load(string $path): void
    {
        if (self::$ffi === null && self::$error === null) {
            try {
                $ffi = \MyPHP\Plugins\NativeLibrary::cdef(self::CDEF, $path); // bound to its own symbols on Linux
            } catch (\FFI\Exception $e) {
                self::$error = new RuntimeException("sqlite: $path is not the plugin's SQLite library: {$e->getMessage()}");
                throw self::$error;
            }
            if ($ffi->sqlite3_threadsafe() === 0) {
                self::$error = new RuntimeException("sqlite: $path is not threadsafe");
                throw self::$error;
            }
            self::$ffi = $ffi;
        }
        if (self::$error !== null) {
            throw self::$error;
        }
    }

    public static function ffi(): FFI
    {
        return self::$ffi ?? throw new RuntimeException('sqlite: the library is not loaded');
    }
}