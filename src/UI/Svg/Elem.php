<?php
declare(strict_types=1);

namespace MyPHP\UI\Svg;

use RuntimeException;
use XMLReader;

/**
 * An element of an SVG document, as mygo's internal/svg elem, with the
 * parser and the cascade of its style sheets: $props are the
 * declarations of its properties, in the order they apply — attributes,
 * style sheets, then the style attribute — as [name, value].
 */
final class Elem
{
    private const MAX_ELEMS = 1 << 18, MAX_DEPTH = 256, MAX_RULES = 4096;

    /** @var list<Elem> */
    public array $kids = [];
    public ?Elem $parent = null;
    /** The text of a style element. */
    public string $text = '';
    /** @var list<array{0: string, 1: string}> */
    public array $props = [];

    /** @param array<string, string> $attrs */
    public function __construct(public readonly string $name, public array $attrs = []) {}

    /** Parses the document's elements: the svg element, with every element's properties cascaded. */
    public static function parse(string $data): self
    {
        $r = new XMLReader;
        if (!$r->XML($data, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT)) { throw new RuntimeException('svg: cannot read the document'); }
        $root = null; $stack = []; $count = 0;
        while (@$r->read()) {
            if ($r->nodeType === XMLReader::ELEMENT) {
                if (count($stack) >= self::MAX_DEPTH) { throw new RuntimeException('svg: elements nested too deep'); }
                if (++$count > self::MAX_ELEMS) { throw new RuntimeException('svg: too many elements'); }
                $e = new self($r->localName);
                $empty = $r->isEmptyElement;
                if ($r->hasAttributes && $r->moveToFirstAttribute()) {
                    do {
                        // Namespaced attributes belong to editors, but for xlink:href, which href takes precedence over.
                        if ($r->prefix !== '' && $r->localName !== 'href') { continue; }
                        if ($r->prefix !== '' && isset($e->attrs[$r->localName])) { continue; }
                        if ($r->prefix === 'xmlns' || $r->name === 'xmlns') { continue; }
                        $e->attrs[$r->localName] = $r->value;
                    } while ($r->moveToNextAttribute());
                    $r->moveToElement();
                }
                if ($stack !== []) { $e->parent = end($stack); $e->parent->kids[] = $e; } else { $root = $e; }
                if (!$empty) { $stack[] = $e; }
            } elseif ($r->nodeType === XMLReader::END_ELEMENT) {
                array_pop($stack);
                if ($stack === []) { break; }
            } elseif (($r->nodeType === XMLReader::TEXT || $r->nodeType === XMLReader::CDATA) && $stack !== [] && end($stack)->name === 'style') {
                end($stack)->text .= $r->value;
            }
        }
        $r->close();
        if ($root === null || $root->name !== 'svg') { throw new RuntimeException('svg: no svg element'); }
        self::cascade($root);
        return $root;
    }

    /** Calls $fn on this element and every one under it. */
    public function walk(callable $fn): void
    {
        $fn($this);
        foreach ($this->kids as $k) { $k->walk($fn); }
    }

