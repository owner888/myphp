<?php
// Content rules matched in PHP (mygo's contentrules_test.go), as WebView2
// applies them: what a list does to a load, what rules it refuses and
// takes, the domain and literal it indexes a rule by, its hiding script.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Native\ContentRuleList as L;

(function () {
    $page = 'https://news.example/article';
    $domain = '[{"trigger":{"url-filter":"^[^:]+://+([^:/]+\\\\.)?ads\\\\.test[:/]"},"action":{"type":"block"}}]';
    $none = ['block' => false, 'blockCookies' => false, 'makeHTTPS' => false];
    $block = array_replace($none, ['block' => true]);
    foreach ([
        ['domain blocks', $domain, 'https://cdn.ads.test/x.js', $page, L::SCRIPT, $block],
        ['domain spares others', $domain, 'https://notads.test/x.js', $page, L::SCRIPT, $none],
        ['domain spares a longer host', $domain, 'https://ads.test.example/x.js', $page, L::SCRIPT, $none],
        ['path', '[{"trigger":{"url-filter":"/banner/.*\\\\.gif"},"action":{"type":"block"}}]', 'https://a.example/img/BANNER/top.gif', $page, L::IMAGE, $block],
        ['case-sensitive', '[{"trigger":{"url-filter":"/Banner/","url-filter-is-case-sensitive":true},"action":{"type":"block"}}]', 'https://a.example/banner/top.gif', $page, L::IMAGE, $none],
        ['resource-type matches', '[{"trigger":{"url-filter":"track","resource-type":["script","raw"]},"action":{"type":"block"}}]', 'https://a.example/track.js', $page, L::SCRIPT, $block],
        ['resource-type spares', '[{"trigger":{"url-filter":"track","resource-type":["script"]},"action":{"type":"block"}}]', 'https://a.example/track.png', $page, L::IMAGE, $none],
        ['third-party', '[{"trigger":{"url-filter":"pixel","load-type":["third-party"]},"action":{"type":"block"}}]', 'https://t.other.example/pixel', $page, L::IMAGE, $block],
        ['third-party spares the site', '[{"trigger":{"url-filter":"pixel","load-type":["third-party"]},"action":{"type":"block"}}]', 'https://static.news.example/pixel', $page, L::IMAGE, $none],
        ['first-party', '[{"trigger":{"url-filter":"pixel","load-type":["first-party"]},"action":{"type":"block"}}]', 'https://static.news.example/pixel', $page, L::IMAGE, $block],
        ['public suffix sites differ', '[{"trigger":{"url-filter":"pixel","load-type":["third-party"]},"action":{"type":"block"}}]', 'https://b.github.io/pixel', 'https://a.github.io/', L::IMAGE, $block],
        ['if-domain', '[{"trigger":{"url-filter":"ad","if-domain":["*news.example"]},"action":{"type":"block"}}]', 'https://x.example/ad.js', 'https://www.news.example/', L::SCRIPT, $block],
        ['if-domain exact', '[{"trigger":{"url-filter":"ad","if-domain":["news.example"]},"action":{"type":"block"}}]', 'https://x.example/ad.js', 'https://www.news.example/', L::SCRIPT, $none],
        ['unless-domain', '[{"trigger":{"url-filter":"ad","unless-domain":["*news.example"]},"action":{"type":"block"}}]', 'https://x.example/ad.js', $page, L::SCRIPT, $none],
        ['if-top-url', '[{"trigger":{"url-filter":"ad","if-top-url":["^https://news\\\\.example/art"]},"action":{"type":"block"}}]', 'https://x.example/ad.js', $page, L::SCRIPT, $block],
        ['unless-top-url', '[{"trigger":{"url-filter":"ad","unless-top-url":["/article"]},"action":{"type":"block"}}]', 'https://x.example/ad.js', $page, L::SCRIPT, $none],
        ['ignore-previous-rules', '[{"trigger":{"url-filter":"^[^:]+://+([^:/]+\\\\.)?ads\\\\.test[:/]"},"action":{"type":"block"}},{"trigger":{"url-filter":".*","if-domain":["*news.example"]},"action":{"type":"ignore-previous-rules"}}]',
            'https://ads.test/x.js', $page, L::SCRIPT, $none],
        ['block after ignore', '[{"trigger":{"url-filter":".*","if-domain":["*news.example"]},"action":{"type":"ignore-previous-rules"}},{"trigger":{"url-filter":"^[^:]+://+([^:/]+\\\\.)?ads\\\\.test[:/]"},"action":{"type":"block"}}]',
            'https://ads.test/x.js', $page, L::SCRIPT, $block],
        ['block-cookies', '[{"trigger":{"url-filter":"tracker"},"action":{"type":"block-cookies"}}]', 'https://tracker.example/', $page, L::RAW, array_replace($none, ['blockCookies' => true])],
        ['make-https', '[{"trigger":{"url-filter":"^http://plain\\\\.example/"},"action":{"type":"make-https"}}]', 'http://plain.example/a', $page, L::IMAGE, array_replace($none, ['makeHTTPS' => true])],
        ['hiding does not block', '[{"trigger":{"url-filter":".*"},"action":{"type":"css-display-none","selector":".ad"}}]', 'https://x.example/', $page, L::DOCUMENT, $none],
        ['page is its own top', '[{"trigger":{"url-filter":"ad","load-type":["first-party"]},"action":{"type":"block"}}]', 'https://ad.example/', '', L::DOCUMENT, $block],
    ] as [$what, $rules, $url, $top, $type, $want]) {
        same($want, L::match([L::parse($rules)], $url, $top, $type), "content rule list: $what");
    }

    $blocks = L::parse('[{"trigger":{"url-filter":"ads"},"action":{"type":"block"}}]');
    $ignores = L::parse('[{"trigger":{"url-filter":".*"},"action":{"type":"ignore-previous-rules"}}]');
    check(L::match([$blocks, $ignores], 'https://ads.example/', $page, L::RAW)['block'], "content rule list: a later list's ignore-previous-rules leaves an earlier list's block");

    foreach ([
        '{}',
        '[{"trigger":{},"action":{"type":"block"}}]',
        '[{"trigger":{"url-filter":"("},"action":{"type":"block"}}]',
        '[{"trigger":{"url-filter":".*"},"action":{"type":"redirect"}}]',
        '[{"trigger":{"url-filter":".*"},"action":{"type":"css-display-none"}}]',
        '[{"trigger":{"url-filter":".*","resource-type":["video"]},"action":{"type":"block"}}]',
        '[{"trigger":{"url-filter":".*","load-type":["second-party"]},"action":{"type":"block"}}]',
        '[{"trigger":{"url-filter":".*","if-domain":["a"],"unless-domain":["b"]},"action":{"type":"block"}}]',
        '[{"trigger":{"url-filter":".*","if-domain":["a"],"if-top-url":["b"]},"action":{"type":"block"}}]',
        '[{"trigger":{"url-filter":".*","if-domain":["Example.com"]},"action":{"type":"block"}}]',
        '[{"trigger":{"url-filter":"a|b"},"action":{"type":"block"}}]',
        '[{"trigger":{"url-filter":"a{2}"},"action":{"type":"block"}}]',
        '[{"trigger":{"url-filter":"a\\\\d"},"action":{"type":"block"}}]',
        '[{"trigger":{"url-filter":"a^b"},"action":{"type":"block"}}]',
        '[{"trigger":{"url-filter":"é"},"action":{"type":"block"}}]',
        '[]',
    ] as $rules) {
        $err = null;
        try { L::parse($rules); } catch (\RuntimeException $e) { $err = $e->getMessage(); }
        check($err !== null, "content rule list: refuses $rules");
    }
    // What WebKit takes, as tried on macOS.
    foreach (['[^a-z]x', '(ab)?c', '(ab)+c', '.*?x', 'x$', '^x', '\\\\/x', '[a\\\\-z]', 'a\\\\.b\\\\?c', '[.]x', 'a\\\\|b', '[|{^]', '^[^:]+://+([^:/]+\\\\.)?ex\\\\.com[/:]'] as $filter) {
        $err = null;
        try { L::parse('[{"trigger":{"url-filter":"' . $filter . '","unknown":1},"action":{"type":"block"}}]'); } catch (\RuntimeException $e) { $err = $e->getMessage(); }
        same(null, $err, "content rule list: takes $filter");
    }

    foreach ([
        '^[^:]+://+([^:/]+\.)?ads\.example\.com[:/]' => 'ads.example.com',
        '^[^:]+://+([^:/]+\.)?Ads\.Example\.com[/:]' => 'ads.example.com',
        '^[^:]+://+([^:/]+\.)?ads\.example\.com/banner' => 'ads.example.com',
        '^[^:]+://+([^:/]+\.)?ads\.example\.com' => '',
        '^[^:]+://+([^:/]+\.)?ads\.example\.co.*[:/]' => '',
        '^[^:]+://+([^:/]+\.)?ads\.example\.com/*x' => '',
        '^https?://ads\.example\.com/' => '',
    ] as $filter => $want) {
        same($want, L::filterDomain($filter), "content rule list: the domain of $filter");
    }
    same(['news.example', 'b.github.io', 'example.co.uk', '192.168.0.1', 'localhost'],
        array_map(L::site(...), ['www.news.example', 'b.github.io', 'a.example.co.uk', '192.168.0.1', 'localhost']), 'content rule list: sites');

    $l = L::parse('[
        {"trigger":{"url-filter":".*"},"action":{"type":"css-display-none","selector":".ad"}},
        {"trigger":{"url-filter":".*"},"action":{"type":"css-display-none","selector":"#banner"}},
        {"trigger":{"url-filter":".*","if-domain":["*news.example"]},"action":{"type":"css-display-none","selector":".promo"}},
        {"trigger":{"url-filter":".*","if-domain":["*shop.example"]},"action":{"type":"ignore-previous-rules"}},
        {"trigger":{"url-filter":"ads"},"action":{"type":"block"}}]');
    $e = $l->cosmeticEntries();
    same([3, ['.ad', '#banner'], false, ['*news.example'], true], [count($e), $e[0]['s'], isset($e[0]['u']), $e[1]['d'], $e[2]['i'] ?? false], 'content rule list: cosmetic entries');
    $s = L::hidingScript([$l]);
    check(str_contains($s, '".promo"') && str_starts_with($s, '(()=>{'), 'content rule list: the hiding script');
    same('', L::hidingScript([$blocks]), 'content rule list: no script for a list hiding nothing');
})();
