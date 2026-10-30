<?php
declare(strict_types=1);

namespace MyPHP;

/** A server, or a proxy, asking for a user name and a password before it serves the page or something it loads, given to Page::setAuthHandler's handler (mygo's AuthRequest). */
final class AuthRequest
{
    public function __construct(
        /** The host and port of the server asking, or of the proxy, as "intranet.example.com" and 443. */
        public readonly string $host,
        public readonly int $port,
        /** The protection space the server named, '' for none: one server can ask for the credentials of several. */
        public readonly string $realm,
        /** The scheme, lowercase: "basic", "digest", "ntlm" or "negotiate". */
        public readonly string $scheme,
        /** A proxy asks rather than the server. */
        public readonly bool $isProxy = false,
    ) {}
}
