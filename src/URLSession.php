<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;
use ReflectionClass;
use ReflectionNamedType;

/**
 * URLSession, as Foundation's URLSession.shared in SwiftUI: makes non-blocking
 * HTTP(S) requests in fibers (`->task()`, `App::go()`), returning `[$data, $response]`
 * from `data(from:|for:)`, `upload(for:from:|fromFile:)`, `download(from:|for:)`,
 * or decoding JSON directly with `json()` / `decode($class, from:)`.
 */
final class URLSession
{
    private static ?self $shared = null;

    /** @param array<string, string> $defaultHeaders */
    public function __construct(
        public array $defaultHeaders = [],
        public float $timeoutInterval = 0.0,
    ) {}

    /** The shared singleton session, as Swift's `URLSession.shared`. */
    public static function shared(): self
    {
        return self::$shared ??= new self();
    }

    /**
     * Fetches bytes and metadata, as `try await URLSession.shared.data(from: url)`
     * or `data(for: request)`.
     * @return array{0: string, 1: HTTPURLResponse} [$data, $response]
     */
    public static function fetchData(URLRequest|string $from): array
    {
        return self::shared()->data($from);
    }

    /**
     * Instance / static-friendly `data(from:)` / `data(for:)`, as `data(for:delegate:)`: with a $delegate, its
     * didSendBodyData is told how the request's body goes and its didReceive each piece of the answer.
     * @return array{0: string, 1: HTTPURLResponse} [$data, $response]
     */
    public function data(URLRequest|string|null $from = null, URLRequest|string|null $for = null, ?URLSessionTaskDelegate $delegate = null): array
    {
        $target = $for ?? $from ?? throw new \InvalidArgumentException('URLSession::data requires a URL or URLRequest');
        $req = is_string($target) ? new URLRequest($target) : $target;
        $headers = array_merge($this->defaultHeaders, $req->allHTTPHeaderFields);
        $timeout = $req->timeoutInterval > 0 ? $req->timeoutInterval : $this->timeoutInterval;
        $task = new URLSessionTask($req);
        $r = Http::request($req->httpMethod, $req->url, $headers, $req->httpBody, $timeout,
            $this->told($delegate, $task),
            onData: $delegate === null ? null : fn(string $chunk) => $delegate->didReceive($this, $task, $chunk));
        return [$r->body, new HTTPURLResponse($req->url, $r->status, $r->headers)];
    }

    /**
     * Uploads data or a multipart form/file, as `URLSession.shared.upload(for: request, from: data, delegate:)`: with a
     * $delegate, its didSendBodyData is told how much has gone, and `$task->cancel()` ends the upload (URLError cancelled).
     * @param array<string, mixed>|string|null $from
     * @return array{0: string, 1: HTTPURLResponse}
     */
    public function upload(URLRequest|string $for, array|string|null $from = null, ?string $fromFile = null, ?URLSessionTaskDelegate $delegate = null): array
    {
        $req = is_string($for) ? new URLRequest($for, 'POST') : clone $for;
        if ($req->httpMethod === 'GET') {
            $req->httpMethod = 'POST';
        }
        $headers = array_merge($this->defaultHeaders, $req->allHTTPHeaderFields);
        $timeout = $req->timeoutInterval > 0 ? $req->timeoutInterval : $this->timeoutInterval;
        $progress = $this->told($delegate, $task = new URLSessionTask($req));
        if ($fromFile !== null) {
            $body = @file_get_contents($fromFile);
            $body !== false || throw new \RuntimeException("URLSession::upload: cannot read $fromFile");
            $r = Http::request($req->httpMethod, $req->url, $headers, $body, $timeout, $progress);
        } elseif (is_array($from)) {
            $r = Http::post($req->url, $from, $headers, $timeout, $progress);
        } else {
            $r = Http::request($req->httpMethod, $req->url, $headers, $from ?? $req->httpBody, $timeout, $progress);
        }
        return [$r->body, new HTTPURLResponse($req->url, $r->status, $r->headers)];
    }

