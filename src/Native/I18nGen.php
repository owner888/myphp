<?php
declare(strict_types=1);

namespace MyPHP\Native;

use MyPHP\{I18n, Message, Plural, SyntaxError};

/**
 * Reads the catalogs of an app's messages, checks them, and writes their
 * typed accessors (mygo's internal/i18ngen): a PHP class in the catalogs'
 * directory, which registers them as it is required, and a TypeScript
 * module that formats the same messages in pages. `myphp generate`,
 * `myphp dev`, `myphp build` and `myphp i18n check` use it.
 *
 * A catalog is an array: source, namespace, languages (the source first,
 * then the others sorted), keys (the source language's, in the order of
 * its file: name, message, description, params of [name, type, php, ts])
 * and files by language (name, keys, messages, parsed, lines).
 * Diagnostics are [file, line, warning, msg], line 0 for a whole file's.
 *
 * @internal
 */
final class I18nGen
{
    /** The types of placeholders, as ARB's, in PHP and in TypeScript. */
    private const TYPES = ['String' => ['string', 'string'], 'int' => ['int', 'number'], 'double' => ['float', 'number'], 'num' => ['int|float', 'number'], 'DateTime' => ['\DateTimeInterface', 'Date'], 'Object' => ['mixed', 'unknown']];
    /** What a key is: a letter, then letters, digits and _, as Flutter's gen_l10n has them, which accessors are named after. */
    private const KEY = '/^[A-Za-z][A-Za-z0-9_]*$/';
    /** Keys of the namespace myphp that no message of MyPHP's has: the name and the description of the app in a language, which `myphp build` writes into the bundle's localizations. */
    public const PACKAGING_KEYS = ['appName', 'appDescription'];
    /** The methods the generated classes declare, which no key may name (PHP's names of methods ignore case). */
    private const TAKEN = ['in', 'catalog', '__construct'];
    private const TS_RESERVED = ['arguments', 'await', 'break', 'case', 'catch', 'class', 'const', 'continue', 'debugger', 'default', 'delete', 'do', 'else', 'enum', 'eval', 'export', 'extends', 'false', 'finally', 'for', 'function', 'if', 'implements', 'import', 'in', 'instanceof', 'interface', 'let', 'new', 'null', 'package', 'private', 'protected', 'public', 'return', 'static', 'super', 'switch', 'this', 'throw', 'true', 'try', 'typeof', 'var', 'void', 'while', 'with', 'yield'];

    private array $diags = [];
    private array $c;
    private array $meta = [];

    /** @param array<string, array> $libraries the catalogs of libraries whose messages an app's keys "<namespace>.<key>" change, by namespace */
    private function __construct(private readonly string $dir, private readonly string $source, private readonly string $namespace, private readonly array $libraries) {}

    /**
     * Reads the catalogs in $path, "<tag>.json", and checks them; $dir
     * names their directory in diagnostics, as "locales". Problems of their
     * contents are diagnostics, and a catalog with errors is still
     * returned, with what could be read.
     *
     * @param array<string, array> $libraries
     * @return array{array, list<array>}
     */
    public static function load(string $path, string $dir, string $source = 'en', string $namespace = '', array $libraries = []): array
    {
        $l = new self($dir, $source, $namespace, $libraries);
        $l->c = ['source' => '', 'namespace' => $namespace, 'languages' => [], 'keys' => [], 'files' => []];
        $src = I18n::normalize($source);
        if ($src === '' || $src !== $source || I18n::isPseudo($src)) {
            $l->error($dir, 0, sprintf('the source language "%s" is not a BCP 47 tag in its canonical form (as en, de-CH or zh-Hans)', $source));
            return [$l->c, $l->diags];
        }
        $l->c['source'] = $src;
        $names = glob(rtrim($path, '/') . '/*.json') ?: [];
        sort($names, SORT_STRING);
        foreach ($names as $file) {
            $name = basename($file);
            $tag = substr($name, 0, -5);
            $display = "$dir/$name";
            if (($norm = I18n::normalize($tag)) !== $tag) {
                $l->error($display, 0, 'the name of a catalog is a BCP 47 tag in its canonical form, as en.json or zh-Hant.json' . ($norm !== '' ? ": rename it $norm.json" : ''));
                continue;
            }
            if (I18n::isPseudo($tag)) {
                $l->error($display, 0, "$tag is a pseudo locale, made from the source language: remove the file");
                continue;
            }
            $f = $l->readFile($display, $tag, (string)file_get_contents($file));
            if ($f !== null) { $l->c['files'][$tag] = $f; }
        }
        if (!isset($l->c['files'][$src])) {
            $l->error("$dir/$src.json", 0, "missing: it holds the messages of the source language, $src");
            return [$l->c, $l->sorted()];
        }
        $others = array_values(array_filter(array_keys($l->c['files']), fn(string $t) => $t !== $src));
        sort($others, SORT_STRING);
        $l->c['languages'] = [$src, ...$others];
        $l->sourceKeys($l->c['files'][$src]);
        foreach ($l->c['languages'] as $tag) { $l->checkFile($l->c['files'][$tag]); }
        return [$l->c, $l->sorted()];
    }

