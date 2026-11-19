<?php
declare(strict_types=1);

namespace MyPHP;

/**
 * The delegate of a URLSession call (`delegate:`), as Foundation's URLSessionTaskDelegate with its Data and Download
 * kinds, whose methods an app overrides, as Swift's optional ones: each does nothing here. The names are the first label of the
 * Swift method (`urlSession(_:task:didSendBodyData:totalBytesSent:totalBytesExpectedToSend:)` is `didSendBodyData`).
 * They run on the main thread, in the run loop's turn, not in the fiber that waits for the call: a method may change the
 * app's state and ask for a frame. Throttled to 30 calls a second (the last always comes). `$task->cancel()` ends the
 * transfer. What a method throws ends it, and the call throws it.
 *
 *     final class Uploads extends URLSessionTaskDelegate {
 *         public function __construct(private Upload $state) {}
 *         public function didSendBodyData(URLSession $session, URLSessionTask $task, int $bytesSent, int $totalBytesSent, int $totalBytesExpectedToSend): void {
 *             $this->state->fraction = $task->progress->fractionCompleted;   // ProgressView('Uploading', value: $this->fraction)
 *         }
 *     }
 *     [$body, $response] = URLSession::shared()->upload(for: $request, fromFile: $path, delegate: new Uploads($state));
 */
abstract class URLSessionTaskDelegate
{
    /** The body is going out: $bytesSent since the last call, $totalBytesSent so far, $totalBytesExpectedToSend in all. */
    public function didSendBodyData(URLSession $session, URLSessionTask $task, int $bytesSent, int $totalBytesSent, int $totalBytesExpectedToSend): void
    {
    }

    /** A download's file is being written (URLSessionDownloadDelegate): $totalBytesExpectedToWrite is 0 when the server did not say. */
    public function didWriteData(URLSession $session, URLSessionTask $downloadTask, int $bytesWritten, int $totalBytesWritten, int $totalBytesExpectedToWrite): void
    {
    }

    /** A data task's piece of the body has come (URLSessionDataDelegate), each piece, not throttled. */
    public function didReceive(URLSession $session, URLSessionTask $dataTask, string $data): void
    {
    }
}
