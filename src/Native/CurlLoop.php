<?php
declare(strict_types=1);

namespace MyPHP\Native;

use Closure;
use FFI;
use FFI\CData;
use Fiber;
use MyPHP\App;

/**
 * Transfers of libcurl the run loop drives as their sockets are ready, on
 * the main thread: one multi handle for the process, its sockets watched
 * by CFFileDescriptors of the main run loop (CURLMOPT_SOCKETFUNCTION; one
 * a socket number, kept: see $descriptors) and
 * its timeouts by timers (CURLMOPT_TIMERFUNCTION), so that nothing waits
 * and nothing is polled. Off macOS (a test's Linux) there is no run loop:
 * pump() drives it, curl_multi_poll and curl_multi_perform.
 *
 * waitUntil() waits for what a transfer brings: in a fiber of the app's, the
 * fiber waits (App::go), resumed once what it waits for came, looked at as
 * each socket's data is read; outside one, the run loop turns meanwhile.
 *
 * @internal Media\HttpBytes
 */
final class CurlLoop
{
    private const MOPT_SOCKETFUNCTION = 20001, MOPT_TIMERFUNCTION = 20004;
    private const POLL_IN = 1, POLL_OUT = 2, POLL_REMOVE = 4;
    private const CSELECT_IN = 1, CSELECT_OUT = 2;
    private const SOCKET_TIMEOUT = -1;
    /** kCFFileDescriptorReadCallBack, WriteCallBack. */
    private const CF_READ = 1, CF_WRITE = 2;

    private static ?FFI $cf = null;
    private static ?CData $multi = null;
    /** @var array<int, Closure(int): void> what is told when each transfer ends, with its CURLcode, by its handle's address */
    private static array $done = [];
    /** @var array<int, int> what curl waits for of each socket it watches (CURL_POLL_*), by the socket */
    private static array $sockets = [];
    /**
     * @var array<int, CData> the CFFileDescriptor of each socket number, kept for the process: never invalidated, its
     * callbacks only disabled when curl lets the socket go (an invalidation, done later, took the kevent of the next
     * socket of the number, which curl opens at once: its transfer stood still)
     */
    private static array $descriptors = [];
    /** The callbacks' holders, kept for the process; the timer asked for last. */
    private static ?CData $holders = null;
    private static int $timer = 0;
    private static int $running = 0;
    /** @var list<array{0: Closure(): bool, 1: Fiber}> the fibers waiting, each for what it waits for */
    private static array $waiters = [];

    /** Whether the run loop drives the transfers (macOS), else pump(). */
    private static function driven(): bool
    {
        return PHP_OS_FAMILY === 'Darwin';
    }

    private static function multi(): CData
    {
        if (self::$multi !== null) {
            return self::$multi;
        }
        $f = Curl::ffi();
        self::$multi = $f->curl_multi_init();
        if (self::driven()) {
            self::$holders = $f->new('struct { int (*socket)(void*, int, int, void*, void*); int (*timer)(void*, long, void*); }');
            self::$holders->socket = static function ($easy, int $fd, int $what, $user, $socketp): int {
                self::watch($fd, $what);
                return 0;
            };
            self::$holders->timer = static function ($multi, int $ms, $user): int {
                $id = ++self::$timer;
                if ($ms >= 0) {
                    $run = static function () use ($id): void { if (self::$timer === $id) { self::act(self::SOCKET_TIMEOUT, 0); } };
                    $ms === 0 ? App::later($run) : App::after($ms / 1000, $run);
                }
                return 0;
            };
            $f->curl_multi_setopt(self::$multi, self::MOPT_SOCKETFUNCTION, self::$holders->socket);
            $f->curl_multi_setopt(self::$multi, self::MOPT_TIMERFUNCTION, self::$holders->timer);
        }
        return self::$multi;
    }

    /** Starts the transfer of easy handle $easy, set up; $done is told its CURLcode once it ends (0 for done), after it left the loop. */
    public static function start(CData $easy, Closure $done): void
    {
        $m = self::multi();
        self::$done[Mem::addr($easy)] = $done;
        Curl::ffi()->curl_multi_add_handle($m, $easy); // the timer callback starts it
    }

    /** Stops the transfer of $easy, untold; the caller cleans the handle up. */
    public static function stop(CData $easy): void
    {
        if (isset(self::$done[$key = Mem::addr($easy)])) {
            unset(self::$done[$key]);
            Curl::ffi()->curl_multi_remove_handle(self::multi(), $easy);
        }
    }