    /** The errors of diagnostics, as lines; [] without any. @return list<string> */
    public static function errors(array $diags): array
    {
        return array_values(array_map(self::line(...), array_filter($diags, fn(array $d) => !$d[2])));
    }

    /** The warnings of diagnostics, as lines. @return list<string> */
    public static function warnings(array $diags): array
    {
        return array_values(array_map(self::line(...), array_filter($diags, fn(array $d) => $d[2])));
    }

    /** A diagnostic as a line: "locales/de.json:3: warning: …". */
    public static function line(array $d): string
    {
        return $d[0] . ($d[1] > 0 ? ":$d[1]" : '') . ($d[2] ? ': warning: ' : ': ') . $d[3];
    }

    private function error(string $file, int $line, string $msg): void { $this->diags[] = [$file, $line, false, $msg]; }
    private function warn(string $file, int $line, string $msg): void { $this->diags[] = [$file, $line, true, $msg]; }

    /** The diagnostics by file, then by line, those of a whole file last. */
    private function sorted(): array
    {
        $d = $this->diags;
        $i = 0;
        foreach ($d as &$x) { $x[] = $i++; }
        unset($x);
        usort($d, fn($a, $b) => [$a[0], $a[1] ?: PHP_INT_MAX, $a[4]] <=> [$b[0], $b[1] ?: PHP_INT_MAX, $b[4]]);
        return array_map(fn($x) => array_slice($x, 0, 4), $d);
    }

    /** Reads a catalog: an object of messages, metadata and @@-attributes, in order, with the lines of its keys. */
    private function readFile(string $name, string $tag, string $data): ?array
    {
        $f = ['tag' => $tag, 'name' => $name, 'keys' => [], 'messages' => [], 'parsed' => [], 'lines' => []];
        try {
            $members = self::members($data);
        } catch (\JsonException $e) {
            $this->error($name, $e->getCode(), $e->getMessage());
            return null;
        }
        if ($members === null) {
            $this->error($name, 0, 'not a JSON object of messages');
            return null;
        }
        $meta = [];
        foreach ($members as [$key, $raw, $at]) {
            if (isset($f['lines'][$key])) {
                $this->error($name, $at, json_encode($key, JSON_UNESCAPED_UNICODE) . ' comes twice');
                continue;
            }
            $f['lines'][$key] = $at;
            $q = json_encode($key, JSON_UNESCAPED_UNICODE);
            if ($key === '@@locale') {
                if (!is_string($v = json_decode($raw)) || I18n::normalize($v) !== $tag) { $this->error($name, $at, "@@locale $raw is not $tag, the language the file is named after"); }
            } elseif (str_starts_with($key, '@@')) {
                // Other attributes of the file, as @@last_modified.
            } elseif (str_starts_with($key, '@')) {
                $meta[substr($key, 1)] = $raw;
            } elseif (!is_string($msg = json_decode($raw))) {
                $this->error($name, $at, "$q is not a string");
            } else {
                $f['keys'][] = $key;
                $f['messages'][$key] = $msg;
                try {
                    $f['parsed'][$key] = Message::parse($msg);
                } catch (SyntaxError $e) {
                    $this->error($name, $at, "$q does not parse: $e->error");
                }
            }
        }
        if ($tag === $this->source) { $this->meta = $meta; } // translations may carry metadata, which tools copy; the source's is the one that counts
        return $f;
    }

