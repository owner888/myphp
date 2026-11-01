<?php
declare(strict_types=1);

namespace MyPHP\Media;

/**
 * A filter as mpv writes one in its vf and af lists (IINA's MPVFilter): an
 * optional label (`@label:`), its name, and its parameters, as their
 * string (`name=a:b`, `lavfi=[graph]`) or by name. Its string is mpv's
 * (string()), the parameters of crop and expand in their order
 * (w:h:x:y), of others by name; two filters are the same of the same
 * label, name and parameters, whatever their order (equals()).
 *
 * MyPHP has no mpv: what a filter does is VideoFilters' and
 * AudioFilters', of the names they know.
 */
final class Filter
{
    /** The order of the parameters given without their names (MPVFilter.formats). */
    private const FORMATS = ['crop' => ['w', 'h', 'x', 'y'], 'expand' => ['w', 'h', 'x', 'y', 'aspect', 'round']];
    /** Filters whose parameters are not parsed by name (MPVFilter.doNotParse). */
    private const UNPARSED = ['crop', 'flip', 'hflip', 'lavfi'];

    /**
     * @param ?array<string, string> $params by name
     * @param ?string $raw the parameters as they were written, which the string keeps
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $label = null,
        public readonly ?array $params = null,
        public readonly ?string $raw = null,
    ) {}

    /**
     * The filter $s writes (`[@label:]name[=params]`), as IINA reads one
     * (MPVFilter(rawString:)); null of one that is no filter.
     */
    public static function parse(string $s): ?self
    {
        $parts = explode('=', $s, 2);
        $name = $parts[0];
        $label = null;
        if ($name === '') {
            return null;
        }
        if ($name[0] === '@') {
            $at = explode(':', substr($name, 1), 2);
            if (count($at) !== 2 || $at[0] === '' || $at[1] === '') {
                return null;
            }
            [$label, $name] = $at;
        }
        $raw = isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null;
        return new self($name, $label, self::parseParams($name, $raw), $raw);
    }

    /** A filter of $name with $params by name (or by their place, "0" the first, of crop and expand). @param array<string|int, string> $params */
    public static function of(string $name, array $params = [], ?string $label = null): self
    {
        $format = self::FORMATS[$name] ?? null;
        $named = [];
        foreach ($params as $k => $v) {
            $named[$format !== null && is_int($k) ? $format[$k] : (string)$k] = (string)$v;
        }
        return new self($name, $label, $named === [] ? null : $named);
    }

    /** A filter of FFmpeg's, mpv's lavfi of the graph `[name=params]`, the parameters by place (a list) or by name. @param array<int|string, string> $params */
    public static function lavfi(string $name, array $params, ?string $label = null): self
    {
        $p = array_is_list($params) ? implode(':', $params) : implode(':', array_map(fn($k, $v) => "$k=$v", array_keys($params), $params));
        return new self('lavfi', $label, null, "[$name" . ($p !== '' ? "=$p" : '') . ']');
    }

    /** FFmpeg's unsharp of $amount (luma and chroma, negative blurs) and matrix $msize (IINA's sharpen and blur). */
    public static function unsharp(float $amount, int $msize = 5): self
    {
        $a = self::number($amount);
        return self::lavfi('unsharp', ["$msize", "$msize", $a, "$msize", "$msize", $a]);
    }

    /** The filter's string, as mpv writes it (MPVFilter.stringFormat). */
    public function string(): string
    {
        $s = ($this->label !== null ? "@{$this->label}:" : '') . $this->name;
        if ($this->raw !== null) {
            return "$s={$this->raw}";
        }
        if ($this->params === null || $this->params === []) {
            return $s;
        }
        $format = self::FORMATS[$this->name] ?? null;
        if ($format !== null) {
            return $s . '=' . implode(':', array_map(fn(string $k) => $this->params[$k] ?? '', $format));
        }
        return $s . '=' . implode(':', array_map(fn($k, $v) => "$k=" . self::quoted($v), array_keys($this->params), $this->params));
    }

    /** Whether $o is this filter: of the same label and name, and parameters by name where both have them, else the same string (MPVFilter.isEqual). */
    public function equals(self $o): bool
    {
        if ($this->label !== $o->label || $this->name !== $o->name) {
            return false;
        }
        if ($this->params !== null && $o->params !== null) {
            $a = $this->params; $b = $o->params;
            ksort($a); ksort($b);
            return $a === $b;
        }
        return $this->string() === $o->string();
    }

