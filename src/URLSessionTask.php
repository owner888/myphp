<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * The transfer a URLSession call makes, as Foundation's URLSessionTask: what its delegate is given, with the bytes it has
 * sent and received and expects to, its Progress, and cancel(). The `await` of Swift is the call that waits (in a fiber,
 * `App::go`), so the task is only seen by the delegate.
 */
final class URLSessionTask
{
    public int $countOfBytesSent = 0;
    public int $countOfBytesExpectedToSend = 0;
    public int $countOfBytesReceived = 0;
    public int $countOfBytesExpectedToReceive = 0;
    /** The overall progress: the bytes sent and received over those expected to go and to come. */
    public readonly Progress $progress;

    public function __construct(public readonly URLRequest $originalRequest)
    {
        $this->progress = new Progress();
    }

    /** Ends the transfer at its next report; the call that waits for it throws URLError (cancelled). */
    public function cancel(): void
    {
        $this->progress->cancel();
    }

    /** Updates the counts and the Progress from what curl reports. @internal URLSession */
    public function update(int $uploadTotal, int $uploadNow, int $downloadTotal, int $downloadNow): void
    {
        [$this->countOfBytesExpectedToSend, $this->countOfBytesSent] = [$uploadTotal, $uploadNow];
        [$this->countOfBytesExpectedToReceive, $this->countOfBytesReceived] = [$downloadTotal, $downloadNow];
        $this->progress->totalUnitCount = $uploadTotal + $downloadTotal;
        $this->progress->completedUnitCount = $uploadNow + $downloadNow;
    }
}
