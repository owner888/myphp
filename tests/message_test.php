<?php
// Messages of ICU MessageFormat (mygo's message_test.go): the fixtures
// mygo's Go and TypeScript formatters both check (tests/i18n/messages.json),
// arguments and cases, values, nesting.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\{Arg, Message, Operands, Style, SyntaxError};

$f = json_decode(file_get_contents(__DIR__ . '/i18n/messages.json'), true, flags: JSON_THROW_ON_ERROR);
check(count($f['format']) >= 50 && count($f['errors']) >= 20 && count($f['pseudo']) >= 4, 'the fixtures have their cases');
$wrong = [];
foreach ([...$f['format'], ...$f['pseudo']] as $c) {
    try {
        $m = Message::parse($c['message']);
    } catch (SyntaxError $e) {
        $wrong[] = $e->getMessage();
        continue;
    }
    $got = $m->format($c['locale'] ?? 'en', $c['args'] ?? []);
    if ($got !== $c['want']) { $wrong[] = json_encode($c['message'], JSON_UNESCAPED_UNICODE) . ' in ' . ($c['locale'] ?? 'en') . ' = ' . json_encode($got, JSON_UNESCAPED_UNICODE) . ', want ' . json_encode($c['want'], JSON_UNESCAPED_UNICODE); }
    if ((string)$m !== $c['message']) { $wrong[] = 'the source of ' . $c['message']; }
}
same([], $wrong, 'the fixtures format as mygo formats them');
$parsed = [];
foreach ($f['errors'] as $s) {
    try { Message::parse($s); $parsed[] = $s; } catch (SyntaxError) {}
}
same([], $parsed, 'the bad messages are syntax errors');
try { Message::parse('{n, plural, one {x}}'); } catch (SyntaxError $e) { same([20, 'the plural argument "n" has no other case'], [$e->offset, $e->error], 'where and what'); }

$m = Message::parse('{host} and {n, plural, offset:1 one {# {g, select, a {{host}} other {{when, date, short}}}} other {{n, number}}} {x, selectordinal, other {#}} {t, time} {p, number, percent}');
same([['host', ''], ['n', 'plural'], ['g', 'select'], ['when', 'date'], ['x', 'selectordinal'], ['t', 'time'], ['p', 'number']], array_map(fn(Arg $a) => [$a->name, $a->kind], $m->args()), 'the arguments, each once');
same([['one', 'other'], ['a', 'other']], [$m->cases('n'), $m->cases('g')], 'the cases');
same(['=0', 'one', 'other', 'few'], Message::parse('{n, plural, =0 {none} one {#} other {{n, plural, few {#} other {#}}}}')->cases('n'), 'nested cases of the same name');

$day = new \DateTimeImmutable('2026-01-02 15:04:05', new \DateTimeZone('UTC'));
$got = $want = [];
foreach ([['{d, date}', ['d' => $day], 'Jan 2, 2026'], ['{d, date, short}', ['d' => $day], '1/2/26'], ['{d, date, full}', ['d' => $day], 'Friday, January 2, 2026'], ['{d, time, short}', ['d' => $day], '3:04 PM'],
    ['{d, time}', ['d' => $day], '3:04:05 PM'], ['{d}', ['d' => $day], '1/2/26, 3:04 PM'], ['{d, date, ::yMMMM}', ['d' => $day], 'January 2026'], ['{d, date, ::MMMd}', ['d' => $day], 'Jan 2'],
    ['{d, date}', ['d' => 'tomorrow'], 'tomorrow'], ['{e}', ['e' => new \RuntimeException('boom')], 'boom'], ['{v}', ['v' => Style::Short], 'Short'], ['{v}', ['v' => null], ''], ['{v}', ['v' => [1]], '[1]'],
    ['{n, plural, one {#} other {# x}}', ['n' => -2], '-2 x'], ['{n, plural, one {#} other {# x}}', ['n' => 1.5], '1.5 x'], ['{n, plural, one {#} other {# x}}', ['n' => new Operands(1, 2)], '1.00 x'],
    ['{n, plural, one {#} other {# x}}', ['n' => new Operands(1)], '1'], ['{s, select, 2 {A} other {O}}', ['s' => 2.0], 'A']] as [$msg, $args, $w]) {
    $got[] = Message::parse($msg)->format('en', $args); $want[] = $w;
}
same($want, $got, 'values of every kind');
$deep = '';
for ($i = 0; $i < 40; $i++) { $deep = '{a, select, other {' . $deep . '}}'; }
try { Message::parse($deep); check(false, 'nested 40 deep'); } catch (SyntaxError) { check(true, 'a message nested 40 deep does not parse'); }
