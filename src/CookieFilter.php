<?php
declare(strict_types=1);

namespace MyPHP;

/** Which cookies Cookies::list() returns (mygo's CookieFilter): all by default, each field set narrowing the choice. */
final class CookieFilter
{
    public function __construct(
        /** The cookies a request to this http or https URL sends: those of its host's domain and the path, Secure ones only to https. */
        public string $url = '',
        /** The cookies of this name. */
        public string $name = '',
        /** The cookies of this domain and of its subdomains, with or without a leading dot: "example.com" keeps those of "example.com", ".example.com" and "www.example.com". */
        public string $domain = '',
        /** The cookies of exactly this path. */
        public string $path = '',
    ) {}
}
