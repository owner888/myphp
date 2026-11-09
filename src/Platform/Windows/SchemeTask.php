<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use Closure;

/**
 * The answer to a request of the app's schemes in a page of WebView2
 * (WebView::resourceRequested): WebView2 takes a response whole, of an
 * IStream, so its body is gathered as it is written and the response made
 * as it finishes (mygo's schemeResponse); a failure is a 500 whose body
 * says why.
 */
final class SchemeTask implements \MyPHP\Native\SchemeTask
{
    private int $status = 200;
    /** @var array<string, string> */
    private array $headers = [];
    private string $body = '';
    private bool $responded = false, $done = false;

    /** @param Closure(int, array<string, string>, string): void $send makes the response */
    public function __construct(private readonly Closure $send) {}

    public function respond(int $status, array $headers): void
    {
        if ($this->done || $this->responded) {
            return;
        }
        [$this->status, $this->headers, $this->responded] = [$status, $headers, true];
    }

    public function write(string $bytes): void
    {
        if (!$this->done) {
            $this->body .= $bytes;
        }
    }

    public function finish(): void
    {
        if ($this->done) {
            return;
        }
        $this->done = true;
        ($this->send)($this->status, $this->headers, $this->body);
        $this->body = '';
    }

    public function fail(string $message): void
    {
        if ($this->done) {
            return;
        }
        $this->done = true;
        ($this->send)(500, ['Content-Type' => 'text/plain; charset=utf-8'], $message);
    }

    public function stopped(): bool
    {
        return $this->done;
    }
}
