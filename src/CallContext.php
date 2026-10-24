<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;

/**
 * The page a bound method was called from, and its life (mygo's context of
 * a call, with CallerWindow and CallerPage). A method of a bound service
 * that has a CallContext parameter gets it in that place; the page passes
 * no argument for it. It is the same for every call of one document of a
 * window, and ends as the page goes away: when the window loads another
 * document, reloads, or closes.
 */
final class CallContext
{
    private bool $done = false;
    /** @var array<int, Closure> what runs as it ends, by key */
    private array $after = [];
    private int $next = 0;

    /** @internal pages make them, one per document */
    public function __construct(private readonly ?Window $window, private readonly ?Page $page = null) {}

    /** The window whose page called, the one a web view is in for a web view's page (mygo's CallerWindow). */
    public function window(): ?Window
    {
        return $this->window;
    }

    /** The page that called: a window's, or a web view's (mygo's CallerPage). */
    public function page(): ?Page
    {
        return $this->page;
    }

    /** Whether the page went away (mygo's ctx.Err() != nil). */
    public function done(): bool
    {
        return $this->done;
    }

    /**
     * Runs $fn once the page goes away, at once when it has (Go's
     * context.AfterFunc). Returns a function that stops it, which reports
     * whether it stopped it before it ran.
     */
    public function afterFunc(Closure $fn): Closure
    {
        if ($this->done) {
            $fn();
            return fn(): bool => false;
        }
        $k = $this->next++;
        $this->after[$k] = $fn;
        return function () use ($k): bool {
            if (!isset($this->after[$k])) {
                return false;
            }
            unset($this->after[$k]);
            return true;
        };
    }

    /** @internal ends the context: the page went away */
    public function cancel(): void
    {
        if ($this->done) {
            return;
        }
        $this->done = true;
        $after = $this->after;
        $this->after = [];
        foreach ($after as $fn) {
            $fn();
        }
    }
}
