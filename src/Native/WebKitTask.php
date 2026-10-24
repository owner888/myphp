<?php
declare(strict_types=1);

namespace MyPHP\Native;

/**
 * The SchemeTask of a WKURLSchemeTask (mygo's darwin schemeTask): kept
 * retained until it finishes, fails or WebKit stops it, after which it
 * must not be touched.
 *
 * @internal
 */
final class WebKitTask implements SchemeTask
{
    /** @var array<int, WebKitTask> the live tasks by WKURLSchemeTask address */
    public static array $live = [];
    private bool $responded = false, $done = false;
    public bool $isStopped = false;

    public function __construct(private $task)
    {
        ObjC::msg('p:', $task, 'retain');
        self::$live[ObjC::addr($task)] = $this;
    }

    public function stopped(): bool
    {
        return $this->isStopped || $this->done;
    }

    public function respond(int $status, array $headers): void
    {
        if ($this->stopped() || $this->responded) {
            return;
        }
        $this->responded = true;
        $fields = ObjC::msg('p:', ObjC::cls('NSMutableDictionary'), 'dictionary');
        foreach ($headers as $k => $v) {
            ObjC::msg('v:pp', $fields, 'setObject:forKey:', ObjC::str($v), ObjC::str((string)$k));
        }
        $url = ObjC::msg('p:', ObjC::msg('p:', $this->task, 'request'), 'URL');
        $resp = ObjC::msg('p:plpp', ObjC::alloc('NSHTTPURLResponse'), 'initWithURL:statusCode:HTTPVersion:headerFields:', $url, $status, ObjC::str('HTTP/1.1'), $fields);
        ObjC::msg('v:p', $this->task, 'didReceiveResponse:', $resp);
        ObjC::msg('v:', $resp, 'release');
    }

    public function write(string $bytes): void
    {
        if ($this->stopped() || $bytes === '') {
            return;
        }
        if (!$this->responded) {
            $this->respond(200, []);
        }
        ObjC::msg('v:p', $this->task, 'didReceiveData:', ObjC::msg('p:sL', ObjC::cls('NSData'), 'dataWithBytes:length:', $bytes, strlen($bytes)));
    }

    public function finish(): void
    {
        if ($this->stopped()) {
            return;
        }
        if (!$this->responded) {
            $this->respond(200, []);
        }
        ObjC::msg('v:', $this->task, 'didFinish');
        $this->release();
    }

    public function fail(string $message): void
    {
        if ($this->stopped()) {
            return;
        }
        $info = ObjC::msg('p:', ObjC::cls('NSMutableDictionary'), 'dictionary');
        ObjC::msg('v:pp', $info, 'setObject:forKey:', ObjC::str($message), ObjC::str('NSLocalizedDescription'));
        ObjC::msg('v:p', $this->task, 'didFailWithError:', ObjC::msg('p:plp', ObjC::cls('NSError'), 'errorWithDomain:code:userInfo:', ObjC::str('myphp'), -1, $info));
        $this->release();
    }

    /** WebKit stopped the task: the page no longer reads it. */
    public function stop(): void
    {
        $this->isStopped = true;
        $this->release();
    }

    private function release(): void
    {
        if ($this->done) {
            return;
        }
        $this->done = true;
        unset(self::$live[ObjC::addr($this->task)]);
        ObjC::msg('v:', $this->task, 'release');
    }

    /** The request of a WKURLSchemeTask: method, URL, headers and body. @return array{0: string, 1: string, 2: array<string, string>, 3: string} */
    public static function request($task): array
    {
        $r = ObjC::msg('p:', $task, 'request');
        $url = ObjC::phpStr(ObjC::msg('p:', ObjC::msg('p:', $r, 'URL'), 'absoluteString'));
        $method = ObjC::phpStr(ObjC::msg('p:', $r, 'HTTPMethod'));
        $headers = [];
        $fields = ObjC::msg('p:', $r, 'allHTTPHeaderFields');
        if ($fields !== null) {
            $keys = ObjC::msg('p:', $fields, 'allKeys');
            for ($i = 0, $n = ObjC::msg('L:', $keys, 'count'); $i < $n; $i++) {
                $k = ObjC::msg('p:L', $keys, 'objectAtIndex:', $i);
                $headers[ObjC::phpStr($k)] = ObjC::phpStr(ObjC::msg('p:p', $fields, 'objectForKey:', $k));
            }
        }
        $body = '';
        if (($data = ObjC::msg('p:', $r, 'HTTPBody')) !== null) {
            $body = Drag::bytes($data);
        } elseif (($stream = ObjC::msg('p:', $r, 'HTTPBodyStream')) !== null) {
            ObjC::msg('v:', $stream, 'open');
            $buf = ObjC::$c->new('char[65536]');
            while (($n = ObjC::msg('l:pL', $stream, 'read:maxLength:', ObjC::$c->cast('void*', \FFI::addr($buf)), 65536)) > 0) {
                $body .= \FFI::string($buf, $n);
            }
            ObjC::msg('v:', $stream, 'close');
        }
        return [$method !== '' ? $method : 'GET', $url, $headers, $body];
    }
}
