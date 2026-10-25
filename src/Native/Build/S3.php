<?php
declare(strict_types=1);

namespace MyPHP\Native\Build;

use RuntimeException;

/**
 * Uploads to Amazon S3 and compatible services, such as Cloudflare R2
 * (mygo's s3.go): objects put with a single request signed with AWS
 * Signature Version 4. myphp.json's "updates": {"s3": {...}}:
 *
 *     bucket     the bucket
 *     prefix     the directory of the files in it (default: its root)
 *     region     its region (default: $AWS_REGION, else $AWS_DEFAULT_REGION, else us-east-1)
 *     endpoint   the URL of a compatible service (default: Amazon S3)
 *     pathStyle  names the bucket in the path of URLs rather than in the
 *                host name (default: with endpoint, or a bucket name with dots)
 *
 * The credentials come from $AWS_ACCESS_KEY_ID, $AWS_SECRET_ACCESS_KEY and
 * $AWS_SESSION_TOKEN.
 *
 * @internal
 */
final class S3
{
    public string $bucket;
    public string $prefix;
    public string $region;
    public bool $pathStyle;
    /** @var array{scheme: string, host: string} */
    private array $endpoint;

    private function __construct(private readonly string $accessKey, private readonly string $secretKey, private readonly string $sessionToken) {}

    /** Checks updates.s3 without credentials (mygo's S3.validate). @param array<string, mixed> $s */
    public static function validate(array $s): void
    {
        if (($s['bucket'] ?? '') === '' || !is_string($s['bucket'])) {
            throw new RuntimeException('updates.s3 needs a bucket');
        }
        $endpoint = (string)($s['endpoint'] ?? '');
        if ($endpoint !== '') {
            $u = parse_url($endpoint);
            if ($u === false || !in_array($u['scheme'] ?? '', ['http', 'https'], true) || ($u['host'] ?? '') === '') {
                throw new RuntimeException("updates.s3.endpoint \"$endpoint\" is not an http(s) URL");
            }
            if (trim($u['path'] ?? '', '/') !== '' || isset($u['query'])) {
                throw new RuntimeException("updates.s3.endpoint \"$endpoint\" has a path: the endpoint is the service's, and bucket names the bucket");
            }
        }
    }

    /** A client of the bucket of updates.s3, with the credentials of the environment. @param array<string, mixed> $s */
    public static function client(array $s): self
    {
        self::validate($s);
        $env = fn(string $k): string => (string)getenv($k);
        $c = new self($env('AWS_ACCESS_KEY_ID'), $env('AWS_SECRET_ACCESS_KEY'), $env('AWS_SESSION_TOKEN'));
        if ($c->accessKey === '' || $c->secretKey === '') {
            throw new RuntimeException('uploading to updates.s3 needs the credentials in AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY');
        }
        $c->bucket = $s['bucket'];
        $c->prefix = trim((string)($s['prefix'] ?? ''), '/');
        $c->region = array_values(array_filter([(string)($s['region'] ?? ''), $env('AWS_REGION'), $env('AWS_DEFAULT_REGION'), 'us-east-1'], fn($v) => $v !== ''))[0];
        $endpoint = (string)($s['endpoint'] ?? '');
        $c->pathStyle = isset($s['pathStyle']) ? (bool)$s['pathStyle'] : ($endpoint !== '' || str_contains($c->bucket, '.'));
        $u = parse_url($endpoint !== '' ? $endpoint : "https://s3.{$c->region}.amazonaws.com");
        $c->endpoint = ['scheme' => $u['scheme'], 'host' => $u['host'] . (isset($u['port']) ? ":{$u['port']}" : '')];
        return $c;
    }

    /** A client with the given credentials and endpoint, for tests of the signature. */
    public static function with(string $bucket, string $region, string $endpoint, string $accessKey, string $secretKey, string $sessionToken = ''): self
    {
        $c = new self($accessKey, $secretKey, $sessionToken);
        $c->bucket = $bucket; $c->prefix = ''; $c->region = $region; $c->pathStyle = false;
        $u = parse_url($endpoint);
        $c->endpoint = ['scheme' => $u['scheme'], 'host' => $u['host']];
        return $c;
    }

    /** The key of the file named $name. */
    public function key(string $name): string
    {
        return $this->prefix === '' ? $name : "{$this->prefix}/$name";
    }

