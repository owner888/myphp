<?php
declare(strict_types=1);

namespace MyPHP;

use DateTimeInterface;
use MyPHP\Native\Pseudo;

/**
 * A message of ICU MessageFormat, parsed (mygo's i18n.Message): text with
 * arguments that the values given to format() replace, as ARB files and
 * Flutter's gen_l10n write them:
 *
 *     Hello, {name}!
 *     {count, plural, =0 {No files} one {# file} other {# files}}
 *     {count, plural, offset:1 =0 {Nobody} =1 {{host}} one {{host} and # other} other {{host} and # others}}
 *     {place, selectordinal, one {#st} two {#nd} few {#rd} other {#th}}
 *     {gender, select, female {She} male {He} other {They}} replied.
 *     {price, number} on {day, date, short} at {day, time, short}
 *
 * An argument {name} writes the value of name, a number formatted for the
 * locale (as {name, number} does) and a DateTimeInterface as a short date
 * and time. A plural argument picks the case of the number's plural
 * category in the language, or of an exact value, =0; # in its cases
 * writes the number less the offset. A select argument picks the case that
 * is the value, as a string, or other; every plural and select argument
 * has an other case. Cases nest.
 *
 * An apostrophe quotes, as ICU's does: '{' writes a brace, '#' a #, '{a}'
 * the text {a}, and two apostrophes write one. An apostrophe before any
 * other character is one: "don't" needs no quoting.
 */
final class Message implements \Stringable
{
    private const TEXT = 0, ARG = 1, POUND = 2, NUMBER = 3, DATE = 4, TIME = 5, PLURAL = 6, ORDINAL = 7, SELECT = 8;
    private const KINDS = [self::ARG => '', self::NUMBER => 'number', self::DATE => 'date', self::TIME => 'time', self::PLURAL => 'plural', self::ORDINAL => 'selectordinal', self::SELECT => 'select'];
    /** Unicode's Pattern_White_Space. */
    private const SPACE = "[\t\n\v\f\r \u{85}\u{200E}\u{200F}\u{2028}\u{2029}]";
    /** Unicode's Pattern_Syntax, which ends a name. */
    private const SYNTAX = "[!-\\/:-@\\[-\\^`{-~\u{A1}-\u{A7}\u{A9}\u{AB}\u{AC}\u{AE}\u{B0}\u{B1}\u{B6}\u{BB}\u{BF}\u{D7}\u{F7}\u{2010}-\u{2027}\u{2030}-\u{203E}\u{2041}-\u{2053}\u{2055}-\u{205E}\u{2190}-\u{245F}\u{2500}-\u{2775}\u{2794}-\u{2BFF}\u{2E00}-\u{2E7F}\u{3001}-\u{3003}\u{3008}-\u{3020}\u{3030}\u{FD3E}\u{FD3F}\u{FE45}\u{FE46}]";

    /** @var list<array> the parse: nodes of [kind, text or name, style, offset, cases], a case [key, ?Operands, nodes] */
    private array $nodes;
    private int $i = 0;
    // What format() is formatting with.
    private string $locale = '', $pluralLocale = '';
    private array $args = [];
    private ?Pseudo $pseudo = null;

    private function __construct(private readonly string $source) {}

    /** Parses a message of ICU MessageFormat; throws a SyntaxError for one that does not parse. */
    public static function parse(string $s): self
    {
        $m = new self($s);
        $m->nodes = $m->message(0, false);
        if ($m->i < strlen($s)) {
            throw $m->error('unmatched }');
        }
        return $m;
    }

    /** The message as it was written. */
    public function __toString(): string
    {
        return $this->source;
    }

    /** The arguments of the message, nested ones too, in the order they come; one used twice comes once, with its first kind other than ''. @return list<Arg> */
    public function args(): array
    {
        $out = [];
        $walk = function (array $nodes) use (&$walk, &$out): void {
            foreach ($nodes as $n) {
                if ($n[0] === self::TEXT || $n[0] === self::POUND) { continue; }
                $kind = self::KINDS[$n[0]];
                if (!isset($out[$n[1]])) { $out[$n[1]] = $kind; }
                elseif ($out[$n[1]] === '') { $out[$n[1]] = $kind; }
                foreach ($n[4] as $c) { $walk($c[2]); }
            }
        };
        $walk($this->nodes);
        $args = [];
        foreach ($out as $name => $kind) { $args[] = new Arg((string)$name, $kind); }
        return $args;
    }

