<?php
// URLSession's progress, as Foundation's: the `delegate:` of upload(for:from:delegate:), download(for:delegate:) and
// data(for:delegate:), a URLSessionTaskDelegate with didSendBodyData, didWriteData and didReceive, a task with counts, a
// Progress and cancel(); an ended transfer throws URLError (cancelled). Against a local server (testdata/range_server.php).
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\{Progress, URLError, URLRequest, URLSession, URLSessionTask, URLSessionTaskDelegate};

try {
    \MyPHP\Native\Curl::ffi();
} catch (\Throwable $e) {
    echo "  skipped: url_session_progress: {$e->getMessage()}\n";
    return;
}

// --- Progress and the task, without a network
$p = new Progress(200, 50);
same(0.25, $p->fractionCompleted, 'a Progress: the fraction done');
same(0.0, (new Progress())->fractionCompleted, 'with no total: 0');
same(1.0, (new Progress(10, 99))->fractionCompleted, 'never over 1');
check(!$p->isCancelled, 'not cancelled');
$p->cancel();
check($p->isCancelled, 'cancelled');
$t = new URLSessionTask(new URLRequest('http://example.com/'));
$t->update(1000, 400, 50, 20);
same([1000, 400, 50, 20], [$t->countOfBytesExpectedToSend, $t->countOfBytesSent, $t->countOfBytesExpectedToReceive, $t->countOfBytesReceived], 'a task keeps the counts of what has gone and come');
same([1050, 420], [$t->progress->totalUnitCount, $t->progress->completedUnitCount], 'and its Progress is the two together');
$t->cancel();
check($t->progress->isCancelled, 'cancel() cancels its Progress');
$e = URLError::cancelled();
same([-999, true, true], [$e->getCode(), $e->isCancelled(), $e instanceof \RuntimeException], 'URLError.cancelled is -999');