    /**
     * The members of a JSON object, in order, duplicates too: [key, the
     * raw JSON of the value, the line of the key]; null for JSON that is
     * no object. Throws a JsonException, whose code is the line, for JSON
     * that does not parse.
     *
     * @return ?list<array{string, string, int}>
     */
    private static function members(string $data): ?array
    {
        $line = fn(int $at) => 1 + substr_count($data, "\n", 0, min($at, strlen($data)));
        $ws = fn(int $i) => $i + strspn($data, " \t\r\n", $i);
        $string = '"(?:[^"\\\\\x00-\x1f]|\\\\(?:["\\\\\/bfnrt]|u[0-9a-fA-F]{4}))*"';
        $i = $ws(0);
        if (($data[$i] ?? '') !== '{') {
            try { json_decode($data, flags: JSON_THROW_ON_ERROR); } catch (\JsonException $e) { throw new \JsonException($e->getMessage(), 1); }
            return null;
        }
        $out = [];
        $i = $ws($i + 1);
        if (($data[$i] ?? '') === '}') { $i++; }
        else {
            while (true) {
                if (!preg_match("/\\G$string/", $data, $m, 0, $i)) { throw new \JsonException('expected the name of a member', $line($i)); }
                $key = json_decode($m[0]); $at = $line($i);
                $i = $ws($i + strlen($m[0]));
                if (($data[$i] ?? '') !== ':') { throw new \JsonException('expected :', $line($i)); }
                $start = $i = $ws($i + 1);
                $i = self::skipValue($data, $i, $string, $line);
                $raw = substr($data, $start, $i - $start);
                try { json_decode($raw, flags: JSON_THROW_ON_ERROR); } catch (\JsonException $e) { throw new \JsonException($e->getMessage(), $line($start)); }
                $out[] = [(string)$key, $raw, $at];
                $i = $ws($i);
                if (($data[$i] ?? '') === ',') { $i = $ws($i + 1); continue; }
                if (($data[$i] ?? '') === '}') { $i++; break; }
                throw new \JsonException('expected , or }', $line($i));
            }
        }
        if ($ws($i) < strlen($data)) { throw new \JsonException('text after the object', $line($i)); }
        return $out;
    }

    /** The offset after the JSON value at $i: a string, or anything up to the , or } that ends it, nested ones skipped whole. */
    private static function skipValue(string $data, int $i, string $string, \Closure $line): int
    {
        $depth = 0; $n = strlen($data);
        while ($i < $n) {
            $c = $data[$i];
            if ($c === '"') {
                if (!preg_match("/\\G$string/", $data, $m, 0, $i)) { throw new \JsonException('a string does not end', $line($i)); }
                $i += strlen($m[0]);
                if ($depth === 0) { return $i; }
                continue;
            }
            if ($c === '{' || $c === '[') { $depth++; }
            elseif ($c === '}' || $c === ']') {
                if ($depth === 0) { return $i; }
                if (--$depth === 0) { return $i + 1; }
            } elseif ($c === ',' && $depth === 0) { return $i; }
            $i++;
        }
        throw new \JsonException('the object does not end', $line($i));
    }

    /** Reads the keys of the source language and their parameters. */
    private function sourceKeys(array $f): void
    {
        $taken = array_fill_keys(self::TAKEN, '');
        foreach ($f['keys'] as $key) {
            $at = $f['lines'][$key];
            if ($this->override($key)) { continue; }
            $q = json_encode($key, JSON_UNESCAPED_UNICODE);
            if (!preg_match(self::KEY, $key)) {
                $this->error($f['name'], $at, "$q is not a key: a letter, then letters, digits and _, as filesSelected");
                continue;
            }
            $lower = strtolower($key);
            if (isset($taken[$lower])) {
                $this->error($f['name'], $at, $taken[$lower] === '' ? "$q would name its PHP method $key, which the generated class declares" : sprintf('"%s" and %s would both name their PHP method %s (PHP\'s names of methods ignore case)', $taken[$lower], $q, $key));
                continue;
            }
            $taken[$lower] = $key;
            $this->c['keys'][] = $this->readMeta($f, $key);
        }
        foreach ($this->meta as $key => $_) {
            if (!array_key_exists($key, $f['messages'])) { $this->warn($f['name'], $f['lines']["@$key"] ?? 0, "@$key describes no message"); }
        }
    }

    /** Whether a key changes a library's message, "<namespace>.<key>". */
    private function override(string $key): bool
    {
        $ns = strstr($key, '.', true);
        return $ns !== false && $this->namespace === '' && $ns !== '' && preg_match(self::KEY, $ns) === 1;
    }

