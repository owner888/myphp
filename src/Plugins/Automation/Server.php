<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Automation;

use Closure;
use RuntimeException;

/**
 * The automation plugin's socket (mygo's listen and serve): a Unix domain
 * socket only its user reads and writes (mode 0600), polled on the run
 * loop as SingleInstance's is. Each connection's requests are answered
 * in order, each once the one before it is; one waiting (idle) holds its
 * connection's, not the others'.
 *
 * @internal
 */
final class Server
{
    /** @var list<array{conn: ?resource, in: string, lines: list<string>, waiting: ?Closure, out: string, eof: bool, tooLong: bool}> the connections: what came of a line, the lines to answer, the answer waited for, what to write */
    private array $conns = [];

    /**
     * @param resource $server
     */
    private function __construct(private $server, public readonly string $path, private readonly ?int $inode) {}

    /**
     * Listens at $path. A socket left there by an app that ended without
     * removing it is replaced; one another app listens on is not, nor a
     * file that is no socket.
     */
    public static function listen(string $path): self
    {
        clearstatcache(true, $path);
        if (($st = @lstat($path)) !== false) {
            if (($st['mode'] & 0o170000) !== 0o140000) {
                throw new RuntimeException("$path exists and is not a socket");
            }
            if (($c = @stream_socket_client("unix://$path", $errno, $err, 1.0)) !== false) {
                fclose($c);
                throw new RuntimeException("another app listens on $path");
            }
            if (!@unlink($path)) {
                throw new RuntimeException("cannot remove the socket left at $path");
            }
        }
        $old = umask(0o177); // the socket is the user's alone from the start, not once chmod comes
        $server = @stream_socket_server("unix://$path", $errno, $err);
        umask($old);
        if ($server === false) {
            throw new RuntimeException("cannot listen on $path: $err");
        }
        if (!@chmod($path, 0o600)) {
            fclose($server);
            throw new RuntimeException("cannot make $path the user's alone");
        }
        stream_set_blocking($server, false);
        clearstatcache(true, $path);
        $st = @stat($path);
        return new self($server, $path, $st === false ? null : $st['ino']);
    }

    /** Takes the connections and the requests that came, and answers what it can; false once closed. */
    public function poll(Driver $d): bool
    {
        if ($this->server === null) {
            return false;
        }
        while (($c = @stream_socket_accept($this->server, 0)) !== false) {
            stream_set_blocking($c, false);
            $this->conns[] = ['conn' => $c, 'in' => '', 'lines' => [], 'waiting' => null, 'out' => '', 'eof' => false, 'tooLong' => false];
        }
        foreach ($this->conns as $i => &$c) {
            $this->read($c);
            $this->answer($c, $d);
            $this->write($c);
            if ($c['eof'] && $c['lines'] === [] && $c['waiting'] === null && $c['out'] === '' || $c['conn'] === null) {
                if ($c['conn'] !== null) { fclose($c['conn']); }
                unset($this->conns[$i]);
            }
        }
        unset($c);
        $this->conns = array_values($this->conns);
        return true;
    }

    /** Reads what came on a connection into its lines. */
    private function read(array &$c): void
    {
        while (!$c['eof']) {
            $b = @fread($c['conn'], 1 << 16);
            if ($b === false || $b === '') {
                if (feof($c['conn'])) { $c['eof'] = true; }
                break;
            }
            $c['in'] .= $b;
            while (($nl = strpos($c['in'], "\n")) !== false) {
                $c['lines'][] = substr($c['in'], 0, $nl);
                $c['in'] = substr($c['in'], $nl + 1);
            }
            if (strlen($c['in']) > Protocol::MAX_LINE) {
                // The answers to the lines before it, then this one's, and the connection ends.
                $c['tooLong'] = true;
                $c['in'] = '';
                $c['eof'] = true;
            }
        }
        if ($c['eof'] && $c['in'] !== '') {
            $c['lines'][] = $c['in']; // the last line, without its newline
            $c['in'] = '';
        }
    }

    /** Answers a connection's requests, in order, up to one that waits. */
    private function answer(array &$c, Driver $d): void
    {
        while (true) {
            if ($c['waiting'] !== null) {
                $r = ($c['waiting'])();
                if ($r === null) {
                    return;
                }
                $c['waiting'] = null;
                $c['out'] .= Protocol::encode($r);
            }
            if ($c['lines'] === []) {
                if ($c['tooLong']) {
                    $c['out'] .= Protocol::encode(Protocol::error(null, new Failure(Failure::PARSE, 'a request is longer than ' . Protocol::MAX_LINE . ' bytes')));
                    $c['tooLong'] = false;
                }
                return;
            }
            $line = trim(array_shift($c['lines']));
            if ($line === '') {
                continue;
            }
            $r = Protocol::handle($d, $line);
            if ($r instanceof Closure) {
                $c['waiting'] = $r;
            } else {
                $c['out'] .= Protocol::encode($r);
            }
        }
    }

    /** Writes what a connection was answered, as much as it takes now. */
    private function write(array &$c): void
    {
        while ($c['out'] !== '') {
            $n = @fwrite($c['conn'], $c['out']);
            if ($n === false) { // the client went: nothing more to answer it
                fclose($c['conn']);
                $c['conn'] = null;
                return;
            }
            if ($n === 0) {
                return;
            }
            $c['out'] = (string)substr($c['out'], $n);
        }
    }

    /** Stops listening, and removes the socket if it is still this one. */
    public function close(): void
    {
        if ($this->server === null) {
            return;
        }
        foreach ($this->conns as $c) {
            if ($c['conn'] !== null) { fclose($c['conn']); }
        }
        $this->conns = [];
        fclose($this->server);
        $this->server = null;
        clearstatcache(true, $this->path);
        $st = @stat($this->path);
        if ($st !== false && $st['ino'] === $this->inode) {
            @unlink($this->path);
        }
    }
}