// --- a server
$dir = sys_get_temp_dir() . '/myphp-urlsession-progress-' . getmypid();
@mkdir($dir);
$big = 6 * 1024 * 1024;
file_put_contents("$dir/big.bin", str_repeat('0123456789abcdef', $big / 16));
file_put_contents("$dir/small.txt", 'hello');
[$base, $stop] = rangeServer($dir);
$slowPort = freePort();
$slow = proc_open([PHP_BINARY, __DIR__ . '/testdata/slow_upload_server.php', (string)$slowPort], [['file', DEVNULL, 'r'], ['file', DEVNULL, 'w'], ['file', DEVNULL, 'w']], $slowPipes);
for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $slowPort) === false; $i++) { usleep(20000); }
$slowBase = "http://127.0.0.1:$slowPort";
$collector = new class extends URLSessionTaskDelegate {
    /** @var list<array{int, int, int}> */
    public array $sent = [];
    /** @var list<array{int, int, int}> */
    public array $written = [];
    public string $data = '';
    public ?\Closure $on = null;
    public function didSendBodyData(\MyPHP\URLSession $session, URLSessionTask $task, int $bytesSent, int $totalBytesSent, int $totalBytesExpectedToSend): void
    {
        $this->sent[] = [$bytesSent, $totalBytesSent, $totalBytesExpectedToSend];
        if ($this->on) { ($this->on)($task, $totalBytesSent, $totalBytesExpectedToSend); }
    }
    public function didWriteData(\MyPHP\URLSession $session, URLSessionTask $downloadTask, int $bytesWritten, int $totalBytesWritten, int $totalBytesExpectedToWrite): void
    {
        $this->written[] = [$bytesWritten, $totalBytesWritten, $totalBytesExpectedToWrite];
        if ($this->on) { ($this->on)($downloadTask, $totalBytesWritten, $totalBytesExpectedToWrite); }
    }
    public function didReceive(\MyPHP\URLSession $session, URLSessionTask $dataTask, string $data): void
    {
        $this->data .= $data;
    }
};
$session = new URLSession();
$fresh = fn() => clone $collector;
try {
    // upload: told as it goes, ending at the whole
    $d = $fresh();
    $body = str_repeat('x', 2 * 1024 * 1024);
    $req = new URLRequest("$slowBase/up", 'POST');
    [$answer, $response] = $session->upload(for: $req, from: $body, delegate: $d);
    same(200, $response->statusCode, 'an upload with a delegate: the answer comes');
    check(count($d->sent) >= 2, 'the delegate is told more than once: ' . count($d->sent) . ' times');
    $last = $d->sent[array_key_last($d->sent)];
    same([strlen($body), strlen($body)], [$last[1], $last[2]], 'the last report is the whole body');
    $monotonic = true;
    $sum = 0;
    foreach ($d->sent as [$delta, $total]) { $sum += $delta; $monotonic = $monotonic && $total === $sum && $delta > 0; }
    check($monotonic && $sum === strlen($body), 'each report adds what was sent since the last, which sum to the body');
    check(count($d->sent) <= 4 * 30, 'throttled: a call a 30th of a second at most: ' . count($d->sent));

    // an upload from a file
    $file = "$dir/upload.bin";
    file_put_contents($file, str_repeat('y', 1024 * 1024));
    $d = $fresh();
    [, $response] = $session->upload(for: new URLRequest("$slowBase/up", 'POST'), fromFile: $file, delegate: $d);
    same([200, 1024 * 1024], [$response->statusCode, ($d->sent[array_key_last($d->sent)] ?? [0, 0])[1]], 'an upload of a file is told the same');
    try {
        $session->upload(for: new URLRequest("$base/small.txt", 'POST'), fromFile: "$dir/none.bin");
        check(false, 'a file that is not there throws');
    } catch (\RuntimeException $e) {
        check(str_contains($e->getMessage(), 'cannot read'), 'a file that is not there throws: ' . $e->getMessage());
    }

    // cancel an upload from the delegate
    $d = $fresh();
    $d->on = function (URLSessionTask $task, int $sent, int $total) { if ($sent > 0) { $task->cancel(); } };
    try {
        $session->upload(for: new URLRequest("$slowBase/up", 'POST'), from: str_repeat('z', 4 * 1024 * 1024), delegate: $d);
        check(false, 'a cancelled upload throws');
    } catch (URLError $e) {
        check($e->isCancelled(), 'a cancelled upload throws URLError (cancelled)');
    }

    // download: written to a file as it comes
    $d = $fresh();
    [$tmp, $response] = $session->download(from: "$slowBase/download/" . (2 * 1024 * 1024), delegate: $d);
    $big = 2 * 1024 * 1024;
    same([200, $big], [$response->statusCode, filesize($tmp)], 'a download is the whole file');
    same(md5(str_repeat('d', $big)), md5_file($tmp), 'and its bytes');
    check(count($d->written) >= 2, 'the delegate is told more than once: ' . count($d->written) . ' times');
    $lastW = $d->written[array_key_last($d->written)];
    same([$big, $big], [$lastW[1], $lastW[2]], 'the last report is the whole file, of the length the server said');
    unlink($tmp);

    // cancel a download: no file is left
    $files = glob(sys_get_temp_dir() . '/myphp-dl-*') ?: [];
    $d = $fresh();
    $d->on = function (URLSessionTask $task, int $written, int $total) { if ($written > 100000) { $task->cancel(); } };
    try {
        $session->download(from: "$slowBase/download/" . (4 * 1024 * 1024), delegate: $d);
        check(false, 'a cancelled download throws');
    } catch (URLError $e) {
        check($e->isCancelled(), 'a cancelled download throws URLError (cancelled)');
    }
    same(count($files), count(glob(sys_get_temp_dir() . '/myphp-dl-*') ?: []), 'and leaves no file');

    // a download with no delegate is as before
    [$tmp, $response] = $session->download(from: "$base/small.txt");
    same(['hello', 200], [file_get_contents($tmp), $response->statusCode], 'a download with no delegate');
    unlink($tmp);

    // data: each piece
    $d = $fresh();
    [$data] = $session->data(from: "$base/big.bin", delegate: $d);
    same([filesize("$dir/big.bin"), md5_file("$dir/big.bin")], [strlen($data), md5($data)], 'a data task with a delegate still returns the data');
    same(md5($data), md5($d->data), 'and the delegate had each piece of it');

    // what a delegate throws ends the transfer and is thrown
    $d = $fresh();
    $d->on = function () { throw new \LogicException('stop here'); };
    try {
        $session->download(from: "$slowBase/download/" . (1024 * 1024), delegate: $d);
        check(false, 'what the delegate throws is thrown');
    } catch (\LogicException $e) {
        same('stop here', $e->getMessage(), 'what the delegate throws is thrown by the call');
    }
} finally {
    $stop();
    if (is_resource($slow)) { proc_terminate($slow, 9); proc_close($slow); }
    foreach (glob("$dir/*") ?: [] as $f) { @unlink($f); }
    @rmdir($dir);
}