    /** A key of the source: its description and placeholders, and its parameters, the placeholders in their order, then the other arguments of the message in theirs, typed by what they declare or how the message uses them. */
    private function readMeta(array $f, string $key): array
    {
        $at = $f['lines']["@$key"] ?? $f['lines'][$key];
        $k = ['name' => $key, 'message' => $f['messages'][$key], 'description' => '', 'params' => []];
        $declared = []; $declaredType = [];
        if (isset($this->meta[$key])) {
            $meta = json_decode($this->meta[$key], true);
            if (!is_array($meta) || array_is_list($meta) && $meta !== []) {
                $this->error($f['name'], $at, "@$key: not an object");
                $meta = [];
            }
            $k['description'] = is_string($meta['description'] ?? null) ? $meta['description'] : '';
            $placeholders = $meta['placeholders'] ?? null;
            if ($placeholders !== null) {
                if (!is_array($placeholders) || array_is_list($placeholders) && $placeholders !== []) {
                    $this->error($f['name'], $at, "@$key.placeholders: not an object");
                    $placeholders = [];
                }
                foreach ($placeholders as $name => $p) {
                    $name = (string)$name;
                    if (!is_array($p)) { $this->error($f['name'], $at, "@$key.placeholders.$name: not an object"); continue; }
                    $type = is_string($p['type'] ?? null) ? $p['type'] : '';
                    if ($type !== '' && !isset(self::TYPES[$type])) {
                        $this->error($f['name'], $at, "@$key.placeholders.$name: type \"$type\" is not String, int, double, num, DateTime or Object");
                        $type = '';
                    }
                    $declared[] = $name;
                    $declaredType[$name] = $type;
                }
            }
        }
        $m = $f['parsed'][$key] ?? null;
        $kinds = [];
        foreach ($m?->args() ?? [] as $a) { $kinds[$a->name] = $a->kind; }
        $names = $declared;
        foreach (array_keys($kinds) as $n) { if (!in_array((string)$n, $names, true)) { $names[] = (string)$n; } }
        foreach ($names as $name) {
            $used = array_key_exists($name, $kinds);
            $kind = $kinds[$name] ?? '';
            if (!$used && $m !== null) { $this->warn($f['name'], $at, "@$key declares the placeholder $name, which the message does not use"); }
            $type = $declaredType[$name] ?? '';
            if ($type === '') { $type = self::inferType($kind); }
            elseif (!self::compatible($type, $kind)) { $this->error($f['name'], $f['lines'][$key], "\"$key\" uses $name as a $kind argument, which its type $type cannot be"); }
            $k['params'][] = ['name' => $name, 'type' => $type, 'php' => self::phpParam($name), 'ts' => self::tsParam($name)];
        }
        if ($m !== null) { $this->checkPlurals($f, $key, $m); }
        return $k;
    }

    /** The type of an argument without a declared one: a number for plural, selectordinal and number arguments, a time for date and time ones, a string otherwise. */
    private static function inferType(string $kind): string
    {
        return match ($kind) { 'plural', 'selectordinal', 'number' => 'int', 'date', 'time' => 'DateTime', default => 'String' };
    }

    /** Whether an argument of a type can be used as one of a kind. */
    private static function compatible(string $type, string $kind): bool
    {
        return match ($kind) {
            'plural', 'selectordinal', 'number' => in_array($type, ['int', 'double', 'num', 'Object'], true),
            'date', 'time' => in_array($type, ['DateTime', 'Object'], true),
            'select' => in_array($type, ['String', 'Object'], true),
            default => true,
        };
    }

    /** Checks a file against the source language: its keys, their arguments and their plural forms. */
    private function checkFile(array $f): void
    {
        $src = $this->c['files'][$this->c['source']];
        $params = array_column($this->c['keys'], 'params', 'name');
        foreach ($f['keys'] as $key) {
            if ($this->override($key)) { $this->checkOverride($f, $key); continue; }
            if ($f === $src) { continue; }
            if (!array_key_exists($key, $params)) {
                if (!array_key_exists($key, $src['messages'])) { $this->error($f['name'], $f['lines'][$key], json_encode($key, JSON_UNESCAPED_UNICODE) . " is not a key of {$src['name']}"); }
                continue; // else a bad key, reported in the source
            }
            if (($m = $f['parsed'][$key] ?? null) !== null) {
                $this->checkArgs($f, $key, $m, $params[$key]);
                $this->checkPlurals($f, $key, $m);
            }
        }
        if ($f === $src) { return; }
        $missing = array_values(array_filter(array_column($this->c['keys'], 'name'), fn(string $k) => !array_key_exists($k, $f['messages'])));
        if ($missing !== []) {
            $list = count($missing) > 8 ? implode(', ', array_slice($missing, 0, 8)) . sprintf(' and %d more', count($missing) - 8) : implode(', ', $missing);
            $this->warn($f['name'], 0, sprintf('%d of %d messages missing, which say the language they fall back to: %s', count($missing), count($this->c['keys']), $list));
        }
    }

