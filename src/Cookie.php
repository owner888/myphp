<?php
declare(strict_types=1);

namespace MyPHP;

use DateTimeImmutable;

/** A cookie of the app's pages (mygo's Cookie): its name, domain and path identify it. */
final class Cookie
{
    /** Whether a cookie goes along with requests from other sites (mygo's CookieSameSite): Lax with the user's navigations from another site only, Strict with the site's own requests only, None with every request (the cookie must be Secure). Cookies::set() takes "" as Lax; a cookie list() returns has "" when the engine does not say. */
    public const SAME_SITE_LAX = 'lax', SAME_SITE_STRICT = 'strict', SAME_SITE_NONE = 'none';

    public function __construct(
        public string $name = '',
        public string $value = '',
        /** The host the cookie is sent to, as "example.com", or with a leading dot, ".example.com", the domain whose subdomains get it too: lowercase ASCII (punycode), without a port. */
        public string $domain = '',
        /** The path the cookie is sent for; "" means "/". */
        public string $path = '',
        /** When it expires, in whole seconds; null for a cookie that lasts until the app quits. */
        public ?DateTimeImmutable $expires = null,
        public bool $secure = false,
        public bool $httpOnly = false,
        public string $sameSite = '',
    ) {}
}