    /** The URL of the object of $key, and its host and path apart. @return array{string, string, string} */
    public function objectURL(string $key): array
    {
        $host = $this->endpoint['host'];
        $path = '/' . self::escape($key);
        if ($this->pathStyle) {
            $path = '/' . self::escape($this->bucket) . $path;
        } else {
            $host = "{$this->bucket}.$host";
        }
        return ["{$this->endpoint['scheme']}://$host$path", $host, $path];
    }

    /**
     * Signs a PUT of $path on $host, which has no query, with AWS Signature
     * Version 4 for S3: its headers with the host, and a payload of the
     * SHA-256 $payloadHash. Returns the headers with the signature's.
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    public function sign(string $host, string $path, array $headers, string $payloadHash, int $time): array
    {
        $date = gmdate('Ymd', $time); $amzDate = gmdate('Ymd\THis\Z', $time);
        $headers['X-Amz-Date'] = $amzDate;
        $headers['X-Amz-Content-Sha256'] = $payloadHash;
        if ($this->sessionToken !== '') {
            $headers['X-Amz-Security-Token'] = $this->sessionToken;
        }
        $canon = ['host' => $host];
        foreach ($headers as $name => $v) {
            $canon[strtolower($name)] = implode(' ', preg_split('/\s+/', trim($v)) ?: []);
        }
        ksort($canon, SORT_STRING);
        $canonical = '';
        foreach ($canon as $name => $v) {
            $canonical .= "$name:$v\n";
        }
        $signed = implode(';', array_keys($canon));
        $request = "PUT\n$path\n\n$canonical\n$signed\n$payloadHash";
        $scope = "$date/{$this->region}/s3/aws4_request";
        $key = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        foreach ([$this->region, 's3', 'aws4_request'] as $s) {
            $key = hash_hmac('sha256', $s, $key, true);
        }
        $signature = hash_hmac('sha256', "AWS4-HMAC-SHA256\n$amzDate\n$scope\n" . hash('sha256', $request), $key);
        $headers['Authorization'] = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/$scope, SignedHeaders=$signed, Signature=$signature";
        return $headers;
    }

    /** Uploads the file at $file to $key with $headers, through curl; S3 redirects requests for another region, which are not followed. @param array<string, string> $headers */
    public function put(string $key, string $file, array $headers, string $userAgent): void
    {
        [$url, $host, $path] = $this->objectURL($key);
        $headers = $this->sign($host, $path, $headers, hash_file('sha256', $file), time());
        $args = ['curl', '-sS', '-X', 'PUT', '--upload-file', $file, '-A', $userAgent, '-H', 'Expect:', '-D', '-', '-w', "\n%{http_code}"];
        foreach ($headers as $name => $v) {
            array_push($args, '-H', "$name: $v");
        }
        $args[] = $url;
        [$code, $out] = Cmd::output($args);
        if ($code !== 0) {
            throw new RuntimeException("uploading to s3://{$this->bucket}/$key: curl exited with $code: " . trim($out));
        }
        $status = (int)substr($out, (int)strrpos($out, "\n") + 1);
        if (intdiv($status, 100) === 2) {
            return;
        }
        $response = substr($out, 0, (int)strrpos($out, "\n"));
        [$head, $body] = array_pad(explode("\r\n\r\n", $response, 2), 2, '');
        $msg = preg_match('~^HTTP/\S+ (\d+ .*)$~m', $head, $m) ? trim($m[1]) : (string)$status;
        if (preg_match('~<Code>(.*?)</Code>~s', $body, $c) && preg_match('~<Message>(.*?)</Message>~s', $body, $mm)) {
            $msg .= ": {$c[1]}: {$mm[1]}"; // S3 describes errors in XML
        }
        if (preg_match('~^x-amz-bucket-region:\s*(\S+)~mi', $head, $r) && $r[1] !== $this->region) {
            $msg .= " (the bucket is in {$r[1]}: set updates.s3.region)";
        }
        throw new RuntimeException("uploading to s3://{$this->bucket}/$key: $msg");
    }

    /** Escapes a key for the path of a URL as Signature Version 4 wants it: every byte but letters, digits, "-._~" and "/". */
    public static function escape(string $s): string
    {
        return (string)preg_replace_callback('~[^A-Za-z0-9\-._\~/]~', fn(array $m) => sprintf('%%%02X', ord($m[0])), $s);
    }
}
