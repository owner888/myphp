<?php
declare(strict_types=1);

namespace MyPHP\Native\Docs;

use Closure;
use MyPHP\{DocumentState, Window};

/**
 * The window of a document, as documents use it: WindowFrame's of a
 * Window, a test's own otherwise.
 *
 * @internal
 */
interface Frame
{
    /** The window, or null for a test's. */
    public function window(): ?Window;

    /** Calls $fn(bool $canceled): bool as the window is asked to close; true keeps it open. */
    public function onClose(Closure $fn): void;

    /** Calls $fn once the window closed. */
    public function onClosed(Closure $fn): void;

    public function onFocus(Closure $fn): void;

    public function onBlur(Closure $fn): void;

    /** Brings the window to the front, with the keyboard. */
    public function show(): void;

    /** Closes the window as the user does: its 'close' listeners may keep it open. */
    public function close(): void;

    /** Closes the window without asking. */
    public function destroy(): void;

    public function isDestroyed(): bool;

    /** Shows the document's state: title, represented file, edited dot, and the page's event. */
    public function present(DocumentState $s): void;

    /** Tells the page that the document's content was read again. */
    public function reloaded(): void;
}
