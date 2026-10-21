<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

use MyPHP\Plugins\Terminal\Vt\{ClipboardRead, ClipboardReply, KeyEncoder, Terminal as VtTerminal};
use MyPHP\Transfer\{Data, Format};

/**
 * Pasting (mygo's paste.go): bracketed when the program asks, or offered
 * to a program that reads the clipboard itself (mode 5522).
 *
 * @internal part of Terminal
 */
trait TerminalPaste
{
    /** How long after a paste's offer is written onPasteStatus tells that the program did not read it: long enough for a program far away, over SSH, to read it. @internal */
    public static float $notTakenAfter = 5.0;
    /** What the last paste offered a program that reads the clipboard itself, until it reads it. */
    private ?PasteOffer $offer = null;
    /** Tells that what writePTY gets next is the reply to the program's read, which goes in pieces that report their progress. */
    private bool $replying = false;

    /**
     * Pastes text, as pasteData() does: bracketed when the program asks
     * (mode 2004), so that it does not run as commands as it would typed,
     * or offered to a program that reads the clipboard itself (mode 5522),
     * which reads it as text/plain.
     */
    public function paste(string $text): void
    {
        if ($text !== '') { $this->pasteData(Data::text($text)); }
    }

    /**
     * Pastes data, as the paste keys paste the clipboard. A program that
     * reads the clipboard itself (mode 5522, the Kitty clipboard protocol,
     * as programs that take images do) is offered $d's formats as MIME
     * types (a file list as text/uri-list) and reads those it wants, even
     * over SSH; any other gets $d's text, bracketed when it asks (mode
     * 2004), and nothing when there is none. The terminal keeps a snapshot
     * of $d until the program reads it or another paste comes, and answers
     * only that read.
     */
    public function pasteData(Data $d): void { $this->pasteHeld($d, null); }

    /** @internal pastes $d; $held are the formats the clipboard held when the view read only some of them, which tell whether a paste of no text had something else @param ?list<string> $held */
    public function pasteHeld(Data $d, ?array $held): void
    {
        $o = new PasteOffer($d->snapshot());
        $held ??= $o->data->formats();
        if ($this->term === null) {
            return;
        }
        $data = '';
        $status = null;
        if ($o->mimes !== [] && $this->term->pasteEvent($o->mimes)) {
            // The event went to the input queue; the offer waits for the program's read.
            $this->offer = $o;
            $status = PasteStatus::PasteOffered;
        } else {
            try {
                $text = $o->data->read(Format::TEXT);
            } catch (\Throwable) {
                $text = '';
            }
            if ($text === '' && array_filter($held, fn(string $f) => $f !== Format::TEXT) !== []) {
                $status = PasteStatus::PasteNoText;
            }
            $data = KeyEncoder::encodePaste($text, $this->term->mode(VtTerminal::MODE_BRACKETED_PASTE));
        }
        $this->term->scrollToBottom();
        $this->send($data);
        $this->redraw();
        if ($status === null) {
            return;
        }
        // Once what the paste sent is written.
        $this->push('', function () use ($status, $o): void {
            $this->pasteStatus(new PasteProgress($status));
            if ($status !== PasteStatus::PasteOffered) {
                return;
            }
            self::schedule(self::$notTakenAfter, function () use ($o): void {
                if ($this->offer === $o) { $this->pasteStatus(new PasteProgress(PasteStatus::PasteNotTaken)); }
            });
        });
    }

    private function pasteStatus(PasteProgress $p): void
    {
        if ($this->opts->onPasteStatus !== null) { ($this->opts->onPasteStatus)($p); }
    }

    /** Answers a program's read of the clipboard: only what a paste offered, once; any other read goes unanswered, as other windows of the same program may be the ones to answer. */
    private function clipboardRead(ClipboardRead $req): ?ClipboardReply
    {
        $o = $this->offer;
        if (!$req->granted || $o === null) {
            return null;
        }
        $this->offer = null;
        $reply = new ClipboardReply;
        foreach ($req->mimes as $mime) {
            if (($b = $o->read($mime)) !== '') { $reply->contents[$mime] = $b; }
        }
        if ($req->list) { $reply->available = $o->mimes; }
        // The reply, encoded, comes to writePTY as this returns.
        $this->replying = $reply->contents !== [];
        return $reply;
    }

    /** Queues a paste's reply in pieces, each telling how far it went once written, which a slow link makes worth watching. */
    private function sendReply(string $p): void
    {
        $total = strlen($p);
        $this->push('', function () use ($total): void { $this->pasteStatus(new PasteProgress(PasteStatus::PasteSending, 0, $total)); });
        $sent = 0;
        while ($p !== '') {
            $piece = substr($p, 0, 1 << 16);
            $p = (string)substr($p, strlen($piece));
            $sent += strlen($piece);
            $status = $p === '' ? PasteStatus::PasteSent : PasteStatus::PasteSending;
            $done = $sent;
            $this->push($piece, function () use ($status, $done, $total): void { $this->pasteStatus(new PasteProgress($status, $done, $total)); });
        }
    }

    /** @internal whether the program reads the clipboard itself (mode 5522), as a paste then offers it all the clipboard holds */
    public function readsClipboard(): bool
    {
        return $this->term !== null && $this->term->mode(VtTerminal::MODE_PASTE_EVENTS);
    }
}
