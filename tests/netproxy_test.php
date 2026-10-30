<?php
// The system's proxy for myphp's requests (mygo's netproxy_test.go): the
// first proxy curl can use of the system's list, loopback hosts direct,
// the environment deciding when it names a proxy, the system's answer
// kept 5 seconds a scheme and host. On macOS, CFNetwork answers.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Native\NetProxy;

(function () {
    foreach ([
        [[], null], [['direct://'], null], [['http://proxy:3128'], 'http://proxy:3128'], [['HTTP://proxy:3128/'], 'http://proxy:3128'],
        [['https://proxy:443'], 'https://proxy:443'], [['http://user:pw@proxy:3128'], 'http://user:pw@proxy:3128'],
        [['socks5://s:1080', 'socks4a://s:1080', 'socks4://s:1080'], 'socks5://s:1080'], [['socks5h://s:1080'], 'socks5h://s:1080'],
        [['socks://s:1080'], 'socks5://s:1080'], [['socks4://s:1080', 'http://p:80'], 'http://p:80'], [['socks4://s:1080', 'direct://', 'http://p:80'], null],
        [['ftp://f:21', '', 'http://'], null], [['http://[::1]:8080'], 'http://[::1]:8080'],
    ] as [$list, $want]) {
        same($want, NetProxy::first($list), 'proxy: the first curl can use of ' . json_encode($list));
    }

    $asked = []; $env = []; $now = 1000.0;
    NetProxy::$system = function (string $url) use (&$asked): array { $asked[] = $url; return ['http://p:8080']; };
    NetProxy::$env = function (string $name) use (&$env) { return $env[$name] ?? false; };
    NetProxy::$clock = function () use (&$now): float { return $now; };
    NetProxy::forget();
    foreach (['http://localhost:8080/x', 'http://a.localhost/', 'http://127.0.0.1/', 'http://[::1]:9/', 'ws://localhost/'] as $u) {
        same(null, NetProxy::decide($u), "proxy: $u direct");
    }
    same([], $asked, 'proxy: the system not asked of a loopback host');
    same('http://p:8080', NetProxy::decide('https://example.com/a?b'), 'proxy: the system\'s');
    same(['https://example.com/'], $asked, 'proxy: asked of the scheme and host alone');
    NetProxy::decide('https://example.com/other');
    same(1, count($asked), 'proxy: kept for the same scheme and host');
    NetProxy::decide('https://example.com:8443/');
    NetProxy::decide('wss://example.com/socket');
    same(['https://example.com/', 'https://example.com:8443/'], $asked, 'proxy: another port asked of, a WebSocket as its HTTPS');
    $now += 5.0;
    NetProxy::decide('https://example.com/');
    same(3, count($asked), 'proxy: asked again 5 seconds on');
    $env['no_proxy'] = 'corp.com';
    same(false, NetProxy::decide('https://example.com/'), 'proxy: the environment decides once it names any');
    NetProxy::$system = fn(string $u): array => [];
    $env = []; NetProxy::forget();
    same(null, NetProxy::decide('https://example.com/'), 'proxy: none from the system, direct');
    [NetProxy::$system, NetProxy::$env, NetProxy::$clock] = [null, null, null];
    NetProxy::forget();

    if (PHP_OS_FAMILY === 'Darwin') { // CFNetwork answers for a URL: a list of what it knows, direct:// when no proxy is set
        $list = \MyPHP\Native\SystemProxy::forURL('https://example.com/');
        check(array_filter($list, fn($s) => !preg_match('~^(direct|http|socks5)://~', $s)) === [], 'proxy: the system\'s list for a URL: ' . json_encode($list));
    }
})();