    /**
     * The FFmpeg filter of a lavfi's graph `[name=params]` (one filter):
     * its name and parameters, by name or place ("0", "1"…); null of none
     * or of a graph of more.
     * @return ?array{0: string, 1: array<string, string>}
     */
    public function graph(): ?array
    {
        if ($this->name !== 'lavfi' || $this->raw === null || preg_match('/^\[([^\[\],;]+)\]$/', $this->raw, $m) !== 1) {
            return null;
        }
        $at = explode('=', $m[1], 2);
        return [trim($at[0]), self::byName($at[1] ?? '')];
    }

    /**
     * What the filter is, FFmpeg's or mpv's: its name and its parameters
     * by name or place ("0", "1"…), a lavfi's of its graph's one filter.
     * @return ?array{0: string, 1: array<string, string>}
     */
    public function what(): ?array
    {
        if ($this->name === 'lavfi') {
            return $this->graph();
        }
        if ($this->params !== null) {
            return [$this->name, $this->params];
        }
        return [$this->name, self::byName($this->raw ?? '', self::FORMATS[$this->name] ?? [])];
    }

    /**
     * Parameters $s (`a:b`, `k=v:k=v`, mpv's %n% quoting undone) by name,
     * those without one by their place ($format's name of it, else "0",
     * "1"…).
     * @param list<string> $format
     * @return array<string, string>
     */
    public static function byName(string $s, array $format = []): array
    {
        $out = [];
        if ($s === '') {
            return $out;
        }
        foreach (self::split($s) as $i => $p) {
            $kv = explode('=', $p, 2);
            if (count($kv) === 2 && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $kv[0]) === 1) {
                $out[$kv[0]] = self::unquoted($kv[1]);
            } else {
                $out[$format[$i] ?? (string)$i] = self::unquoted($p);
            }
        }
        return $out;
    }

    /** $s split at its colons, but within mpv's %n% quotes and FFmpeg's '…' and [ ]. @return list<string> */
    private static function split(string $s): array
    {
        $out = []; $cur = ''; $depth = 0; $quote = false;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $ch = $s[$i];
            if (!$quote && $ch === '%' && preg_match('/\G%(\d+)%/', $s, $m, 0, $i) === 1) { // %n%: n bytes as they are
                $len = (int)$m[1];
                $cur .= substr($s, $i, strlen($m[0]) + $len);
                $i += strlen($m[0]) + $len - 1;
                continue;
            }
            if ($ch === "'") { $quote = !$quote; }
            elseif (!$quote && $ch === '[') { $depth++; }
            elseif (!$quote && $ch === ']') { $depth--; }
            if ($ch === ':' && !$quote && $depth === 0) { $out[] = $cur; $cur = ''; continue; }
            $cur .= $ch;
        }
        $out[] = $cur;
        return $out;
    }

    /** $v of mpv's %n% or FFmpeg's '…' undone. */
    private static function unquoted(string $v): string
    {
        if (preg_match('/^%(\d+)%(.*)$/s', $v, $m) === 1) {
            return substr($m[2], 0, (int)$m[1]);
        }
        return strlen($v) >= 2 && $v[0] === "'" && $v[-1] === "'" ? substr($v, 1, -1) : $v;
    }

    /** Parameters $raw of filter $name by name, where they are more than one of names (MPVFilter.parseRawParamString); else null. @return ?array<string, string> */
    private static function parseParams(string $name, ?string $raw): ?array
    {
        if ($raw === null || in_array($name, self::UNPARSED, true)) {
            return null;
        }
        $pairs = explode(':', $raw);
        if (count($pairs) < 2) {
            return null;
        }
        $out = [];
        foreach ($pairs as $p) {
            $kv = explode('=', $p, 2);
            if (count($kv) !== 2) {
                return null;
            }
            $out[$kv[0]] = (string)preg_replace('/^%\d+%/', '', $kv[1]);
        }
        return $out;
    }

    /** $v as mpv takes it: as it is of its names' characters, else of fixed length (%n%). */
    private static function quoted(string $v): string
    {
        return preg_match('/^[A-Za-z0-9_-]*$/', $v) === 1 ? $v : '%' . strlen($v) . "%$v";
    }

    /** $f as Swift's Float.description writes it: 1.0, 0.5, -1.5. */
    private static function number(float $f): string
    {
        $s = rtrim(rtrim(sprintf('%.6F', $f), '0'), '.');
        return str_contains($s, '.') ? $s : "$s.0";
    }
}
