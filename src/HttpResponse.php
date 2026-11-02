<?php
declare(strict_types=1);

namespace MyPHP;

/** The response to an Http request: the last one's, after the redirects followed. */
final class HttpResponse
{
    public function __construct(
        /** Its status code, as 200. */
        public readonly int $status,
        /** Its body, uncompressed. */
        public readonly string $body,
        /** @var array<string, string> its headers, by their names in lower case (a repeated one: its last value) */
        public readonly array $headers,
    ) {}

    /** Whether the status is 2xx. */
    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** The body decoded from JSON, arrays for objects; throws a JsonException when it is not JSON. */
    public function json(): mixed
    {
        return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
    }
}
