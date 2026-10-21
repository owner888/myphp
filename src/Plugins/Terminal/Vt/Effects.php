<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal\Vt;

use Closure;

/**
 * What a terminal asks of its embedder while it processes output, all
 * optional (mygo's vt.Effects). They run during Terminal::write(), which
 * they must not call.
 *
 * @internal
 */
final class Effects
{
    /**
     * @param ?Closure(string): void $writePTY answers the program, as to a query of the cursor's position
     * @param ?Closure(): void $bell
     * @param ?Closure(): void $titleChanged tells that title() changed
     * @param ?Closure(): void $pwdChanged tells that pwd() changed
     * @param ?Closure(bool): void $renderHold tells that the program started or ended a synchronized update (mode 2026), during which the screen should not show
     * @param ?Closure(string, bool): bool $clipboard writes text to the clipboard (OSC 52), the selection clipboard of X11 when the second is set, and reports whether it did
     * @param ?Closure(ClipboardRead): ?ClipboardReply $clipboardRead answers a program's read of the clipboard: null leaves it unanswered, a reply with no contents refuses it; the reply, encoded, comes to $writePTY in one call as it returns
     * @param ?Closure(): array{0: int, 1: int, 2: int, 3: int} $size the size of the screen in cells and of a cell in pixels, for size reports
     * @param ?Closure(): bool $dark whether the colors are dark, for color scheme reports
     * @param ?Closure(string, string): void $notify shows a desktop notification (OSC 9, OSC 777)
     * @param ?Closure(int, int): void $progress reports the program's progress (OSC 9;4): its state and percent
     */
    public function __construct(
        public ?Closure $writePTY = null,
        public ?Closure $bell = null,
        public ?Closure $titleChanged = null,
        public ?Closure $pwdChanged = null,
        public ?Closure $renderHold = null,
        public ?Closure $clipboard = null,
        public ?Closure $clipboardRead = null,
        public ?Closure $size = null,
        public ?Closure $dark = null,
        public ?Closure $notify = null,
        public ?Closure $progress = null,
    ) {}
}
