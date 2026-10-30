<?php
// Content rules (mygo's content_rules_test.go): the ids taken, the name a
// list is kept under (the id and a hash of the rules), which kept lists
// are an id's stale ones; compiling and applying them is WebKit's, with
// the running app.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\ContentRules;

(function () {
    foreach (['', 'a/b', 'spaced id', str_repeat('x', 65), "ads\n"] as $id) {
        $err = null;
        try { ContentRules::compile($id, '[]'); } catch (\InvalidArgumentException $e) { $err = $e->getMessage(); }
        same('myphp: content rules: invalid id "' . $id . '"', $err, 'content rules: id ' . json_encode($id) . ' refused');
    }
    check(ContentRules::validID('ads.v2_x-1') && ContentRules::validID(str_repeat('x', 64)), 'content rules: letters, digits, "-", "_" and ".", up to 64');
    $err = null;
    try { ContentRules::compile('ads', '[]'); } catch (\RuntimeException $e) { $err = $e->getMessage(); }
    check(str_contains((string)$err, 'running app'), 'content rules: compiled once the app runs');

    same('ads-' . substr(hash('sha256', '[1]'), 0, 16), ContentRules::identifier('ads', '[1]'), 'content rules: kept as the id and a hash of the rules');
    $current = ContentRules::identifier('ads', '[1]');
    foreach ([
        [ContentRules::identifier('ads', '[2]'), true, "the id's of other rules"],
        [$current, false, 'the current'],
        [ContentRules::identifier('ads-more', '[2]'), false, "another id's"],
        ['ads-notahash0000000', false, 'no hash'],
        ['ads', false, 'the id alone'],
    ] as [$name, $want, $what]) {
        same($want, ContentRules::stale('ads', $current, $name), "content rules: stale, $what");
    }
})();
