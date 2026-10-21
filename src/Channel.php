<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * Streams values from a bound method to the page that called it, as
 * mygo's Channel: the lines a command prints, the tokens of an answer.
 * A method takes it as a parameter and the page passes a `myphp.Channel`
 * in its place, iterating it or handling values with onmessage:
 *
 *     // PHP: values go out as sent; the channel closes when the method returns.
 *     public function tail(string $file, Channel $lines): void { ... $lines->send($line); ... }
 *
 *     // or a generator: each yield is sent, and the run loop turns between
 *     // yields, so the window stays responsive while the method works.
 *     public function count(int $n, Channel $out): \Generator { for ($i = 0; $i < $n; $i++) { yield $i; } }
 *
 *     // page
 *     const lines = new myphp.Channel();
 *     const done = myphp.call("Shell.tail", "/var/log/system.log", lines);
 *     for await (const line of lines) output.append(line + "\n");
 *     await done;
 *
 * A generator that waits for something, as the network, yields
 * Channel::wait($seconds): nothing is sent, and it goes on that much later,
 * the run loop turning meanwhile (mygo's methods block on a goroutine).
 *
 * Values arrive in order, before the call's result. The page may close
 * the channel (breaking out of the loop does): send() then throws
 * ChannelClosed, and a generator gets it thrown at its yield. Navigating
 * away or closing the window closes it too. There is no back-pressure:
 * calls run on the main thread, which must not wait for the page.
 */
final class Channel
{
    private bool $closed = false;

    public function __construct(private readonly Window $window, public readonly int $id, private readonly string $secret) {}

    /** What a streaming generator yields to send nothing now and go on $seconds later. */
    public static function wait(float $seconds): ChannelWait
    {
        return new ChannelWait(max(0.0, $seconds));
    }

    /** Sends $value to the page. */
    public function send(mixed $value): void
    {
        if ($this->closed) {
            throw new ChannelClosed("channel {$this->id} is closed");
        }
        $this->window->page()->deliver(['t' => 'chan', 'c' => $this->id, 'k' => $this->secret, 'p' => $value]);
    }

    /** Closes the channel: the page's iteration ends once it took what was sent. Called when the method returns. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->window->page()?->deliver(['t' => 'chan', 'c' => $this->id, 'k' => $this->secret, 'end' => true]);
        $this->window->forgetChannel($this->id);
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** The page closed it, or went away: nothing more is sent, and the next send() throws. */
    public function closedByPage(): void
    {
        $this->closed = true;
        $this->window->forgetChannel($this->id);
    }
}
