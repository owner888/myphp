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
 * away or closing the window closes it too.
 *
 * A generator waits while the page has more than a MiB of values yet to
 * take (mygo's flow control), so a fast producer does not pile them up in
 * the page's memory; send() itself never waits, as the main thread must
 * not (mygo's Send on the main thread doesn't either).
 */
final class Channel
{
    /** How many bytes of values may wait for the page to take them (mygo's channelWindow). The page acknowledges what it took when a value asks for it, once every half window. */
    public const WINDOW = 1 << 20;

    private bool $closed = false;
    /** Flow control, in bytes: sent, taken by the page as far as it said, and sent when an acknowledgment was last asked for. */
    private int $seq = 0, $sent = 0, $taken = 0, $asked = 0;
    /** @var list<array{0: int, 1: int}> the acknowledgments asked for: [seq, sent] */
    private array $asks = [];
    /** What runs once there is room again, or the channel closed. */
    private ?\Closure $whenRoom = null;

    /**
     * @internal Window::openChannel() makes it
     * @param \Closure(array<string, mixed>): void $deliver
     * @param \Closure(): void $forget
     */
    public function __construct(private readonly \Closure $deliver, private readonly \Closure $forget, public readonly int $id, private readonly string $secret) {}

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
        $m = ['t' => 'chan', 'c' => $this->id, 'k' => $this->secret, 'p' => $value];
        $this->seq++;
        $this->sent += strlen(json_encode($m, JSON_THROW_ON_ERROR)); // the whole message
        if ($this->sent - $this->asked >= self::WINDOW / 2) {
            $this->asked = $this->sent;
            $this->asks[] = [$this->seq, $this->sent];
            $m['a'] = 1;
        }
        ($this->deliver)($m);
    }

    /** Closes the channel: the page's iteration ends once it took what was sent. Called when the method returns. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        ($this->deliver)(['t' => 'chan', 'c' => $this->id, 'k' => $this->secret, 'end' => true]);
        ($this->forget)();
        $this->room();
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** @internal whether the page is a window behind, so that a generator waits */
    public function full(): bool
    {
        return !$this->closed && $this->sent - $this->taken >= self::WINDOW;
    }

    /** @internal runs $fn once the channel is no longer full(), at once if it isn't */
    public function whenRoom(\Closure $fn): void
    {
        if (!$this->full()) {
            $fn();
            return;
        }
        $this->whenRoom = $fn;
    }

    /** @internal the page took the values up to the $seq-th (its chan-ack) */
    public function ack(int $seq): void
    {
        while ($this->asks !== [] && $this->asks[0][0] <= $seq) {
            $this->taken = array_shift($this->asks)[1];
        }
        $this->room();
    }

    /** @internal the page closed it, or went away: nothing more is sent, and the next send() throws */
    public function closedByPage(): void
    {
        $this->closed = true;
        ($this->forget)();
        $this->room();
    }

    private function room(): void
    {
        if ($this->whenRoom !== null && !$this->full()) {
            $fn = $this->whenRoom;
            $this->whenRoom = null;
            $fn();
        }
    }
}
