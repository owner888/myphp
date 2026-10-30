<?php
// Sessions (mygo's session_test.go, the part without WebKit): the
// sessions of names, the same at every call; ephemeral ones apart; the
// UUIDs that name their stores; the kinds of data they clear; the default
// session's cookies, Cookies'.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\{Cookies, PageOptions, Session, SiteData};

$work = Session::newSession('work');
same([true, 'work', false], [Session::newSession('work') === $work, $work->name(), $work->isEphemeral()], 'the persistent session of a name, the same at every call');
check(Session::newSession('home') !== $work, 'another name, another session');
$e1 = Session::newEphemeralSession(); $e2 = Session::newEphemeralSession();
same([true, '', true], [$e1 !== $e2, $e1->name(), $e1->isEphemeral()], 'ephemeral sessions, each its own');
$d = Session::defaultSession();
same([true, '', false], [Session::defaultSession() === $d, $d->name(), $d->isEphemeral()], 'the default session');
check($d !== $work && $d !== $e1, 'apart from the others');
check(updThrowsSession(fn() => Session::newSession('')), 'no session of no name');
same(null, (new PageOptions())->session, 'pages in the default session by default');
same($work, (new PageOptions(session: $work))->session, 'a page in a session');

$id = Session::id('work');
check(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-8[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id) === 1, "a version 8 UUID: $id");
same([$id, false], [Session::id('work'), Session::id('home') === $id], 'the same for a name at every run, another for another');

same([1, 2, 4, 8, 16], [SiteData::COOKIES, SiteData::LOCAL_STORAGE, SiteData::INDEXED_DB, SiteData::CACHES, SiteData::SERVICE_WORKERS], 'the kinds of site data, as bits');
same(SiteData::ALL, SiteData::ALL | SiteData::COOKIES | SiteData::SERVICE_WORKERS, 'all of them in ALL');
$e1->clearData(0); // nothing to clear: no store asked for, no app needed
check(true, 'clearing no kind does nothing');

// The default session's cookies are Cookies': both check a filter's URL before any store.
foreach ([fn() => Cookies::list(new \MyPHP\CookieFilter(url: 'ftp://x/')), fn() => $work->cookies->list(new \MyPHP\CookieFilter(url: 'ftp://x/'))] as $i => $f) {
    check(updThrowsSession($f, \InvalidArgumentException::class), "list($i) refuses a URL that is not http(s)");
}

function updThrowsSession(\Closure $f, string $class = \Throwable::class): bool
{
    try { $f(); } catch (\Throwable $e) { return $e instanceof $class; }
    return false;
}
