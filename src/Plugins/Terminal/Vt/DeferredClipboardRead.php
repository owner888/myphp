<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal\Vt;

/**
 * A read of the clipboard answered after its effect returned (mygo's
 * vt.DeferredClipboardRead): the binding fills it as the library writes the
 * refusal it holds back. $kitty tells that the read came by the Kitty
 * clipboard protocol (OSC 5522) rather than OSC 52; for OSC 52, $prefix is
 * the reply's beginning ("\e]52;" with the selector and its ";") and
 * $terminator how the request ended; $refusal is what the library wrote to
 * refuse the read, which a denial writes again; $mimes, $list and $primary
 * are what the program asked for, and $id the id of a Kitty protocol read,
 * echoed in its answer. Terminal::answerClipboard answers it.
 *
 * @internal
 */
final class DeferredClipboardRead
{
    public bool $kitty = false;
    public string $prefix = '', $terminator = '', $refusal = '', $id = '';
    /** @var list<string> */
    public array $mimes = [];
    public bool $list = false, $primary = false;

    /** How many raw bytes of one MIME type a Kitty protocol DATA packet carries. */
    private const CHUNK = 4096;

    /** Takes $p, what the library wrote to refuse the read, and keeps it for a denial; whether it was a refusal (mygo's setRefusal). */
    public function setRefusal(string $p): bool
    {
        if (str_starts_with($p, "\x1b]52;")) { // "\e]52;<selector>;<terminator>": the payload is empty
            $first = strpos($p, ';');
            $second = $first === false ? false : strpos($p, ';', $first + 1);
            if ($second === false) {
                return false;
            }
            [$this->kitty, $this->prefix, $this->terminator, $this->refusal] = [false, substr($p, 0, $second + 1), substr($p, $second + 1), $p];
            return true;
        }
        if (str_starts_with($p, "\x1b]5522;type=read:status=")) {
            $this->kitty = true;
            $this->refusal = $p;
            $this->terminator = str_ends_with($p, "\x1b\\") ? "\x1b\\" : (str_ends_with($p, "\x07") ? "\x07" : "\x1b\\");
            $this->id = '';
            if (($i = strpos($p, ':id=')) !== false) {
                $rest = substr($p, $i + 4);
                $this->id = substr($rest, 0, strcspn($rest, ":\x1b\x07"));
            }
            if (str_contains($p, ':loc=primary')) { $this->primary = true; }
            return true;
        }
        return false;
    }

    /** The read's answer: $contents by MIME type, and $available for the types listing (mygo's AnswerClipboard), as the library encodes one. @param array<string, string> $contents @param list<string> $available */
    public function answer(array $contents, array $available): string
    {
        if (!$this->kitty) {
            return $this->prefix . base64_encode($contents['text/plain'] ?? '') . $this->terminator;
        }
        // As ReadSuccess.encode in Ghostty's response.zig: an OK packet, the listing when asked for, the representations in the order asked as DATA packets, DONE; loc only on OK, no password.
        $packet = fn(string $status, bool $loc): string => "\x1b]5522;type=read:status=$status" . ($loc && $this->primary ? ':loc=primary' : '') . ($this->id !== '' ? ":id={$this->id}" : '');
        $out = $packet('OK', true) . $this->terminator;
        if ($this->list) {
            $out .= $packet('DATA', false) . ':mime=' . base64_encode('.');
            if ($available !== []) { // the types joined by spaces with a newline, whole types only
                $raw = '';
                foreach ($available as $i => $mime) {
                    if (strlen($raw) + ($i > 0 ? 1 : 0) + strlen($mime) + 1 > self::CHUNK) { break; }
                    $raw .= ($i > 0 ? ' ' : '') . $mime;
                }
                $out .= ';' . base64_encode($raw . "\n");
            }
            $out .= $this->terminator;
        }
        foreach ($this->mimes as $mime) {
            $data = $contents[$mime] ?? '';
            for ($i = 0; $i < strlen($data); $i += self::CHUNK) {
                $out .= $packet('DATA', false) . ':mime=' . base64_encode($mime) . ';' . base64_encode(substr($data, $i, self::CHUNK)) . $this->terminator;
            }
        }
        return $out . $packet('DONE', false) . $this->terminator;
    }
}
