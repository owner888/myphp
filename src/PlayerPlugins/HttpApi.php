<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use MyPHP\Media\Https;
use RuntimeException;

/**
 * IINA's iina.http, of the network's permission and the plugin's allowed
 * hosts: a request (get, post, put, patch, delete) with $options' params
 * (the query's), headers and data (a form's): the response, {statusCode,
 * reason, data (its JSON, null for none), text}; one of an error's status
 * thrown as HttpError; a file downloaded; an XML-RPC server's client.
 */
final class HttpApi extends Api
{
    /** @param array<string, mixed> $options @return array{statusCode: int, reason: string, data: mixed, text: ?string} */
    public function get(string $url, array $options = []): array { return $this->request('GET', $url, $options); }
    /** @param array<string, mixed> $options @return array{statusCode: int, reason: string, data: mixed, text: ?string} */
    public function post(string $url, array $options = []): array { return $this->request('POST', $url, $options); }
    /** @param array<string, mixed> $options @return array{statusCode: int, reason: string, data: mixed, text: ?string} */
    public function put(string $url, array $options = []): array { return $this->request('PUT', $url, $options); }
    /** @param array<string, mixed> $options @return array{statusCode: int, reason: string, data: mixed, text: ?string} */
    public function patch(string $url, array $options = []): array { return $this->request('PATCH', $url, $options); }
    /** @param array<string, mixed> $options @return array{statusCode: int, reason: string, data: mixed, text: ?string} */
    public function delete(string $url, array $options = []): array { return $this->request('DELETE', $url, $options); }

    /** Downloads $url ($options' method, params, headers, data) to $dest (an IINA path). @param array<string, mixed> $options */
    public function download(string $url, string $dest, array $options = []): void
    {
        $method = strtoupper((string)($options['method'] ?? 'GET'));
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true)) { $this->fail('method is invalid.'); }
        $path = $this->parsePath($dest)[0];
        [$status, $body] = $this->send($method, $url, $options);
        if ($status < 200 || $status >= 300) { throw new HttpError(self::response($status, $body)); }
        if (@file_put_contents($path, $body) === false) { $this->fail('Unable to write to the destination.'); }
    }

    /** A client of the XML-RPC server at $location. */
    public function xmlrpc(string $location): XmlRpc
    {
        $this->allowed($location);
        return new XmlRpc($this->instance, $location);
    }

    /** @param array<string, mixed> $options @return array{statusCode: int, reason: string, data: mixed, text: ?string} */
    private function request(string $method, string $url, array $options): array
    {
        [$status, $body] = $this->send($method, $url, $options);
        $r = self::response($status, $body);
        if ($status < 200 || $status >= 300) { throw new HttpError($r); }
        return $r;
    }

    /** @param array<string, mixed> $options @return array{0: int, 1: string} */
    private function send(string $method, string $url, array $options): array
    {
        $this->allowed($url);
        $params = is_array($options['params'] ?? null) ? $options['params'] : [];
        if ($params !== []) { $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986); }
        $headers = array_map('strval', is_array($options['headers'] ?? null) ? $options['headers'] : []);
        $data = is_array($options['data'] ?? null) ? $options['data'] : [];
        $body = null;
        if ($data !== []) {
            $body = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
            $headers += ['Content-Type' => 'application/x-www-form-urlencoded'];
        }
        try {
            [$status, $text] = Https::request($method, $url, $headers, $body, "http.$method", true);
        } catch (RuntimeException $e) {
            throw new HttpError(['statusCode' => 0, 'reason' => $e->getMessage(), 'data' => null, 'text' => null]);
        }
        return [$status, $text];
    }

    /** Throws unless the plugin may reach $url (the network's permission, its host allowed). */
    private function allowed(string $url): void
    {
        $this->require(Plugin::NETWORK);
        if (parse_url($url, PHP_URL_HOST) === null) { $this->fail("URL $url is invalid."); }
        if (!$this->instance->plugin->canAccess($url)) { $this->fail("URL $url is not allowed."); }
    }

    /** @return array{statusCode: int, reason: string, data: mixed, text: ?string} */
    private static function response(int $status, string $body): array
    {
        $json = json_decode($body, true);
        return ['statusCode' => $status, 'reason' => self::reason($status), 'data' => json_last_error() === JSON_ERROR_NONE ? $json : null, 'text' => $body];
    }

    /** The reason of HTTP status $code. */
    private static function reason(int $code): string
    {
        return [200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content', 206 => 'Partial Content', 301 => 'Moved Permanently', 302 => 'Found', 304 => 'Not Modified',
            400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed', 406 => 'Not Acceptable', 408 => 'Request Timeout', 409 => 'Conflict',
            410 => 'Gone', 413 => 'Payload Too Large', 415 => 'Unsupported Media Type', 429 => 'Too Many Requests', 500 => 'Internal Server Error', 501 => 'Not Implemented',
            502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Timeout'][$code] ?? ($code >= 200 && $code < 300 ? 'OK' : 'Error');
    }
}
