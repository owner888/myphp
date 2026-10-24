<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * What a Protocol handler answers (mygo's http.ResponseWriter): a status,
 * headers and a body. The body is a string, or an iterable of strings
 * (a Generator), whose chunks reach the page one run loop turn apart, as
 * a Flush of mygo's does; a body read from a file thus never sits in
 * memory whole. Without a Content-Type, it is sniffed from the body's
 * first bytes.
 */
final class ProtocolResponse
{
    /**
     * @param array<string, string> $headers
     * @param string|iterable<string> $body
     */
    public function __construct(
        public int $status = 200,
        public array $headers = [],
        public string|iterable $body = '',
    ) {}

    /** The header $name, whatever its case, or ''. */
    public function header(string $name): string
    {
        foreach ($this->headers as $k => $v) {
            if (strcasecmp((string)$k, $name) === 0) {
                return $v;
            }
        }
        return '';
    }

    /** Sets the header $name, replacing one of another case. */
    public function setHeader(string $name, string $value): void
    {
        foreach (array_keys($this->headers) as $k) {
            if (strcasecmp((string)$k, $name) === 0) {
                unset($this->headers[$k]);
            }
        }
        $this->headers[$name] = $value;
    }
}
