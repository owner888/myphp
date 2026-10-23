<?php
// Cookies and downloads (mygo's cookies_test.go and downloads_test.go):
// what Cookies::set() takes and how it fills a cookie in, which cookies a
// request to a URL sends, a download's file name and its numbered path.
declare(strict_types=1);

namespace MyPHP\Tests;

use DateTimeImmutable;
use MyPHP\{Cookie, Cookies, Window};

$bad = function (Cookie $c): string {
    try {
        Cookies::normalize($c);
    } catch (\InvalidArgumentException $e) {
        return $e->getMessage();
    }
    return '';
};
// Filled in: the domain lowercase, the path "/", SameSite Lax, the expiry in whole seconds of UTC.
$c = Cookies::normalize(new Cookie('session', 'abc', 'Example.COM', expires: new DateTimeImmutable('2030-01-02 03:04:05.6 +02:00')));
same(['example.com', '/', Cookie::SAME_SITE_LAX, '2030-01-02T01:04:05+00:00'], [$c->domain, $c->path, $c->sameSite, $c->expires->format('c')], 'a cookie filled in');
same('.example.com', Cookies::normalize(new Cookie('a', 'b', '.example.com'))->domain, 'a domain with its subdomains');
same('192.168.0.1', Cookies::normalize(new Cookie('a', 'b', '192.168.0.1'))->domain, 'an IP address');
same('::1', Cookies::normalize(new Cookie('a', 'b', '::1'))->domain, 'an IPv6 address');
// Refused.
foreach ([
    'name' => [new Cookie('', 'v', 'example.com'), new Cookie('a=b', 'v', 'example.com'), new Cookie(' a', 'v', 'example.com'), new Cookie("a\n", 'v', 'example.com'), new Cookie('a;', 'v', 'example.com')],
    'value' => [new Cookie('a', 'v;w', 'example.com'), new Cookie('a', ' v', 'example.com'), new Cookie('a', "v\x7f", 'example.com')],
    'domain' => [new Cookie('a', 'v', ''), new Cookie('a', 'v', 'example.com.'), new Cookie('a', 'v', 'exämple.com'), new Cookie('a', 'v', '-x.com'), new Cookie('a', 'v', 'a b.com'), new Cookie('a', 'v', '.'), new Cookie('a', 'v', str_repeat('a', 64) . '.com')],
    'IP domain' => [new Cookie('a', 'v', '.192.168.0.1')],
    'path' => [new Cookie('a', 'v', 'example.com', 'x'), new Cookie('a', 'v', 'example.com', '/a;b')],
    'SameSite=None without Secure' => [new Cookie('a', 'v', 'example.com', sameSite: Cookie::SAME_SITE_NONE)],
    'SameSite' => [new Cookie('a', 'v', 'example.com', sameSite: 'Lax')],
    'expiration year' => [new Cookie('a', 'v', 'example.com', expires: new DateTimeImmutable('1500-01-01'))],
    'cookie prefix without Secure' => [new Cookie('__Secure-a', 'v', 'example.com'), new Cookie('__Host-a', 'v', 'example.com')],
    '__Host- domain or path' => [new Cookie('__Host-a', 'v', '.example.com', secure: true), new Cookie('__Host-a', 'v', 'example.com', '/x', secure: true)],
] as $what => $cookies) {
    foreach ($cookies as $i => $c) {
        same("myphp: cookies: invalid $what", $bad($c), "refused: $what #$i");
    }
}
same('', $bad(new Cookie('a', "v\tw", 'example.com', sameSite: Cookie::SAME_SITE_NONE, secure: true)), 'a tab inside a value, and None with Secure');

// Which cookies a request sends.
$m = fn(Cookie $c, string $url): bool => Cookies::matchesURL($c, parse_url($url));
check($m(new Cookie('a', 'v', 'example.com'), 'http://example.com/x'), 'its host');
check(!$m(new Cookie('a', 'v', 'example.com'), 'http://www.example.com/'), 'not its subdomains without the dot');
check($m(new Cookie('a', 'v', '.example.com'), 'http://www.example.com/') && $m(new Cookie('a', 'v', '.example.com'), 'http://example.com/'), 'the domain and its subdomains with it');
check(!$m(new Cookie('a', 'v', '.0.1'), 'http://192.168.0.1/'), 'no IP address as a subdomain');
check($m(new Cookie('a', 'v', 'example.com', '/docs'), 'http://example.com/docs') && $m(new Cookie('a', 'v', 'example.com', '/docs'), 'http://example.com/docs/a')
    && !$m(new Cookie('a', 'v', 'example.com', '/docs'), 'http://example.com/docsx') && $m(new Cookie('a', 'v', 'example.com', '/docs/'), 'http://example.com/docs/x'), 'its path and below');
check($m(new Cookie('a', 'v', 'example.com'), 'http://example.com'), 'no path is /');
check(!$m(new Cookie('a', 'v', 'example.com', secure: true), 'http://example.com/') && $m(new Cookie('a', 'v', 'example.com', secure: true), 'HTTPS://example.com/'), 'a secure one to https only');
$threw = false;
try { Cookies::list(new \MyPHP\CookieFilter(url: 'ftp://example.com/')); } catch (\InvalidArgumentException) { $threw = true; }
check($threw, 'list needs an http or https URL');

// Downloads: the name suggested, without directories; numbered when taken.
same('report.pdf', Window::downloadName('../../etc/report.pdf'), 'no directories');
same('a.txt', Window::downloadName('C:\\Users\\a.txt'), 'nor Windows ones');
same('download', Window::downloadName(''), 'a name for none');
same('download', Window::downloadName('..'), 'nor a parent');
$dir = sys_get_temp_dir() . '/myphp-dl-' . getmypid();
@mkdir($dir);
same("$dir/a.pdf", Window::uniquePath("$dir/a.pdf"), 'a free name stays');
touch("$dir/a.pdf");
touch("$dir/a (2).pdf");
same("$dir/a (3).pdf", Window::uniquePath("$dir/a.pdf"), 'a taken one is numbered');
touch("$dir/b");
same("$dir/b (2)", Window::uniquePath("$dir/b"), 'without an extension');
exec('rm -rf ' . escapeshellarg($dir));
