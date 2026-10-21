<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Sqlite;

use FFI;
use FFI\CData;
use RuntimeException;

/**
 * The plugin's SQLite library through FFI (mygo's native.go): SQLite's
 * amalgamation and mygo's small C shim (native/sqlite.c), the build mygo's
 * release carries.
 *
 * @internal
 */
final class Lib
{
    private const CDEF = <<<'C'
        typedef struct sqlite3 sqlite3;
        typedef struct sqlite3_stmt sqlite3_stmt;
        typedef struct mygo_sqlite_operation mygo_sqlite_operation;
        int mygo_sqlite_abi_version(void);
        int sqlite3_open_v2(const char *filename, sqlite3 **db, int flags, const char *vfs);
        int sqlite3_close_v2(sqlite3 *db);
        const char *sqlite3_errmsg(sqlite3 *db);
        int sqlite3_extended_errcode(sqlite3 *db);
        int sqlite3_extended_result_codes(sqlite3 *db, int onoff);
        const char *sqlite3_libversion(void);
        int sqlite3_threadsafe(void);
        int mygo_sqlite_prepare(sqlite3 *db, const char *sql, int size, sqlite3_stmt **stmt, const char **tail, mygo_sqlite_operation *op);
        int sqlite3_prepare_v2(sqlite3 *db, const char *sql, int size, sqlite3_stmt **stmt, const char **tail);
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
        mygo_sqlite_operation *mygo_sqlite_operation_new(int timeout_ms);
        void mygo_sqlite_operation_cancel(mygo_sqlite_operation *op);
        void mygo_sqlite_operation_start(sqlite3 *db, mygo_sqlite_operation *op);
        void mygo_sqlite_operation_end(sqlite3 *db);
        void sqlite3_free(void *p);
        C;

    private static ?FFI $ffi = null;
    private static ?\Throwable $error = null;

    /** Loads the library at $path, once; later calls report how the first went. */
    public static function load(string $path): void
    {
        if (self::$ffi === null && self::$error === null) {
            try {
                $ffi = FFI::cdef(self::CDEF, $path);
            } catch (\FFI\Exception $e) {
                self::$error = new RuntimeException("sqlite: $path is not the plugin's SQLite library: {$e->getMessage()}");
                throw self::$error;
            }
            if ($ffi->mygo_sqlite_abi_version() !== 1 || $ffi->sqlite3_threadsafe() === 0) {
                self::$error = new RuntimeException("sqlite: $path has an incompatible ABI or is not threadsafe");
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

    /** The integer address of a pointer, through a void* slot (casting a pointer to an integer type would read what it points at). */
    public static function addr(CData $p): int
    {
        static $slot = null, $slotInt = null;
        if ($slot === null) {
            $slot = self::ffi()->new('void*[1]');
            $slotInt = self::ffi()->cast('intptr_t[1]', $slot);
        }
        $slot[0] = $p;
        return $slotInt[0];
    }
}
