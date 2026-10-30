<?php
declare(strict_types=1);

namespace MyPHP;

/** A request a page made to a scheme Protocol serves (mygo's *http.Request of a Protocol handler). */
final class ProtocolRequest
{
    /** @var array<string, string> the headers by their lowercase names */
    private array $lower = [];

    /** @param array<string, string> $headers */
    public function __construct(
        /** GET, POST… */
        public readonly string $method,
        /** The whole URL, as thumbs://localhost/a.png?size=2. */
        public readonly string $url,
        /** The headers the page sent, by name. */
        public readonly array $headers = [],
        /** The body, of a POST say. */
        public readonly string $body = '',
        /** The window whose page asked, null for one that is gone (mygo's RemoteAddr "mygo:<id>"). */
        public readonly ?Window $window = null,
        /** The page that asked, a window's or a web view's (mygo's CallerPage of the request's context): how a page, untrusted ones too (PageOptions::$untrusted), speaks to the app. */
        public readonly ?Page $page = null,
    ) {
        foreach ($headers as $k => $v) {
            $this->lower[strtolower((string)$k)] = $v;
        }
    }

    /** The header $name, whatever its case, or ''. */
    public function header(string $name): string
    {
        return $this->lower[strtolower($name)] ?? '';
    }

    /** The URL's path, decoded: "/a b.png"; "/" when it has none. */
    public function path(): string
    {
        $p = rawurldecode((string)parse_url($this->url, PHP_URL_PATH));
        return $p === '' ? '/' : $p;
    }

    /** The URL's query, parsed. @return array<string, mixed> */
    public function query(): array
    {
        parse_str((string)parse_url($this->url, PHP_URL_QUERY), $q);
        return $q;
    }
}
