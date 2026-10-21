<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Sqlite;

use InvalidArgumentException;
use MyPHP\{App, CallContext};
use RuntimeException;

/**
 * The page plugin's service (mygo's sqlite service), bound as
 * "plugin:sqlite" by App::use(Sqlite::plugin()): the methods the plugin's
 * TypeScript client calls. A connection belongs to the page that opened
 * it: other pages cannot use its id, and it closes when the page goes
 * away (CallContext) or the app quits.
 *
 * Values cross as mygo's wire values: {type: "null"|"integer"|"real"|
 * "text"|"blob", integer (a decimal string), real, special ("Infinity",
 * "-Infinity", "NaN"), text, blob (base64)}.
 *
 * @internal
 */
final class Service
{
    /** @var array<string, array{db: DB, stop: \Closure, ctx: CallContext}> by the page's context and the connection's id */
    private array $dbs = [];
    private bool $closed = false;

    public function __construct(private readonly Options $opts) {}

    /** Opens a database file of the plugin's directory, or ":memory:", for the calling page; returns its id. @param array{readOnly?: bool} $opts */
    public function open(CallContext $ctx, string $name, array $opts = []): string
    {
        if ($ctx->done()) {
            throw new RuntimeException('sqlite: the page is gone');
        }
        $readOnly = (bool)($opts['readOnly'] ?? false);
        $path = $name;
        if ($name !== ':memory:') {
            self::databaseName($name);
            $dir = $this->opts->directory !== '' ? $this->opts->directory : App::userDataPath();
            if (!$readOnly && !is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new RuntimeException("sqlite: cannot make $dir");
            }
            $path = "$dir/$name";
            if (is_link($path)) {
                throw new RuntimeException('sqlite: database file may not be a symbolic link');
            }
        }
        $db = Sqlite::open($path, new OpenOptions($readOnly, $this->opts->busyTimeout));
        if ($this->closed) {
            $db->close();
            throw new ErrClosed;
        }
        $id = bin2hex(random_bytes(16));
        $k = self::key($ctx, $id);
        $this->dbs[$k] = ['db' => $db, 'ctx' => $ctx, 'stop' => $ctx->afterFunc(fn() => $this->closeKey($k))];
        return $id;
    }

    /** @param list<array<string, mixed>> $args @return array{changes: int, lastInsertId: string} */
    public function execute(CallContext $ctx, string $id, string $sql, array $args = []): array
    {
        return self::resultWire($this->database($ctx, $id)->execute($sql, ...self::parameters($args)));
    }

    /** @param list<array<string, mixed>> $args @return array{columns: list<string>, rows: list<list<array<string, mixed>>>} */
    public function query(CallContext $ctx, string $id, string $sql, array $args = []): array
    {
        return self::rowsWire($this->database($ctx, $id)->query($sql, ...self::parameters($args)));
    }

    /** @param list<array{sql: string, args?: list<array<string, mixed>>}> $statements @return list<array{changes: int, lastInsertId: string}> */
    public function transaction(CallContext $ctx, string $id, array $statements): array
    {
        $db = $this->database($ctx, $id);
        $batch = [];
        foreach ($statements as $s) {
            $batch[] = new Statement((string)($s['sql'] ?? ''), self::parameters($s['args'] ?? []));
        }
        return array_map(self::resultWire(...), $db->transaction($batch));
    }

    public function close(CallContext $ctx, string $id): void
    {
        $this->closeKey(self::key($ctx, $id));
    }

    /** Closes every connection, as the app quits: not public, which pages could call. */
    private function shutdown(): void
    {
        $this->closed = true;
        $dbs = $this->dbs;
        $this->dbs = [];
        foreach ($dbs as $c) {
            ($c['stop'])();
            $c['db']->close();
        }
    }

    private static function key(CallContext $ctx, string $id): string
    {
        return spl_object_id($ctx) . ':' . $id;
    }

    private function database(CallContext $ctx, string $id): DB
    {
        $c = $this->dbs[self::key($ctx, $id)] ?? null;
        if ($c === null || $c['ctx'] !== $ctx || $ctx->done()) {
            throw new RuntimeException('sqlite: database is closed or belongs to another page');
        }
        return $c['db'];
    }

