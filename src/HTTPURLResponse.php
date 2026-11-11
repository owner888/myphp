<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * The HTTP metadata of a URLSession response, as Foundation's
 * HTTPURLResponse in SwiftUI: $statusCode, $allHeaderFields, $url.
 */
final class HTTPURLResponse
{
    /** @param array<string, string> $allHeaderFields */
    public function __construct(
        public readonly string $url,
        public readonly int $statusCode,
        public readonly array $allHeaderFields = [],
    ) {}

    public function value(string $forHTTPHeaderField): ?string
    {
        return $this->allHeaderFields[strtolower($forHTTPHeaderField)] ?? null;
    }
}