<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

use MyPHP\Plugins\Terminal\Vt\DeferredClipboardRead;

/**
 * A program's read of the clipboard the app is asked about
 * (Options::$onClipboardRequest, mygo's terminal.ClipboardRequest): the
 * MIME types it asked for, in order of preference (empty when it asked only
 * for what is available), whether it asked for the types available (the
 * Kitty protocol's listing, which is answered without asking), whether it
 * wants the selection clipboard of X11, and what the clipboard held as
 * text when the request was seen, for a prompt about it. allow() or deny()
 * answers it, in the hook or later, the first of them winning.
 */
final class ClipboardRequest
{
    /** @internal 0 until allow() (1) or deny() (2) */
    public int $answer = 0;
    /** @internal Options::$onClipboardRequest was called with it */
    public bool $asked = false;

    /** @param list<string> $mimes @internal */
    public function __construct(
        public readonly array $mimes,
        public readonly bool $list,
        public readonly bool $primary,
        /** @internal the read the library deferred */
        public readonly DeferredClipboardRead $deferred,
        /** @internal the app is asked before it is answered */
        public readonly bool $ask,
        private readonly Terminal $t,
        public string $text = '',
    ) {}

    /** Answers the request with what the clipboard holds. */
    public function allow(): void { $this->answer(1); }

    /** Refuses the request. */
    public function deny(): void { $this->answer(2); }

    private function answer(int $a): void
    {
        if ($this->answer === 0) {
            $this->answer = $a;
            $this->t->redraw();
        }
    }
}