    /** Watches socket $fd for what curl waits for ($what, CURL_POLL_*), or lets it go. */
    private static function watch(int $fd, int $what): void
    {
        $cf = self::cf();
        if ($what === self::POLL_REMOVE) {
            if (isset(self::$descriptors[$fd])) { $cf->CFFileDescriptorDisableCallBacks(self::$descriptors[$fd], self::CF_READ | self::CF_WRITE); }
            unset(self::$sockets[$fd]);
            return;
        }
        if (!isset(self::$descriptors[$fd])) {
            $d = $cf->CFFileDescriptorCreate(null, $fd, false, self::$cfCallback, null); // the socket is curl's: not closed with it
            $src = $cf->CFFileDescriptorCreateRunLoopSource(null, $d, 0);
            $cf->CFRunLoopAddSource($cf->CFRunLoopGetMain(), $src, $cf->kCFRunLoopCommonModes);
            $cf->CFRelease($src); // the run loop's
            self::$descriptors[$fd] = $d;
        }
        self::$sockets[$fd] = $what;
        self::arm($fd);
    }

    /** Enables the callbacks of $fd's descriptor for what curl waits for: they fire once each. */
    private static function arm(int $fd): void
    {
        $d = self::$descriptors[$fd]; $what = self::$sockets[$fd];
        $flags = (($what & self::POLL_IN) ? self::CF_READ : 0) | (($what & self::POLL_OUT) ? self::CF_WRITE : 0);
        $cf = self::cf();
        $cf->CFFileDescriptorDisableCallBacks($d, (self::CF_READ | self::CF_WRITE) & ~$flags);
        if ($flags !== 0) { $cf->CFFileDescriptorEnableCallBacks($d, $flags); }
    }

    private static mixed $cfCallback = null;

