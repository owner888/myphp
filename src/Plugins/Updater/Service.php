<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Updater;

use Generator;
use MyPHP\{CallContext, Channel};
use RuntimeException;

/**
 * What the update window's page calls (mygo's updater service), bound as
 * "plugin:updater". Only the window of the session under way may call it.
 *
 * @internal
 */
final class Service
{
    public function __construct(private readonly Checker $u) {}

    /** The session whose window made the call. */
    private function session(CallContext $ctx): Session
    {
        $s = $this->u->session;
        if ($s === null || $ctx->window() === null || $s->win !== $ctx->window()) {
            throw new RuntimeException('only the update window may call the updater');
        }
        return $s;
    }

    /**
     * Streams the views of the session to the update window.
     *
     * @param Channel $views
     */
    public function watch(CallContext $ctx, Channel $views): Generator
    {
        $s = $this->session($ctx);
        $seen = -1;
        while (!$ctx->done() && !$s->ended) {
            if ($s->changes !== $seen) {
                $seen = $s->changes;
                yield UpdateWindow::pageView($s->view);
            } else {
                yield Channel::wait(0.02);
            }
        }
    }

    /** Gives the window the width its buttons need, and a status view the height of its text, which both depend on the language and the fonts. */
    public function fit(CallContext $ctx, int $width, int $height): void
    {
        $this->session($ctx)->fitContent($width, $height);
    }

    /** Answers the prompt of a view with a button, and tells whether updates may install automatically from now on. */
    public function respond(CallContext $ctx, int $prompt, string $action, bool $automaticDownloads): void
    {
        $this->session($ctx)->respond($prompt, $action, $automaticDownloads);
    }
}
