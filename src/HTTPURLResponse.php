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

    /** Whether $statusCode is 2xx (200..299). */
    public function ok(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function value(string $forHTTPHeaderField): ?string
    {
        return $this->allHeaderFields[strtolower($forHTTPHeaderField)] ?? null;
    }
}