    private static function cf(): FFI
    {
        if (self::$cf === null) {
            self::$cf = FFI::cdef('
                typedef void (*callout)(void*, unsigned long, void*);
                void* CFFileDescriptorCreate(void*, int, bool, callout, void*);
                int   CFFileDescriptorGetNativeDescriptor(void*);
                void  CFFileDescriptorEnableCallBacks(void*, unsigned long);
                void  CFFileDescriptorDisableCallBacks(void*, unsigned long);
                void* CFFileDescriptorCreateRunLoopSource(void*, void*, long);
                void* CFRunLoopGetMain(void);
                void  CFRunLoopAddSource(void*, void*, void*);
                void  CFRelease(void*);
                extern void* kCFRunLoopCommonModes;
            ', '/System/Library/Frameworks/CoreFoundation.framework/CoreFoundation');
            $holder = self::$cf->new('struct { callout fn; }', false); // for the process
            $holder->fn = static function ($d, int $types, $info): void { // on the main run loop: a socket ready
                $fd = self::$cf->CFFileDescriptorGetNativeDescriptor($d);
                if (!isset(self::$sockets[$fd])) { return; } // a socket curl let go, its number's next not yet watched
                self::drain($fd, (($types & self::CF_READ) ? self::CSELECT_IN : 0) | (($types & self::CF_WRITE) ? self::CSELECT_OUT : 0));
            };
            self::$cfCallback = $holder->fn;
        }
        return self::$cf;
    }

    /** How many times a socket is acted on in a turn, before the next turn goes on with it. */
    private const DRAIN = 64;
    /** POLLIN, POLLOUT. */
    private const POLLIN = 1, POLLOUT = 4;

    /**
     * Acts on socket $fd while it is ready for what curl waits for, then
     * arms its descriptor again. A descriptor's callback tells a change, not
     * a state: curl reads a buffer at a time, and what it left in the socket
     * (a fast server's, which then waits for room to send) tells nothing
     * more, the transfer standing still (128 KiB in, at 0 B/s, a local
     * server's 44 MB/s). poll() says whether more is there; past DRAIN
     * times, the next turn of the run loop goes on, the rest of the app
     * running between.
     */
    private static function drain(int $fd, int $events): void
    {
        for ($i = 0; $i < self::DRAIN; $i++) {
            self::act($fd, $events);
            if (!isset(self::$sockets[$fd])) {
                return; // curl let it go
            }
            $events = self::ready($fd, self::$sockets[$fd]);
            if ($events === 0) {
                self::arm($fd);
                return;
            }
        }
        App::later(static function () use ($fd): void {
            if (isset(self::$sockets[$fd])) { self::drain($fd, self::ready($fd, self::$sockets[$fd])); }
        });
    }

    private static ?FFI $libc = null;

    /** What socket $fd is ready for now of what curl waits for ($what, CURL_POLL_*), as CURL_CSELECT_* bits; 0 for nothing. */
    private static function ready(int $fd, int $what): int
    {
        $c = self::$libc ??= FFI::cdef('struct pollfd { int fd; short events; short revents; }; int poll(struct pollfd*, unsigned int, int);');
        $p = $c->new('struct pollfd');
        $p->fd = $fd;
        $p->events = (($what & self::POLL_IN) ? self::POLLIN : 0) | (($what & self::POLL_OUT) ? self::POLLOUT : 0);
        if ($p->events === 0 || $c->poll(FFI::addr($p), 1, 0) <= 0) {
            return 0;
        }
        return (($p->revents & self::POLLIN) ? self::CSELECT_IN : 0) | (($p->revents & self::POLLOUT) ? self::CSELECT_OUT : 0);
    }

    /** Tells curl socket $fd is ready for $events (or its timeout came), then what ended. */
    private static function act(int $fd, int $events): void
    {
        $f = Curl::ffi();
        $running = $f->new('int');
        $f->curl_multi_socket_action(self::multi(), $fd, $events, FFI::addr($running));
        self::$running = $running->cdata;
        self::finished();
        self::wake();
    }

    /** Resumes the fibers whose wait is over, each on its own turn of the run loop. */
    private static function wake(): void
    {
        foreach (self::$waiters as $k => [$done, $fiber]) {
            if ($done()) {
                unset(self::$waiters[$k]);
                App::later(static fn() => $fiber->resume());
            }
        }
    }

    /** Tells the transfers that ended, each once it left the loop. */
    private static function finished(): void
    {
        $f = Curl::ffi();
        $left = $f->new('int');
        $ended = [];
        while (($msg = $f->curl_multi_info_read(self::multi(), FFI::addr($left))) !== null && !FFI::isNull($msg)) {
            if ($msg->msg === Curl::MSG_DONE) { $ended[] = [$msg->easy_handle, $msg->data->result]; }
        }
        foreach ($ended as [$easy, $result]) {
            $fn = self::$done[$key = Mem::addr($easy)] ?? null;
            unset(self::$done[$key]);
            $f->curl_multi_remove_handle(self::multi(), $easy);
            $fn?->__invoke($result);
        }
    }

    /** @var list<array{0: float, 1: Closure(): void}> what runs after a while where no run loop does (pump()'s), by when */
    private static array $later = [];

    /** Runs $fn in $seconds: a timer of the run loop's, else (off macOS) pump()'s. */
    public static function after(float $seconds, Closure $fn): void
    {
        if (self::driven()) {
            App::after($seconds, $fn);
            return;
        }
        self::$later[] = [microtime(true) + $seconds, $fn];
    }

    /** Drives the transfers for up to $seconds where no run loop does (off macOS): waits for their sockets, then reads and writes. */
    public static function pump(float $seconds): void
    {
        if (self::driven()) {
            $until = ObjC::msg('p:d', ObjC::cls('NSDate'), 'dateWithTimeIntervalSinceNow:', $seconds);
            ObjC::msg('v:p', ObjC::msg('p:', ObjC::cls('NSRunLoop'), 'currentRunLoop'), 'runUntilDate:', $until);
            return;
        }
        $now = microtime(true);
        foreach (self::$later as $k => [$at, $fn]) {
            if ($at <= $now) { unset(self::$later[$k]); $fn(); }
        }
        $f = Curl::ffi();
        $n = $f->new('int');
        $f->curl_multi_poll(self::multi(), null, 0, (int)($seconds * 1000), FFI::addr($n));
        $running = $f->new('int');
        $f->curl_multi_perform(self::multi(), FFI::addr($running));
        self::$running = $running->cdata;
        self::finished();
    }

    /**
     * Waits until $done() holds, the transfers going on: in a fiber of the
     * running app, the fiber (App::go); outside one, the run loop turning
     * (App::wait); without the app, pump().
     */
    public static function waitUntil(Closure $done): void
    {
        if ($done()) {
            return;
        }
        if (App::running() && ($fiber = Fiber::getCurrent()) !== null) {
            self::$waiters[] = [$done, $fiber];
            Fiber::suspend();
        } elseif (App::running()) {
            App::wait($done);
        } else {
            while (!$done()) { self::pump(0.02); }
        }
    }
}
