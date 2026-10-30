<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal\Vt;

/** Answers a ClipboardRead (mygo's vt.ClipboardReply): contents by MIME type, and the types available when the list was asked for. Without contents the read is refused. A read answered later carries $deferred instead (Terminal::answerClipboard). @internal */
final class ClipboardReply
{
    /** @param array<string, string> $contents @param list<string> $available */
    public function __construct(public array $contents = [], public array $available = [], public ?DeferredClipboardRead $deferred = null) {}
}