    private function closeKey(string $k): void
    {
        $c = $this->dbs[$k] ?? null;
        unset($this->dbs[$k]);
        if ($c !== null) {
            ($c['stop'])();
            $c['db']->close();
        }
    }

    /** A database file name without directories (mygo's databaseName). */
    private static function databaseName(string $name): void
    {
        if ($name === '' || $name === '.' || $name === '..' || !mb_check_encoding($name, 'UTF-8')
            || strpbrk($name, "/\\\0:<>\"|?*") !== false || trim($name) !== $name || str_ends_with($name, '.')) {
            throw new InvalidArgumentException('sqlite: use a database file name without directories, or :memory:');
        }
        // Windows device names stay reserved even with a file extension.
        $base = explode('.', strtoupper($name), 2)[0];
        if (in_array($base, ['CON', 'PRN', 'AUX', 'NUL'], true) || preg_match('/^(COM|LPT)[1-9]$/', $base) === 1) {
            throw new InvalidArgumentException('sqlite: database file name is reserved on Windows');
        }
    }

    /** The parameters of wire values (mygo's parameters). @param list<array<string, mixed>> $values @return list<mixed> */
    private static function parameters(array $values): array
    {
        $args = [];
        foreach (array_values($values) as $i => $v) {
            $n = $i + 1;
            $args[] = match ($v['type'] ?? null) {
                'null' => null,
                'integer' => self::integer((string)($v['integer'] ?? ''), $n),
                'real' => isset($v['special']) && $v['special'] !== '' || !is_finite((float)($v['real'] ?? 0))
                    ? throw new InvalidArgumentException("sqlite: parameter $n: number must be finite")
                    : (float)($v['real'] ?? 0),
                'text' => (string)($v['text'] ?? ''),
                'blob' => self::blob((string)($v['blob'] ?? ''), $n),
                default => throw new InvalidArgumentException("sqlite: parameter $n: unknown value type " . json_encode($v['type'] ?? null)),
            };
        }
        return $args;
    }

    /** A BLOB from its base64, as JSON carries Go's []byte. */
    private static function blob(string $b64, int $n): Blob
    {
        $b = base64_decode($b64, true);
        if ($b === false) {
            throw new InvalidArgumentException("sqlite: parameter $n: invalid base64");
        }
        return new Blob($b);
    }

    /** A signed 64-bit integer from its decimal string, as Go's strconv.ParseInt. */
    private static function integer(string $s, int $n): int
    {
        if (preg_match('/^[+-]?\d+$/', $s) !== 1 || ($i = filter_var($s, FILTER_VALIDATE_INT)) === false) {
            throw new InvalidArgumentException("sqlite: parameter $n: invalid signed 64-bit integer");
        }
        return $i;
    }

    /** @return array{changes: int, lastInsertId: string} */
    private static function resultWire(Result $r): array
    {
        return ['changes' => $r->changes, 'lastInsertId' => (string)$r->lastInsertId];
    }

    /** @return array{columns: list<string>, rows: list<list<array<string, mixed>>>} */
    private static function rowsWire(Rows $rows): array
    {
        $out = [];
        foreach ($rows->values as $row) {
            $cells = [];
            foreach ($row as $cell) {
                $cells[] = match (true) {
                    is_int($cell) => ['type' => 'integer', 'integer' => (string)$cell],
                    is_float($cell) && is_nan($cell) => ['type' => 'real', 'special' => 'NaN'],
                    is_float($cell) && is_infinite($cell) => ['type' => 'real', 'special' => $cell > 0 ? 'Infinity' : '-Infinity'],
                    is_float($cell) => ['type' => 'real', 'real' => $cell],
                    is_string($cell) => ['type' => 'text', 'text' => $cell],
                    $cell instanceof Blob => ['type' => 'blob', 'blob' => base64_encode($cell->bytes)],
                    default => ['type' => 'null'],
                };
            }
            $out[] = $cells;
        }
        return ['columns' => $rows->columns, 'rows' => $out];
    }
}