    /** The keys of the cases of the plural, selectordinal or select arguments named $name, as "=0", "one" and "other", in the order they come, each once. @return list<string> */
    public function cases(string $name): array
    {
        $out = [];
        $walk = function (array $nodes) use (&$walk, &$out, $name): void {
            foreach ($nodes as $n) {
                foreach ($n[4] ?? [] as $c) {
                    if ($n[1] === $name && !in_array($c[0], $out, true)) { $out[] = $c[0]; }
                    $walk($c[2]);
                }
            }
        };
        $walk($this->nodes);
        return $out;
    }

    /**
     * Writes the message with $args, the values of its arguments by name,
     * for $locale: the language whose plural rules pick the cases, and
     * whose conventions numbers and dates follow. An argument $args lacks
     * is written as it is in the message, {name}. The pseudo locales
     * transform the message, which keeps the plural rules of English.
     *
     * Values: a string, an int or float, a string of a decimal number
     * (which keeps its fraction digits: "1.50"), a bool, a
     * DateTimeInterface, a Throwable (its message), a Stringable, an enum,
     * Operands, null (nothing), or anything else as JSON.
     */
    public function format(string $locale, array $args = []): string
    {
        return $this->formatIn($locale, I18n::isPseudo($locale) ? 'en' : $locale, $args);
    }

    /** @internal formats with the plural rules of $pluralLocale, the language the message is in, and numbers and dates for $locale; a pseudo locale transforms it. */
    public function formatIn(string $locale, string $pluralLocale, array $args): string
    {
        $f = clone $this;
        $f->pseudo = Pseudo::for($locale);
        [$f->locale, $f->pluralLocale, $f->args] = [$f->pseudo !== null ? $pluralLocale : $locale, $pluralLocale, $args];
        $s = $f->write($f->nodes, null);
        return $f->pseudo !== null ? $f->pseudo->wrap($s) : $s;
    }

    // Formatting.

    /** @param ?array $pound the number of the plural case being written, for #: [decimal, exact] or [raw value] */
    private function write(array $nodes, ?array $pound): string
    {
        $out = '';
        foreach ($nodes as $n) {
            switch ($n[0]) {
                case self::TEXT:
                    $out .= $this->pseudo !== null ? $this->pseudo->text($n[1]) : $n[1];
                    break;
                case self::POUND:
                    if ($pound === null) { $out .= '#'; break; }
                    $out .= $this->placeholder(count($pound) === 2 ? self::number($this->locale, $pound, '') : self::valueString($this->locale, $pound[0]));
                    break;
                default:
                    $out .= $this->argument($n, $pound);
            }
        }
        return $out;
    }

    private function placeholder(string $s): string
    {
        return $this->pseudo !== null ? $this->pseudo->placeholder($s) : $s;
    }

    private function argument(array $n, ?array $pound): string
    {
        if (!array_key_exists($n[1], $this->args)) {
            return '{' . $n[1] . '}';
        }
        $v = $this->args[$n[1]];
        switch ($n[0]) {
            case self::ARG:
                return $this->placeholder(self::valueString($this->locale, $v));
            case self::NUMBER:
                $num = self::toNumber($v);
                return $this->placeholder($num !== null ? self::number($this->locale, $num, $n[2]) : self::valueString($this->locale, $v));
            case self::DATE:
            case self::TIME:
                return $this->placeholder($v instanceof DateTimeInterface ? self::date($this->locale, $v, $n[0] === self::TIME ? 'time' : 'date', $n[2]) : self::valueString($this->locale, $v));
            case self::SELECT:
                return $this->write(self::findCase($n[4], self::selectKey($v))[2], $pound);
        }
        $num = self::toNumber($v);
        if ($num === null) {
            return $this->write(self::findCase($n[4], 'other')[2], [$v]); // not a number: the other case, whose # writes the value
        }
        // An exact case matches the number itself, a keyword the number less the offset.
        $chosen = null;
        if (!str_starts_with($num[0], '-')) {
            $exact = Operands::of($num[0]);
            foreach ($n[4] as $c) {
                if ($c[1] !== null && $c[1]->i === $exact->i && $c[1]->t === $exact->t && $c[1]->w === $exact->w) { $chosen = $c; break; }
            }
        }
        $shown = self::minus($num, $n[3]);
        if ($chosen === null) {
            // The category of the number as # writes it, as ICU picks it: 1.0004 is written "1", whose category is one.
            $o = self::shownOperands($shown);
            $cat = $n[0] === self::PLURAL ? I18n::pluralCategory($this->pluralLocale, $o) : I18n::ordinalCategory($this->pluralLocale, $o);
            $chosen = self::findCase($n[4], $cat->value);
        }
        return $this->write($chosen[2], $shown);
    }

