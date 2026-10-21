<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Fetch;

/** A request a page makes, as Options::$allow sees it before it goes (mygo's *http.Request). */
final class Request
{
    /** @param list<array{0: string, 1: string}> $headers the headers as the page set them, names and values */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
    ) {}

    /** The URL without its password, as errors show it (Go's URL.Redacted). */
    public function redacted(): string
    {
        return (string)preg_replace('~^([a-z][a-z0-9+.-]*://[^/:@]*):[^/@]*@~i', '$1:xxxxx@', $this->url);
    }
}
