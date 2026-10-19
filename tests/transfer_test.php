<?php
// The data of native drags (mygo's transfer/data_test.go): representations
// and snapshots, materializing, providers' failures kept, files and URLs,
// several items, the negotiation of copy and move.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Transfer\{Data, Format, FormatUnavailable, Item, Operation, Representation};

// Representations and snapshots: formats without calling providers; a provider once per snapshot.
$calls = 0;
$d = new Data(new Item(Representation::bytes(Format::TEXT, 'plain'), Representation::lazy(Format::HTML, function () use (&$calls) { $calls++; return '<b>plain</b>'; })));
check($d->formats() === [Format::TEXT, Format::HTML] && $calls === 0, 'formats without calling the provider');
same('plain', $d->read(Format::TEXT), 'text read');
$snap = $d->snapshot();
for ($i = 0; $i < 4; $i++) { same('<b>plain</b>', $snap->read(Format::HTML), 'a snapshot read'); }
same(1, $calls, 'the provider is called once for a snapshot');
$d->snapshot()->read(Format::HTML);
same(2, $calls, 'a new snapshot calls it again');

// Materializing: only the formats asked for; a failure is kept and fails the transfer; none matching is FormatUnavailable.
$n = 0;
$boom = new \RuntimeException('encode failed');
$d = new Data(new Item(Representation::bytes(Format::TEXT, 'ok'), Representation::lazy(Format::HTML, function () use (&$n, $boom) { $n++; throw $boom; })));
$m = $d->materialize([Format::TEXT]);
check($n === 0 && $m->formats() === [Format::TEXT], 'materialize reads the format asked for alone');
for ($i = 0; $i < 2; $i++) {
    try { $d->materialize([Format::HTML]); check(false, 'a failed format fails'); } catch (\RuntimeException $e) { check($e === $boom, 'a failed format fails with what the provider threw'); }
}
same(1, $n, "a provider's failure is kept");
try { $d->materialize([Format::PNG]); check(false, 'no match'); } catch (FormatUnavailable) { check(true, 'no match is FormatUnavailable'); }
same('', (new Data(new Item(Representation::bytes(Format::TEXT, ''))))->read(Format::TEXT), 'empty data is valid');

// Files and URLs.
$paths = [sys_get_temp_dir() . '/a #%.txt', sys_get_temp_dir() . '/日本語.txt'];
$d = Data::files(...$paths);
same($paths, $d->paths(), 'file paths round-trip');
same(2, count($d->items), 'one item per file');
check(str_contains($d->urls()[0], '%23'), 'a file path is URL escaped');
same([], Data::url('https://example.com/a#b')->paths(), 'a web URL is no file');
same([], (new Data(new Item(Representation::bytes(Format::URI_LIST, "# comment\r\nfile://remote/tmp/a\r\nhttps://example.com\r\n"))))->paths(), 'a remote file URL is no path');
try { Data::files('relative'); check(false, 'a relative path'); } catch (\InvalidArgumentException) { check(true, 'a relative path is refused'); }
try { Data::url('no-scheme'); check(false, 'a relative URL'); } catch (\InvalidArgumentException) { check(true, 'a relative URL is refused'); }

// Several items: text joined, other formats the first's; materialized, they stay apart.
$custom = 'application/vnd.mygo.test';
$d = new Data(new Item(Representation::bytes(Format::TEXT, 'a'), Representation::bytes($custom, "\0\1")), new Item(Representation::bytes(Format::TEXT, 'b'), Representation::bytes($custom, "\2\3")));
same("a\nb", $d->read(Format::TEXT), 'texts joined by newlines');
same("\0\1", $d->read($custom), "another format: the first item's");
same(2, count($d->materialize([$custom])->items), 'materialized items stay apart');

// Negotiating copy and move.
foreach ([[3, 0, 2, 1], [3, 3, 2, 2], [2, 1, 2, 0], [1, 2, 1, 0], [3, 3, 0, 1], [2, 2, 1, 2], [0, 3, 1, 0]] as [$s, $dst, $sug, $want]) {
    same($want, Operation::negotiate($s, $dst, $sug), "source $s, destination $dst, suggested $sug");
}

// A throwing provider is kept, and negotiation reads none.
$calls = 0;
$d = new Data(new Item(Representation::lazy(Format::HTML, function () use (&$calls) { $calls++; throw new \Error('codec failed'); }), Representation::bytes(Format::TEXT, 'fallback')));
check($d->preferred(Format::PNG, Format::TEXT, Format::HTML) === Format::TEXT && $calls === 0, 'negotiation reads no provider');
same(null, $d->preferred(Format::PNG), 'an unavailable preference');
for ($i = 0; $i < 2; $i++) {
    try { $d->read(Format::HTML); check(false, 'throws'); } catch (\Error $e) { same('codec failed', $e->getMessage(), 'what the provider threw, again'); }
}
same(1, $calls, 'a throwing provider is not called again');
same('fallback', $d->read(Format::TEXT), 'the alternative stays');
try { new Item(Representation::bytes(Format::TEXT, 'a'), Representation::bytes(Format::TEXT, 'b')); check(false, 'duplicate'); } catch (\InvalidArgumentException) { check(true, 'a duplicate format is refused'); }