    /**
     * Downloads a URL to a temporary file, as `URLSession.shared.download(from: url, delegate:)`: the body is written to
     * the file as it comes, not held in memory; with a $delegate, its didWriteData is told how much has come, and
     * `$task->cancel()` ends the download (URLError cancelled; no file is left).
     * @return array{0: string, 1: HTTPURLResponse} [$tempFilePath, $response]
     */
    public function download(URLRequest|string|null $from = null, URLRequest|string|null $for = null, ?URLSessionTaskDelegate $delegate = null): array
    {
        $target = $for ?? $from ?? throw new \InvalidArgumentException('URLSession::download requires a URL or URLRequest');
        $req = is_string($target) ? new URLRequest($target) : $target;
        $headers = array_merge($this->defaultHeaders, $req->allHTTPHeaderFields);
        $timeout = $req->timeoutInterval > 0 ? $req->timeoutInterval : $this->timeoutInterval;
        $tmp = tempnam(sys_get_temp_dir(), 'myphp-dl-') ?: (sys_get_temp_dir() . '/myphp-dl-' . bin2hex(random_bytes(6)));
        $task = new URLSessionTask($req);
        try {
            $r = Http::request($req->httpMethod, $req->url, $headers, $req->httpBody, $timeout,
                $this->told($delegate, $task, download: true), saveTo: $tmp);
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e;
        }
        return [$tmp, new HTTPURLResponse($req->url, $r->status, $r->headers)];
    }

    /**
     * What Http calls as a transfer goes, for $delegate: the task's counts are updated, didSendBodyData is told when the
     * body has gone further (and, for a download, didWriteData when the file has), and the transfer goes on unless the
     * task was cancelled. Null without a delegate.
     */
    private function told(?URLSessionTaskDelegate $delegate, URLSessionTask $task, bool $download = false): ?Closure
    {
        if ($delegate === null) {
            return null;
        }
        $sent = $written = 0;
        return function (int $uploadTotal, int $uploadNow, int $downloadTotal, int $downloadNow) use ($delegate, $task, $download, &$sent, &$written): bool {
            $task->update($uploadTotal, $uploadNow, $downloadTotal, $downloadNow);
            if ($uploadTotal > 0 && $uploadNow !== $sent) {
                $delegate->didSendBodyData($this, $task, $uploadNow - $sent, $uploadNow, $uploadTotal);
                $sent = $uploadNow;
            }
            if ($download && $downloadNow !== $written) {
                $delegate->didWriteData($this, $task, $downloadNow - $written, $downloadNow, $downloadTotal);
                $written = $downloadNow;
            }
            return !$task->progress->isCancelled;
        };
    }

    /**
     * Fetches and decodes JSON into an associative array.
     */
    public function json(URLRequest|string|null $from = null, URLRequest|string|null $for = null): mixed
    {
        [$bytes] = $this->data($from, $for);
        return json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Fetches JSON and maps it into a typed PHP class (or list of objects when
     * the JSON root is a list), as Swift's `JSONDecoder().decode(T.self, from: data)`.
     * @template T of object
     * @param class-string<T> $type
     * @return T|list<T>
     */
    public function decode(string $type, URLRequest|string|null $from = null, URLRequest|string|null $for = null): object|array
    {
        $decoded = $this->json($from, $for);
        if (!is_array($decoded)) {
            throw new \RuntimeException("URLSession::decode: expected JSON object or array for $type");
        }
        if (array_is_list($decoded)) {
            return array_map(fn($item) => self::hydrate($type, is_array($item) ? $item : []), $decoded);
        }
        return self::hydrate($type, $decoded);
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @param array<string, mixed> $data
     * @return T
     */
    public static function hydrate(string $type, array $data): object
    {
        $ref = new ReflectionClass($type);
        $ctor = $ref->getConstructor();
        if ($ctor !== null && $ctor->getNumberOfParameters() > 0) {
            $args = [];
            foreach ($ctor->getParameters() as $p) {
                $name = $p->getName();
                if (array_key_exists($name, $data)) {
                    $args[$name] = $data[$name];
                } elseif ($p->isDefaultValueAvailable()) {
                    $args[$name] = $p->getDefaultValue();
                } elseif ($p->allowsNull()) {
                    $args[$name] = null;
                }
            }
            $obj = $ref->newInstanceArgs($args);
        } else {
            $obj = $ref->newInstance();
        }
        foreach ($data as $k => $v) {
            if ($ref->hasProperty((string)$k)) {
                $prop = $ref->getProperty((string)$k);
                if ($prop->isPublic() && !$prop->isReadOnly()) {
                    $prop->setValue($obj, $v);
                }
            }
        }
        return $obj;
    }
}