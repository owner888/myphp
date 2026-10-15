<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;
use RuntimeException;

/**
 * A child process run without blocking the UI: its output is read as it
 * comes, polled on the run loop (App::after), and $done gets the whole of
 * stdout and stderr with the exit code once it ends. PHP has no threads
 * here, so this is how an app does network and other slow work: curl,
 * ffmpeg, git, a script.
 */
final class Process
{
    private const POLL = 0.016;
    /** @var resource */
    private $proc;
    /** @var array{0: resource, 1: resource, 2: resource} */
    private array $pipes;
    private string $stdin, $out = '', $err = '';
    private bool $ended = false;

    /** @param list<string> $argv the program and its arguments, not a shell line */
    public static function run(array $argv, string $stdin, Closure $done): self
    {
        return new self($argv, $stdin, $done);
    }

    private function __construct(array $argv, string $stdin, private Closure $done)
    {
        $proc = proc_open($argv, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if ($proc === false) {
            throw new RuntimeException('cannot start ' . $argv[0]);
        }
        $this->proc = $proc; $this->pipes = $pipes; $this->stdin = $stdin;
        foreach ($pipes as $p) {
            stream_set_blocking($p, false);
        }
        $this->poll();
    }

    /** Whether the process has ended and $done was called. */
    public function ended(): bool
    {
        return $this->ended;
    }

    /** Ends the process now; $done is not called. */
    public function kill(): void
    {
        if (!$this->ended) {
            $this->ended = true;
            proc_terminate($this->proc);
            $this->close();
        }
    }

    private function poll(): void
    {
        if ($this->ended) {
            return;
        }
        if ($this->stdin !== '') {
            $n = fwrite($this->pipes[0], $this->stdin);
            $this->stdin = $n === false ? '' : substr($this->stdin, $n);
        }
        if ($this->stdin === '' && is_resource($this->pipes[0])) {
            fclose($this->pipes[0]);
        }
        $this->out .= stream_get_contents($this->pipes[1]);
        $this->err .= stream_get_contents($this->pipes[2]);
        $status = proc_get_status($this->proc);
        if ($status['running']) {
            App::after(self::POLL, $this->poll(...));
            return;
        }
        $this->out .= stream_get_contents($this->pipes[1]);
        $this->err .= stream_get_contents($this->pipes[2]);
        $this->ended = true;
        $this->close();
        ($this->done)($this->out, $this->err, $status['exitcode']);
    }

    private function close(): void
    {
        foreach ($this->pipes as $p) {
            if (is_resource($p)) {
                fclose($p);
            }
        }
        proc_close($this->proc);
    }
}