    /** Gives every element the declarations of its properties. */
    private static function cascade(Elem $root): void
    {
        $rules = [];
        $root->walk(function (Elem $e) use (&$rules) {
            $t = $e->attrs['type'] ?? '';
            if ($e->name === 'style' && ($t === '' || $t === 'text/css')) { array_push($rules, ...self::parseCSS($e->text)); }
        });
        $rules = array_slice($rules, 0, self::MAX_RULES);
        // Rules are filed under their id, first class or type, so that an element is matched against the rules it may match only.
        $byID = []; $byClass = []; $byTag = []; $anyElem = [];
        foreach ($rules as $i => [$sel]) {
            if ($sel['id'] !== '') { $byID[$sel['id']][] = $i; }
            elseif ($sel['classes'] !== []) { $byClass[$sel['classes'][0]][] = $i; }
            elseif ($sel['tag'] !== '') { $byTag[$sel['tag']][] = $i; }
            else { $anyElem[] = $i; }
        }
        $root->walk(function (Elem $e) use ($rules, $byID, $byClass, $byTag, $anyElem) {
            foreach ($e->attrs as $name => $v) {
                if (isset(Style::PROPERTIES[$name])) { $e->props[] = [$name, trim($v)]; }
            }
            $matched = [];
            $match = function (array $candidates) use ($e, $rules, &$matched) {
                foreach ($candidates as $i) { if (self::matches($rules[$i][0], $e)) { $matched[$i] = true; } }
            };
            $match($anyElem);
            $match($byTag[$e->name] ?? []);
            if (($id = $e->attrs['id'] ?? '') !== '') { $match($byID[$id] ?? []); }
            foreach (preg_split('/\s+/', trim($e->attrs['class'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $c) { $match($byClass[$c] ?? []); }
            $matched = array_keys($matched);
            usort($matched, fn($a, $b) => $rules[$a][0]['spec'] <=> $rules[$b][0]['spec'] ?: $a <=> $b); // by specificity, then in order
            foreach ($matched as $i) { array_push($e->props, ...$rules[$i][1]); }
            if (isset($e->attrs['style'])) { array_push($e->props, ...self::parseDecls($e->attrs['style'])); }
        });
    }

    /** Whether a compound selector [tag, id, classes, spec] matches $e. */
    private static function matches(array $s, Elem $e): bool
    {
        if (($s['tag'] !== '' && $s['tag'] !== $e->name) || ($s['id'] !== '' && ($e->attrs['id'] ?? '') !== $s['id'])) { return false; }
        if ($s['classes'] !== []) {
            $have = preg_split('/\s+/', trim($e->attrs['class'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($s['classes'] as $c) { if (!in_array($c, $have, true)) { return false; } }
        }
        return true;
    }

    /** The rules of a style sheet, each [selector, decls], with single selectors. @return list<array> */
    private static function parseCSS(string $s): array
    {
        $rules = [];
        while (true) {
            $s = trim($s);
            if ($s === '') { return $rules; }
            if (str_starts_with($s, '/*')) {
                $end = strpos($s, '*/', 2);
                if ($end === false) { return $rules; }
                $s = substr($s, $end + 2); continue;
            }
            if (str_starts_with($s, '<!--')) { $s = substr($s, 4); continue; }
            if (str_starts_with($s, '-->')) { $s = substr($s, 3); continue; }
            if ($s[0] === '@') { // an at-rule ends at a semicolon or with its block
                $i = strcspn($s, ';{');
                if ($i >= strlen($s)) { return $rules; }
                $s = $s[$i] === ';' ? substr($s, $i + 1) : substr($s, self::blockEnd($s, $i));
                continue;
            }
            $open = strpos($s, '{');
            if ($open === false) { return $rules; }
            $end = self::blockEnd($s, $open);
            $body = substr($s, $open + 1, $end - $open - 1);
            if (str_ends_with($body, '}')) { $body = substr($body, 0, -1); }
            $decls = self::parseDecls(self::stripComments($body));
            foreach (explode(',', self::stripComments(substr($s, 0, $open))) as $part) {
                if (($sel = self::parseSelector(trim($part))) !== null) { $rules[] = [$sel, $decls]; }
            }
            $s = substr($s, $end);
        }
    }

    /** The index after the block opening at $s[$open]. */
    private static function blockEnd(string $s, int $open): int
    {
        $depth = 0;
        for ($i = $open, $n = strlen($s); $i < $n; $i++) {
            if ($s[$i] === '{') { $depth++; }
            elseif ($s[$i] === '}' && --$depth === 0) { return $i + 1; }
        }
        return strlen($s);
    }

    private static function stripComments(string $s): string
    {
        while (($i = strpos($s, '/*')) !== false) {
            $j = strpos($s, '*/', $i + 2);
            if ($j === false) { return substr($s, 0, $i); }
            $s = substr($s, 0, $i) . ' ' . substr($s, $j + 2);
        }
        return $s;
    }

    private static function nameEnd(string $s, int $i): int
    {
        $n = strlen($s);
        while ($i < $n && (ctype_alnum($s[$i]) || $s[$i] === '-' || $s[$i] === '_' || ord($s[$i]) >= 0x80)) { $i++; }
        return $i;
    }

    /** A compound selector: a type, an id and classes, any of which may be missing; null for one with combinators, pseudo-classes or attributes. */
    private static function parseSelector(string $s): ?array
    {
        if ($s === '') { return null; }
        $sel = ['tag' => '', 'id' => '', 'classes' => [], 'spec' => 0];
        $i = 0;
        if ($s[0] === '*') { $i = 1; }
        elseif (self::nameEnd($s, 0) > 0) { $i = self::nameEnd($s, 0); $sel['tag'] = substr($s, 0, $i); $sel['spec']++; }
        $n = strlen($s);
        while ($i < $n) {
            $c = $s[$i]; $j = self::nameEnd($s, $i + 1);
            if ($j === $i + 1 || ($c !== '.' && $c !== '#')) { return null; }
            if ($c === '.') { $sel['classes'][] = substr($s, $i + 1, $j - $i - 1); $sel['spec'] += 100; }
            else { $sel['id'] = substr($s, $i + 1, $j - $i - 1); $sel['spec'] += 10000; }
            $i = $j;
        }
        return $sel;
    }

    /** The declarations of a style attribute or block. @return list<array{0: string, 1: string}> */
    private static function parseDecls(string $s): array
    {
        $out = [];
        foreach (explode(';', $s) as $part) {
            if (!str_contains($part, ':')) { continue; }
            [$name, $value] = explode(':', $part, 2);
            $name = strtolower(trim($name)); $value = trim($value);
            if (($i = stripos($value, '!important')) !== false) { $value = trim(substr($value, 0, $i)); }
            if (isset(Style::PROPERTIES[$name]) && $value !== '') { $out[] = [$name, $value]; }
        }
        return $out;
    }
}
