<?php
declare(strict_types=1);

namespace MyPHP;

use DateTimeImmutable;
use InvalidArgumentException;
use MyPHP\Native\CookieStore;

/**
 * The cookies of a session's pages, HttpOnly ones included (mygo's
 * CookiesModule): Session::$cookies; Cookies, statically, those of the
 * default session. They wait for the store, the app handling events
 * meanwhile, and need the running app.
 */
final class CookiesModule
{
    /** @internal Session makes it */
    public function __construct(private readonly Session $session) {}

    /**
     * The cookies $f chooses, sorted by domain, path and name (mygo's List).
     * @return list<Cookie>
     */
    public function list(CookieFilter $f = new CookieFilter): array
    {
        $url = null;
        if ($f->url !== '') {
            $u = parse_url($f->url);
            if ($u === false || !in_array(strtolower($u['scheme'] ?? ''), ['http', 'https'], true) || ($u['host'] ?? '') === '') {
                throw new InvalidArgumentException("myphp: cookies: list needs an http or https URL, not \"{$f->url}\"");
            }
            $url = $u;
        }
        $domain = strtolower(str_starts_with($f->domain, '.') ? substr($f->domain, 1) : $f->domain);
        $out = [];
        foreach (CookieStore::all($this->session->dataStore()) as $c) {
            $c->domain = strtolower($c->domain);
            $host = str_starts_with($c->domain, '.') ? substr($c->domain, 1) : $c->domain;
            if ($f->name !== '' && $c->name !== $f->name || $f->path !== '' && $c->path !== $f->path
                || $domain !== '' && $host !== $domain && !str_ends_with($host, ".$domain")
                || $url !== null && !Cookies::matchesURL($c, $url)) {
                continue;
            }
            $out[] = $c;
        }
        usort($out, fn(Cookie $a, Cookie $b): int => [$a->domain, $a->path, $a->name] <=> [$b->domain, $b->path, $b->name]);
        return $out;
    }

    /** Adds the cookie, or replaces the one of its name, domain and path (mygo's Set), as Cookies::set says. */
    public function set(Cookie $c): void
    {
        $c = Cookies::normalize($c);
        if ($c->expires !== null && $c->expires <= new DateTimeImmutable) {
            $this->delete($c);
            return;
        }
        CookieStore::set($c, $this->session->dataStore());
    }

    /** Deletes the cookie of $c's name, domain and path (mygo's Delete), as Cookies::delete says. */
    public function delete(Cookie $c): void
    {
        [$name, $domain, $path] = Cookies::key($c->name, $c->domain, $c->path);
        CookieStore::delete($name, $domain, $path, $this->session->dataStore());
    }

    /** Deletes every cookie of the session, and nothing else its pages stored (mygo's Clear). */
    public function clear(): void
    {
        CookieStore::clear($this->session->dataStore());
    }
}
