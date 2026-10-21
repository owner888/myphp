<?php
declare(strict_types=1);

namespace MyPHP\Plugins\WebSocket;

/** The opening handshake a page asks for, as Options::$allow sees it before it goes (mygo's *http.Request). */
final class Request
{
    /** @param list<array{0: string, 1: string}> $headers the handshake's headers, names and values: the page's, then those of WebSocket */
    public function __construct(
        /** The URL, with its http or https scheme. */
        public readonly string $url,
        public readonly array $headers,
    ) {}

    /** The URL without its password, as errors show it (Go's URL.Redacted). */
    public function redacted(): string
    {
        return (string)preg_replace('~^([a-z][a-z0-9+.-]*://[^/:@]*):[^/@]*@~i', '$1:xxxxx@', $this->url);
    }
}
