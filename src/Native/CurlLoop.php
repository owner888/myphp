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
 * a socket number, kept: see $descriptors), on Linux in a running app by
 * GLib's fd sources of the main loop (g_unix_fd_add), and its timeouts by
 * timers (CURLMOPT_TIMERFUNCTION), so that nothing waits and nothing is
 * polled. On Linux without the app running (a test, a tool) there is no
 * loop: pump() drives a multi handle of its own, curl_multi_poll and
 * curl_multi_perform.
 *
 * waitUntil() waits for what a transfer brings: in a fiber of the app's, the
 * fiber waits (App::go), resumed once what it waits for came, looked at as
 * each socket's data is read; outside one, the run loop turns meanwhile.
 *
 * @internal Media\HttpBytes, MyPHP\Http
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
    /** The multi handle the loop drives, and on Linux the one pump() drives while the app does not run. */
    private static ?CData $multi = null, $pumped = null;
    /** @var array<int, array{0: Closure(int): void, 1: CData}> what is told when each transfer ends, with its CURLcode, and its multi handle, by its handle's address */
    private static array $done = [];
    /** @var array<int, int> GLib's source watching each socket (Linux), by the socket */
    private static array $sources = [];
    /** G_IO_IN, G_IO_OUT, G_IO_ERR, G_IO_HUP; CURL_CSELECT_ERR. */
    private const G_IN = 1, G_OUT = 4, G_ERR = 8, G_HUP = 16, CSELECT_ERR = 4;
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

    /** Whether the run loop drives the transfers (macOS; Linux's main loop in a running app), else pump(). */
    private static function driven(): bool
    {
        return PHP_OS_FAMILY === 'Darwin' || App::running();
    }

    /** The multi handle of transfers started now: the loop's, or pump()'s. */
    private static function multi(): CData
    {
        if (!self::driven()) {
            return self::$pumped ??= Curl::ffi()->curl_multi_init();
        }
        if (self::$multi !== null) {
            return self::$multi;
        }
        $f = Curl::ffi();
        self::$multi = $f->curl_multi_init();
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
        return self::$multi;
    }

    /** Starts the transfer of easy handle $easy, set up; $done is told its CURLcode once it ends (0 for done), after it left the loop. */
    public static function start(CData $easy, Closure $done): void
    {
        $m = self::multi();
        self::$done[Mem::addr($easy)] = [$done, $m];
        Curl::ffi()->curl_multi_add_handle($m, $easy); // the timer callback starts it, or pump()
    }

    /** Stops the transfer of $easy, untold; the caller cleans the handle up. */
    public static function stop(CData $easy): void
    {
        if (isset(self::$done[$key = Mem::addr($easy)])) {
            $m = self::$done[$key][1];
            unset(self::$done[$key]);
            Curl::ffi()->curl_multi_remove_handle($m, $easy);
        }
    }

    /** Watches socket $fd for what curl waits for ($what, CURL_POLL_*), or lets it go. */
    private static function watch(int $fd, int $what): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            self::watchGlib($fd, $what);
            return;
        }
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

    private static ?CData $glibCallback = null;

    /**
     * Watches socket $fd with a source of GLib's main loop for what curl
     * waits for, in place of the one before, or lets it go (Linux). The
     * source fires while the socket is ready, as poll() tells: no draining.
     */
    private static function watchGlib(int $fd, int $what): void
    {
        $g = \MyPHP\Platform\Linux\Gtk::ffi();
        if (isset(self::$sources[$fd])) {
            $g->g_source_remove(self::$sources[$fd]);
            unset(self::$sources[$fd]);
        }
        if ($what === self::POLL_REMOVE) {
            unset(self::$sockets[$fd]);
            return;
        }
        self::$sockets[$fd] = $what;
        if (self::$glibCallback === null) {
            $h = $g->new('HFd', false); // for the process
            $h->fn = static function (int $fd, int $cond, $data): int {
                $id = self::$sources[$fd] ?? null;
                if ($id === null) { return 0; }
                self::act($fd, (($cond & (self::G_IN | self::G_HUP)) ? self::CSELECT_IN : 0) | (($cond & self::G_OUT) ? self::CSELECT_OUT : 0) | (($cond & self::G_ERR) ? self::CSELECT_ERR : 0));
                return (self::$sources[$fd] ?? null) === $id ? 1 : 0; // G_SOURCE_CONTINUE, unless curl let it go or watches it anew
            };
            self::$glibCallback = $h;
        }
        $cond = (($what & self::POLL_IN) ? self::G_IN | self::G_HUP | self::G_ERR : 0) | (($what & self::POLL_OUT) ? self::G_OUT | self::G_ERR : 0);
        self::$sources[$fd] = $g->g_unix_fd_add_full(0, $fd, $cond, self::$glibCallback->fn, null, null); // G_PRIORITY_DEFAULT
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
        $f->curl_multi_socket_action(self::$multi, $fd, $events, FFI::addr($running));
        self::$running = $running->cdata;
        self::finished(self::$multi);
        self::wake();
    }

    /** Seconds between looks at the fibers' waits while there are any. */
    private const POLL = 0.05;
    private static bool $polling = false;

    /** Looks at the fibers' waits every POLL seconds while there are any. */
    private static function poll(): void
    {
        if (self::$polling) {
            return;
        }
        self::$polling = true;
        App::after(self::POLL, static function (): void {
            self::$polling = false;
            self::wake();
            if (self::$waiters !== []) { self::poll(); }
        });
    }

    /** Resumes the fibers whose wait is over, each on its own turn of the run loop. */
    private static function wake(): void
    {
        foreach (self::$waiters as $k => [$done, $fiber]) {
            try {
                $over = $done();
            } catch (\Throwable) { // what it waits for failed: thrown in the fiber, which looks again, not in the loop's callback
                $over = true;
            }
            if ($over) {
                unset(self::$waiters[$k]);
                App::later(static fn() => $fiber->resume());
            }
        }
    }

    /** Tells the transfers of multi handle $m that ended, each once it left it. */
    private static function finished(CData $m): void
    {
        $f = Curl::ffi();
        $left = $f->new('int');
        $ended = [];
        while (($msg = $f->curl_multi_info_read($m, FFI::addr($left))) !== null && !FFI::isNull($msg)) {
            if ($msg->msg === Curl::MSG_DONE) { $ended[] = [$msg->easy_handle, $msg->data->result]; }
        }
        foreach ($ended as [$easy, $result]) {
            $fn = self::$done[$key = Mem::addr($easy)][0] ?? null;
            unset(self::$done[$key]);
            $f->curl_multi_remove_handle($m, $easy);
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

    /** Drives the transfers for up to $seconds where no run loop does (Linux without the app running): waits for their sockets, then reads and writes; else turns the loop that drives them. */
    public static function pump(float $seconds): void
    {
        if (PHP_OS_FAMILY !== 'Darwin' && App::running()) {
            $at = microtime(true) + $seconds;
            App::wait(fn(): bool => microtime(true) >= $at);
            return;
        }
        if (self::driven()) {
            ObjC::boot(['Foundation']); // before anything booted it: a tool's wait for yt-dlp, the first thing it does
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
        $m = self::multi();
        $f->curl_multi_poll($m, null, 0, (int)($seconds * 1000), FFI::addr($n));
        $running = $f->new('int');
        $f->curl_multi_perform($m, FFI::addr($running));
        self::$running = $running->cdata;
        self::finished($m);
    }

    /**
     * Waits until $done() holds, the transfers going on: in a fiber of the
     * running app, the fiber (App::go), looked at as transfers move and
     * every POLL seconds (what it waits for may be no transfer's: a child
     * process's end, yt-dlp's); outside one, the run loop turning
     * (App::wait); without the app, pump().
     */
    public static function waitUntil(Closure $done): void
    {
        if ($done()) {
            return;
        }
        if (App::running() && ($fiber = Fiber::getCurrent()) !== null) {
            self::$waiters[] = [$done, $fiber];
            self::poll();
            Fiber::suspend();
        } elseif (App::running()) {
            App::wait($done);
        } else {
            while (!$done()) { self::pump(0.02); }
        }
    }
}
