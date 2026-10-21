<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal\Pty;

use FFI;
use RuntimeException;

/**
 * A process running in a pseudo-terminal (mygo's pty.PTY): read() returns
 * its output, write() types into it. The terminal is /dev/ptmx's, opened
 * through libc; its child is spawn.php in a PHP of its own, which makes the
 * terminal its controlling terminal and becomes the program, as a forked
 * PHP running AppKit could not safely do. Reads and writes do not block:
 * the terminal polls them on the run loop.
 *
 * @internal
 */
final class Pty
{
    private static ?FFI $c = null;
    /** @var resource the master side */
    private $master;
    private int $fd;
    /** @var resource the child's process */
    private $proc;
    private int $pid;
    private ?int $code = null;
    private bool $closed = false;

    private static function c(): FFI
    {
        return self::$c ??= FFI::cdef('int posix_openpt(int); int grantpt(int); int unlockpt(int); char *ptsname(int);
            int open(const char*, int, ...); int close(int); int ioctl(int, unsigned long, ...); int fcntl(int, int, ...); int kill(int, int);
            typedef struct { uint16_t row, col, xpixel, ypixel; } winsize;');
    }

    private static function mac(): bool { return PHP_OS_FAMILY === 'Darwin'; }

    /** Starts a process in a pseudo-terminal (mygo's pty.Start). */
    public static function start(Options $o): self
    {
        if ($o->cols <= 0 || $o->rows <= 0) { [$o->cols, $o->rows] = [80, 24]; }
        if ($o->args === []) { $o->args = [$o->path]; }
        $c = self::c();
        $noctty = self::mac() ? 0x20000 : 0x100;
        $fd = $c->posix_openpt(2 | $noctty); // O_RDWR
        if ($fd < 0 || $c->grantpt($fd) !== 0 || $c->unlockpt($fd) !== 0) {
            if ($fd >= 0) { $c->close($fd); }
            throw new RuntimeException('pty: cannot open a pseudo-terminal');
        }
        $c->fcntl($fd, 2, 1); // F_SETFD, FD_CLOEXEC: no child keeps the master
        $name = FFI::string($c->ptsname($fd));
        $slave = $c->open($name, 2 | $noctty);
        if ($slave < 0) {
            $c->close($fd);
            throw new RuntimeException("pty: opening $name");
        }
        self::setSize($fd, $o->cols, $o->rows, $o->width, $o->height);
        $s = fopen("php://fd/$slave", 'r+');
        $arg = base64_encode(json_encode(['path' => $o->path, 'args' => $o->args, 'dir' => $o->dir, 'env' => $o->env], JSON_THROW_ON_ERROR));
        // A static app (phpmicro) runs its own stub whatever it is given, which runs spawn.php on --myphp-pty-spawn (bin/myphp's micro launcher).
        $argv = PHP_SAPI === 'micro' ? [PHP_BINARY, '--myphp-pty-spawn', $arg] : [PHP_BINARY, '-d', 'ffi.enable=1', __DIR__ . '/spawn.php', $arg];
        $proc = proc_open($argv, [$s, $s, $s], $pipes);
        fclose($s);
        $c->close($slave); // the child has its own copies
        if ($proc === false) {
            $c->close($fd);
            throw new RuntimeException("pty: cannot start {$o->path}");
        }
        $p = new self;
        $p->fd = $fd;
        $p->master = fopen("php://fd/$fd", 'r+');
        stream_set_blocking($p->master, false);
        $p->proc = $proc;
        $p->pid = proc_get_status($proc)['pid'];
        return $p;
    }

    /** What the process wrote that is there to read: '' for nothing yet, null once no process has the terminal open any more, or after close(). */
    public function read(int $max = 65536): ?string
    {
        if ($this->closed) { return null; }
        $s = @fread($this->master, $max);
        if ($s === false || ($s === '' && feof($this->master))) {
            return null; // Linux reports EIO when the last process with the terminal open closes it
        }
        return $s;
    }

    /** Types $b into the terminal, as much as it takes now; returns how many bytes it took. */
    public function write(string $b): int
    {
        if ($this->closed) { throw new RuntimeException('pty: closed'); }
        $n = @fwrite($this->master, $b);
        return $n === false ? 0 : $n;
    }

    /** Sets the size of the terminal, which sends the process SIGWINCH. */
    public function resize(int $cols, int $rows, int $width, int $height): void
    {
        if (!$this->closed) { self::setSize($this->fd, $cols, $rows, $width, $height); }
    }

    private static function setSize(int $fd, int $cols, int $rows, int $width, int $height): void
    {
        $c = self::c();
        $ws = $c->new('winsize');
        $clamp = fn(int $v): int => max(0, min($v, 0xFFFF));
        $ws->row = $clamp($rows); $ws->col = $clamp($cols); $ws->xpixel = $clamp($width); $ws->ypixel = $clamp($height);
        $c->ioctl($fd, self::mac() ? 0x80087467 : 0x5414, FFI::addr($ws)); // TIOCSWINSZ
    }

    /** The process's ID. */
    public function pid(): int { return $this->pid; }

    /** The exit code once the process exited, 128 plus the signal's number for one a signal killed; null while it runs. */
    public function exited(): ?int
    {
        if ($this->code === null) {
            $st = proc_get_status($this->proc);
            if (!$st['running']) {
                $this->code = $st['signaled'] ? 128 + $st['termsig'] : $st['exitcode'];
            }
        }
        return $this->code;
    }

    /** Closes the terminal, which hangs up the processes that have it: they get SIGHUP. It does not wait for them to exit. */
    public function close(): void
    {
        if ($this->closed) { return; }
        $this->closed = true;
        fclose($this->master);
        self::c()->close($this->fd);
    }

    /** Kills the process and those of its process group, which it leads. */
    public function kill(): void
    {
        if (self::c()->kill(-$this->pid, 9) !== 0) { self::c()->kill($this->pid, 9); }
    }
}