    /** The case of $key, else other, which every argument has. */
    private static function findCase(array $cases, string $key): array
    {
        $other = null;
        foreach ($cases as $c) {
            if ($c[0] === $key) { return $c; }
            if ($c[0] === 'other') { $other = $c; }
        }
        return $other;
    }

    /** The case a value of a select argument picks. */
    private static function selectKey(mixed $v): string
    {
        return match (true) {
            is_string($v) => $v,
            is_bool($v) => $v ? 'true' : 'false',
            $v instanceof \Stringable => (string)$v,
            $v instanceof \BackedEnum => (string)$v->value,
            $v instanceof \UnitEnum => $v->name,
            default => self::toNumber($v)[0] ?? self::valueString('', $v),
        };
    }

    /** @return ?array{string, bool} $v as a decimal number, and whether it is exact (a string, or Operands): an int, a finite float, a string of a decimal number, Operands; null when it is none */
    private static function toNumber(mixed $v): ?array
    {
        if (is_int($v)) { return [(string)$v, false]; }
        if (is_float($v)) { return is_finite($v) ? [Operands::shortest($v), false] : null; }
        if (is_string($v)) {
            $s = trim($v);
            return preg_match('/^-?\d+(\.\d+)?$/', $s) ? [$s, true] : null;
        }
        if ($v instanceof Operands) {
            $big = 1_000_000_000_000_000_000;
            $s = (string)($v->i >= $big ? $v->i - $big : $v->i);
            if ($v->v > 0) {
                $f = (string)($v->f % $big);
                $s .= '.' . str_repeat('0', max($v->v - strlen($f), 0)) . $f;
            }
            return [$s, true];
        }
        return null;
    }

    /** @return array{string, bool} the number less $offset, with as many fraction digits as it has */
    private static function minus(array $n, int $offset): array
    {
        if ($offset === 0) { return $n; }
        if (!str_contains($n[0], '.') && is_int($i = filter_var($n[0], FILTER_VALIDATE_INT)) && $i >= PHP_INT_MIN + $offset) {
            return [(string)($i - $offset), $n[1]];
        }
        $frac = strlen(explode('.', $n[0] . '.')[1]);
        return [sprintf('%.' . $frac . 'F', (float)$n[0] - $offset), $n[1]];
    }

    /** The operands of a number as a message writes it by default: its own fraction digits when exact, else rounded to three without trailing zeros, as Intl.PluralRules reads it. */
    private static function shownOperands(array $n): Operands
    {
        if ($n[1]) { return Operands::of($n[0]); }
        $s = I18n::numberFormatter('en', new NumberOptions(noGrouping: true))->formatDecimal(ltrim($n[0], '-'));
        return Operands::of($s);
    }