    /** Checks the arguments of a translation against the parameters of its key. */
    private function checkArgs(array $f, string $key, Message $m, array $params): void
    {
        $at = $f['lines'][$key];
        $args = $m->args();
        $names = array_column($params, 'name');
        $types = array_column($params, 'type', 'name');
        foreach ($args as $a) {
            if (!in_array($a->name, $names, true)) {
                $this->error($f['name'], $at, "\"$key\" uses the argument $a->name, but the source message has " . ($names === [] ? 'no arguments' : 'the arguments ' . implode(', ', $names)));
                continue;
            }
            if (!self::compatible($types[$a->name], $a->kind)) { $this->error($f['name'], $at, "\"$key\" uses $a->name as a $a->kind argument, which its type {$types[$a->name]} cannot be"); }
        }
        $used = array_map(fn($a) => $a->name, $args);
        foreach ($names as $n) {
            if (!in_array($n, $used, true)) { $this->warn($f['name'], $at, "\"$key\" does not use the argument $n"); }
        }
    }

    /** Warns of the cases of plural and selectordinal arguments that the language's rules do not have, and of those it lacks. */
    private function checkPlurals(array $f, string $key, Message $m): void
    {
        $at = $f['lines'][$key];
        foreach ($m->args() as $a) {
            if ($a->kind !== 'plural' && $a->kind !== 'selectordinal') { continue; }
            [$have, $kind] = $a->kind === 'plural' ? [I18n::pluralCategories($f['tag']), 'plural'] : [I18n::ordinalCategories($f['tag']), 'ordinal'];
            $haveNames = array_map(fn(Plural $p) => $p->value, $have);
            $cases = $m->cases($a->name);
            $extra = array_values(array_filter($cases, fn(string $c) => Plural::tryFrom($c) !== null && !in_array($c, $haveNames, true)));
            $lacking = array_values(array_filter($haveNames, fn(string $p) => !in_array($p, $cases, true)));
            if ($extra !== []) {
                $this->warn($f['name'], $at, sprintf('"%s": %s has no %s %s of %s (its %s categories are %s)', $key, $f['tag'], $kind, count($extra) === 1 ? 'category' : 'categories', implode(', ', $extra), $kind, implode(', ', $haveNames)));
            }
            if ($lacking !== []) {
                $this->warn($f['name'], $at, sprintf('"%s": the %s argument %s lacks %s, a %s category of %s, which says other in its place', $key, $a->kind, $a->name, implode(', ', $lacking), $kind, $f['tag']));
            }
        }
    }

    /** Checks a key that changes a library's message. */
    private function checkOverride(array $f, string $key): void
    {
        $at = $f['lines'][$key];
        [$ns, $name] = explode('.', $key, 2);
        if (!isset($this->libraries[$ns])) {
            $this->error($f['name'], $at, "\"$key\": no library has the namespace $ns");
            return;
        }
        $lib = null;
        foreach ($this->libraries[$ns]['keys'] as $k) { if ($k['name'] === $name) { $lib = $k; } }
        if ($lib === null && !($ns === 'myphp' && in_array($name, self::PACKAGING_KEYS, true))) {
            $this->error($f['name'], $at, "\"$key\": $ns has no message $name");
            return;
        }
        if (($m = $f['parsed'][$key] ?? null) !== null) {
            $this->checkArgs($f, $key, $m, $lib['params'] ?? []);
            $this->checkPlurals($f, $key, $m);
        }
    }

