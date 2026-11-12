<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * A URL request, as Foundation's URLRequest in SwiftUI: holds the URL,
 * HTTP method, headers, body and timeout, passed to URLSession::data($for)
 * or URLSession::decode($type, for: $req).
 */
final class URLRequest
{
    public string $httpMethod = 'GET';
    /** @var array<string, string> */
    public array $allHTTPHeaderFields = [];
    public ?string $httpBody = null;
    public float $timeoutInterval = 0.0;

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|string|null $body
     */
    public function __construct(
        public string $url,
        string $method = 'GET',
        array $headers = [],
        array|string|null $body = null,
        float $timeoutInterval = 0.0,
    ) {
        $this->httpMethod = strtoupper($method);
        $this->allHTTPHeaderFields = $headers;
        $this->timeoutInterval = $timeoutInterval;
        if (is_array($body)) {
            $this->setJSONBody($body);
        } else {
            $this->httpBody = $body;
        }
    }

    public function setValue(?string $value, string $forHTTPHeaderField): static
    {
        if ($value === null) {
            unset($this->allHTTPHeaderFields[$forHTTPHeaderField]);
        } else {
            $this->allHTTPHeaderFields[$forHTTPHeaderField] = $value;
        }
        return $this;
    }

    public function value(string $forHTTPHeaderField): ?string
    {
        return array_find($this->allHTTPHeaderFields, fn(string $v, string $k) => strcasecmp($k, $forHTTPHeaderField) === 0);
    }

    public function setBearerToken(string $token): static
    {
        return $this->setValue("Bearer $token", 'Authorization');
    }

    public function setJSONBody(mixed $data): static
    {
        $this->httpBody = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($this->value('Content-Type') === null) {
            $this->allHTTPHeaderFields['Content-Type'] = 'application/json';
        }
        return $this;
    }
}