    /** The value of a simple argument, written. */
    private static function valueString(string $locale, mixed $v): string
    {
        return match (true) {
            is_string($v) => $v,
            $v instanceof DateTimeInterface => self::date($locale, $v, 'datetime', 'short'),
            $v instanceof \Throwable => $v->getMessage(),
            $v instanceof \Stringable => (string)$v,
            $v instanceof \BackedEnum => (string)$v->value,
            $v instanceof \UnitEnum => $v->name,
            is_bool($v) => $v ? 'true' : 'false',
            $v === null => '',
            default => ($num = self::toNumber($v)) !== null ? self::number($locale, $num, '') : (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
        };
    }

    /**
     * A number written for $locale in an ICU number style: '' (up to three
     * fraction digits, or those of an exact number), "integer", "percent",
     * or a skeleton of ICU's ("::percent .00"), of which it reads percent,
     * integer, precision-integer, fraction precision (.00, .0#, .##) and
     * group-off, and writes the rest as the default. Integers and percents
     * round half to even, as ICU's MessageFormat does; the default, half
     * away from zero, as Intl.NumberFormat does.
     */
    private static function number(string $locale, array $n, string $style): string
    {
        [$percent, $integer, $halfEven, $noGrouping, $min, $max, $fraction] = [false, false, false, false, 0, 0, false];
        $stems = str_starts_with($style, '::') ? preg_split('/\s+/', trim(substr($style, 2)), -1, PREG_SPLIT_NO_EMPTY) : [$style];
        foreach ($stems as $stem) {
            if ($stem === 'integer' || $stem === 'precision-integer') { [$integer, $halfEven] = [true, true]; }
            elseif ($stem === 'percent') { [$percent, $halfEven] = [true, true]; }
            elseif ($stem === 'group-off') { $noGrouping = true; }
            elseif (strlen($stem) > 1 && $stem[0] === '.' && trim(substr($stem, 1), '0#') === '') { [$min, $max, $fraction] = [substr_count($stem, '0'), strlen($stem) - 1, true]; }
        }
        if ($n[1] && !$fraction && !$integer && !$percent) {
            $min = $max = min(strlen(explode('.', $n[0] . '.')[1]), 100);
        }
        return I18n::numberFormatter($locale, new NumberOptions($percent, $integer, $min, $max, 0, $noGrouping, $halfEven))->formatDecimal($n[0]);
    }

    /** A time written for $locale: its date, its time, or both ("datetime"), in an ICU style, "short", "medium", "long" or "full" ('' is "medium"), or a skeleton ("::yMMMd"). */
    private static function date(string $locale, DateTimeInterface $t, string $kind, string $style): string
    {
        if (str_starts_with($style, '::')) {
            return I18n::formatDate($locale, $t, new DateOptions(skeleton: trim(substr($style, 2))));
        }
        $s = match ($style) { 'short' => Style::Short, 'long' => Style::Long, 'full' => Style::Full, default => Style::Medium };
        return I18n::formatDate($locale, $t, match ($kind) { 'time' => new DateOptions(time: $s), 'datetime' => new DateOptions(Style::Short, Style::Short), default => new DateOptions($s) });
    }

    // Parsing.

    private function error(string $e): SyntaxError
    {
        return new SyntaxError($this->source, $this->i, $e);
    }

    /** Text and arguments up to a } that it leaves, or the end; in a case of a plural argument, # is the number. */
    private function message(int $depth, bool $inPlural): array
    {
        if ($depth > 32) { throw $this->error('arguments nest too deep'); }
        $s = $this->source; $len = strlen($s);
        $nodes = []; $text = '';
        $flush = function () use (&$nodes, &$text): void {
            if ($text !== '') { $nodes[] = [self::TEXT, $text, '', 0, []]; $text = ''; }
        };
        while ($this->i < $len) {
            $c = $s[$this->i];
            if ($c === "'") {
                $text .= $this->quoted($inPlural);
            } elseif ($c === '{') {
                $flush();
                $nodes[] = $this->argumentNode($depth, $inPlural);
            } elseif ($c === '}') {
                $flush();
                return $nodes;
            } elseif ($c === '#' && $inPlural) {
                $flush();
                $nodes[] = [self::POUND, '', '', 0, []];
                $this->i++;
            } else {
                $text .= $c;
                $this->i++;
            }
        }
        $flush();
        return $nodes;
    }

    /** An apostrophe at $i: two write one, one before a brace (or a # in a plural case) quotes up to the next lone apostrophe or the end, any other is itself. */
    private function quoted(bool $inPlural): string
    {
        $s = $this->source; $len = strlen($s);
        $this->i++;
        if ($this->i < $len && $s[$this->i] === "'") { $this->i++; return "'"; }
        if ($this->i >= $len || !($s[$this->i] === '{' || $s[$this->i] === '}' || $s[$this->i] === '#' && $inPlural)) { return "'"; }
        $out = '';
        while ($this->i < $len) {
            if ($s[$this->i] === "'") {
                if ($this->i + 1 < $len && $s[$this->i + 1] === "'") { $out .= "'"; $this->i += 2; continue; }
                $this->i++;
                return $out;
            }
            $out .= $s[$this->i++];
        }
        return $out;
    }

    private function space(): void
    {
        if (preg_match('/\G' . self::SPACE . '+/u', $this->source, $m, 0, $this->i)) { $this->i += strlen($m[0]); }
    }

    /** A name or a keyword: characters other than white space and syntax. */
    private function word(): string
    {
        preg_match('/\G(?:(?!' . self::SPACE . '|' . self::SYNTAX . ').)*/su', $this->source, $m, 0, $this->i);
        $this->i += strlen($m[0]);
        return $m[0];
    }

    private function at(string $c): bool
    {
        return $this->i < strlen($this->source) && $this->source[$this->i] === $c;
    }

    /** An argument from its { to its }. */
    private function argumentNode(int $depth, bool $inPlural): array
    {
        $this->i++; // {
        $this->space();
        $name = $this->word();
        if ($name === '') { throw $this->error('an argument has no name'); }
        if (!self::validName($name)) { throw $this->error(json_encode($name, JSON_UNESCAPED_UNICODE) . ' is not an argument name'); }
        $this->space();
        if ($this->i >= strlen($this->source)) { throw $this->error("unclosed argument {{$name}"); }
        if ($this->at('}')) { $this->i++; return [self::ARG, $name, '', 0, []]; }
        if (!$this->at(',')) { throw $this->error('expected , or } after the argument name ' . json_encode($name, JSON_UNESCAPED_UNICODE)); }
        $this->i++;
        $this->space();
        $type = $this->word();
        $this->space();
        switch ($type) {
            case 'number': case 'date': case 'time':
                $kind = ['number' => self::NUMBER, 'date' => self::DATE, 'time' => self::TIME][$type];
                $style = '';
                if ($this->at(',')) {
                    $this->i++;
                    $style = $this->style();
                    if ($style === '') { throw $this->error("the $type argument \"$name\" has an empty style"); }
                }
                if (!$this->at('}')) { throw $this->error("unclosed argument {{$name}, $type"); }
                $this->i++;
                return [$kind, $name, $style, 0, []];
            case 'plural': case 'selectordinal': case 'select':
                break;
            case '':
                throw $this->error("the argument \"$name\" has no type");
            default:
                throw $this->error("unknown argument type \"$type\" (want number, date, time, plural, selectordinal or select)");
        }
        if (!$this->at(',')) { throw $this->error("expected , after $type"); }
        $this->i++;
        $kind = ['plural' => self::PLURAL, 'selectordinal' => self::ORDINAL, 'select' => self::SELECT][$type];
        $plural = $kind !== self::SELECT;
        $offset = 0;
        $this->space();
        if ($plural && substr_compare($this->source, 'offset:', $this->i, 7) === 0) {
            $this->i += 7;
            $this->space();
            preg_match('/\G\d*/', $this->source, $m, 0, $this->i);
            if ($m[0] === '' || strlen($m[0]) > 15) { throw $this->error('offset: needs a number'); }
            $this->i += strlen($m[0]);
            $offset = (int)$m[0];
        }
        $cases = [];
        while (true) {
            $this->space();
            if ($this->i >= strlen($this->source)) { throw $this->error("unclosed $type argument \"$name\""); }
            if ($this->at('}')) { $this->i++; break; }
            $exact = null;
            if ($plural && $this->at('=')) {
                $this->i++;
                preg_match('/\G[\d.]*/', $this->source, $m, 0, $this->i);
                $this->i += strlen($m[0]);
                $value = $m[0];
                try { $exact = $value === '' || $value[0] === '.' || str_ends_with($value, '.') ? null : Operands::of($value); } catch (\InvalidArgumentException) {}
                if ($exact === null) { throw $this->error("=$value is not a number"); }
                $key = "=$value";
            } else {
                $key = $this->word();
                if ($key === '') { throw $this->error("expected a case of the $type argument \"$name\""); }
                if ($plural && Plural::tryFrom($key) === null) { throw $this->error("\"$key\" is not a plural category (zero, one, two, few, many, other)"); }
            }
            foreach ($cases as $prev) {
                if ($prev[0] === $key) { throw $this->error("the $type argument \"$name\" has the case $key twice"); }
            }
            $this->space();
            if (!$this->at('{')) { throw $this->error("expected { after the case $key"); }
            $this->i++;
            $nodes = $this->message($depth + 1, $plural);
            if ($this->i >= strlen($this->source)) { throw $this->error("unclosed case $key"); }
            $this->i++; // }
            $cases[] = [$key, $exact, $nodes];
        }
        if (!in_array('other', array_column($cases, 0), true)) { throw $this->error("the $type argument \"$name\" has no other case"); }
        return [$kind, $name, '', $offset, $cases];
    }

    /** The style of a number, date or time argument up to its }: a keyword, as "short", or a skeleton, as "::currency/EUR", in which quoted text and nested braces may be. */
    private function style(): string
    {
        $s = $this->source; $start = $this->i; $depth = 0;
        while ($this->i < strlen($s)) {
            switch ($s[$this->i]) {
                case "'":
                    $end = strpos($s, "'", $this->i + 1);
                    if ($end === false) { throw $this->error('unclosed quote in a style'); }
                    $this->i = $end + 1;
                    continue 2;
                case '{':
                    $depth++;
                    break;
                case '}':
                    if ($depth === 0) { return (string)preg_replace('/^' . self::SPACE . '+|' . self::SPACE . '+$/u', '', substr($s, $start, $this->i - $start)); }
                    $depth--;
            }
            $this->i++;
        }
        throw $this->error('unclosed style');
    }

    /** Whether a word names an argument: a number without leading zeros, or a name that starts with a letter or _. */
    private static function validName(string $name): bool
    {
        if (ctype_digit($name[0])) {
            return ctype_digit($name) && !(strlen($name) > 1 && $name[0] === '0');
        }
        return (bool)preg_match('/^[_\p{L}]/u', $name);
    }
}