    /** The name of the class of the accessors of the catalogs in $dir: its name's letters and digits, capitalized at each word, as Locales for locales, or Locales. */
    public static function className(string $dir): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', ltrim(basename($dir), '0123456789'), -1, PREG_SPLIT_NO_EMPTY);
        $name = ltrim(implode('', array_map(ucfirst(...), $words)), '0123456789');
        return $name === '' ? 'Locales' : $name;
    }

    private static function phpParam(string $name): string
    {
        if (ctype_digit($name[0])) { return "arg$name"; }
        return $name === 'this' ? 'this_' : (string)preg_replace('/[^A-Za-z0-9_\x80-\xff]/', '_', $name);
    }

    private static function tsParam(string $name): string
    {
        if (ctype_digit($name[0])) { return "arg$name"; }
        return in_array($name, self::TS_RESERVED, true) ? "{$name}_" : $name;
    }

    /**
     * The PHP accessors of an app's catalog, the source of <Class>.php in
     * the catalogs' directory (mygo's catalog_gen.go, whose package is a
     * class here): the class $class, named after the directory, whose
     * static method per key says its message in the language the app shows
     * (I18n::locale), as Locales::filesSelected(3), and whose in() says
     * them in another, as Locales::in('de')->filesSelected(3); the catalog
     * is registered as the file is required, which makes its languages the
     * app's.
     */
    public static function php(array $c, string $class): string
    {
        $in = "{$class}Messages";
        $doc = sprintf('The messages of the app in %s, the source language, and %s; registered as this file is required, which makes their languages the app\'s.', $c['source'], self::languageList(array_slice($c['languages'], 1)));
        $o = "<?php\n// Code generated by myphp generate from the catalogs in this directory. DO NOT EDIT.\ndeclare(strict_types=1);\n\n";
        $o .= self::comment($doc, '') . "final class $class\n{\n    public static \\MyPHP\\Catalog \$catalog;\n\n";
        $o .= "    /** The messages in the language of \$locale, as \"de\" or \"zh-Hant\", or of the catalog's language that suits it best; '' for the source language. */\n";
        $o .= "    public static function in(string \$locale): $in\n    {\n        return new $in(\$locale);\n    }\n";
        $methods = '';
        foreach ($c['keys'] as $k) {
            [$params, $names, $values] = self::phpParams($k);
            $o .= "\n" . self::methodDoc($k, 'the language the app shows') . "    public static function {$k['name']}($params): string\n    {\n        return self::in(\\MyPHP\\I18n::locale())->{$k['name']}($names);\n    }\n";
            $methods .= "\n" . self::methodDoc($k, 'the language of these messages') . "    public function {$k['name']}($params): string\n    {\n        return $class::\$catalog->format(\$this->locale, " . var_export($k['name'], true) . ", [$values]);\n    }\n";
        }
        $o .= "}\n\n/** The messages of $class in one language. */\nfinal class $in\n{\n    public function __construct(private readonly string \$locale) {}\n$methods}\n\n";
        $o .= "$class::\$catalog = new \\MyPHP\\Catalog(__DIR__, " . var_export($c['source'], true) . ");\n\\MyPHP\\I18n::register($class::\$catalog);\n";
        return $o;
    }

    /** @return array{string, string, string} a key's parameters, the arguments that pass them on, and the array of its message's arguments */
    private static function phpParams(array $k): array
    {
        $params = $names = $values = [];
        foreach ($k['params'] as $p) {
            $params[] = self::TYPES[$p['type']][0] . ' $' . $p['php'];
            $names[] = '$' . $p['php'];
            $values[] = var_export($p['name'], true) . ' => $' . $p['php'];
        }
        return [implode(', ', $params), implode(', ', $names), implode(', ', $values)];
    }

    private static function methodDoc(array $k, string $where): string
    {
        $msg = $k['message'];
        if (strlen($msg) > 120) { $msg = mb_strcut($msg, 0, 117) . '…'; }
        $text = 'Says ' . json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . " in $where.";
        if (($desc = trim((string)preg_replace('/\s+/', ' ', $k['description']))) !== '') { $text .= "\n\n" . $desc; }
        return self::comment($text, '    ');
    }

    /** Text as a PHP doc comment, its lines wrapped at 76 columns; a blank line of the text is a paragraph's end. */
    private static function comment(string $text, string $indent): string
    {
        $text = str_replace('*/', '*\/', $text);
        $out = "$indent/**\n";
        foreach (explode("\n\n", $text) as $i => $para) {
            if ($i > 0) { $out .= "$indent *\n"; }
            $line = "$indent *";
            foreach (preg_split('/\s+/', trim($para)) as $word) {
                if (strlen($line) > strlen("$indent *") && strlen($line) + 1 + strlen($word) > 76) { $out .= "$line\n"; $line = "$indent *"; }
                $line .= " $word";
            }
            $out .= "$line\n";
        }
        return "$out$indent */\n";
    }

    private static function languageList(array $langs): string
    {
        return match (count($langs)) { 0 => 'no other language', 1 => $langs[0], default => implode(', ', array_slice($langs, 0, -1)) . ' and ' . end($langs) };
    }

    /**
     * The TypeScript module of a catalog, which pages import (mygo's TS):
     * built on the formatter of messages of myphp-runtime/i18n
     * (packages/runtime/src/i18n.ts), the messages of every language, which
     * it formats in the language the page shows, and a typed function per
     * key. The messages are in the
     * module, not fetched: the first paint is in the right language
     * without a round trip.
     */
    public static function ts(array $c): string
    {
        $o = "// Code generated by myphp generate from the catalogs of the app. DO NOT EDIT.\nimport { createCatalog } from \"" . \MyPHP\TypeScript::RUNTIME_PACKAGE . "/i18n\";\n\n";
        $o .= "/** The messages of the app in one language. */\nexport interface Messages {\n";
        foreach ($c['keys'] as $k) {
            $params = array_map(fn($p) => $p['ts'] . ': ' . self::TYPES[$p['type']][1], $k['params']);
            $o .= self::tsDoc($k) . "  {$k['name']}(" . implode(', ', $params) . "): string;\n";
        }
        $o .= "}\n\nconst catalog = createCatalog<Messages>({\n";
        $o .= '  source: ' . self::js($c['source']) . ",\n  languages: " . self::js($c['languages']) . ",\n  fallbacks: {\n";
        foreach ($c['languages'] as $tag) { $o .= '    ' . self::js($tag) . ': ' . self::js(self::fallbacks($c, $tag)) . ",\n"; }
        $o .= "  },\n  params: {\n";
        foreach ($c['keys'] as $k) { $o .= '    ' . self::js($k['name']) . ': ' . self::js(array_column($k['params'], 'name')) . ",\n"; }
        $o .= "  },\n  messages: {\n";
        foreach ($c['languages'] as $tag) {
            $f = $c['files'][$tag];
            $o .= '    ' . self::js($tag) . ": {\n";
            foreach ($c['keys'] as $k) {
                if (isset($f['parsed'][$k['name']])) { $o .= '      ' . self::js($k['name']) . ': ' . self::js($f['messages'][$k['name']]) . ",\n"; }
            }
            $o .= "    },\n";
        }
        $o .= "  },\n});\n\n";
        $o .= <<<'TS'
            /**
             * The messages in the language the page shows: the app's, else the
             * browser's. Each call says its message in the language of the moment.
             */
            export const messages: Messages = catalog.messages();

            /** Returns the messages in the language of locale, as "de" or "zh-Hant". */
            export function messagesIn(locale: string): Messages {
              return catalog.messagesIn(locale);
            }

            /** The languages of the catalogs: the source language, then the others. */
            export const languages: readonly string[] = catalog.languages;

            TS;
        return $o;
    }

    /** The languages of the catalog a message in $tag is looked up in: the tag, its CLDR parents the catalog has, then the source language. @return list<string> */
    public static function fallbacks(array $c, string $tag): array
    {
        $out = [];
        for ($t = $tag; $t !== ''; $t = I18n::parent($t)) {
            if (in_array($t, $c['languages'], true) && !in_array($t, $out, true)) { $out[] = $t; }
        }
        if (!in_array($c['source'], $out, true)) { $out[] = $c['source']; }
        return $out;
    }

    private static function tsDoc(array $k): string
    {
        $o = "  /**\n";
        $desc = trim($k['description']);
        foreach (explode("\n", $desc) as $line) {
            if (($line = trim($line)) !== '') { $o .= '   * ' . str_replace('*/', '*\/', $line) . "\n"; }
        }
        if ($desc !== '') { $o .= "   *\n"; }
        return $o . '   * `' . strtr($k['message'], ['*/' => '*\/', '`' => '\`', "\n" => ' ']) . "`\n   */\n";
    }

    /** A string, or a list of strings, as JavaScript: JSON without escaping slashes or Unicode (but the line separators older JavaScript did not allow in strings). */
    private static function js(string|array $v): string
    {
        if (is_array($v)) { return '[' . implode(', ', array_map(self::js(...), $v)) . ']'; }
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
