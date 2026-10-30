<?php
declare(strict_types=1);

namespace MyPHP;

use InvalidArgumentException;

/**
 * An event PHP sends to pages (mygo's Event): declare it once, with the PHP
 * type of its payload, which the TypeScript client types
 * (`events.progress.on(p => ...)`):
 *
 *     $progress = new Event('progress', Progress::class);
 *     $progress->emit($win, new Progress(done: 1, total: 3));
 *
 * Events sent before a page's DOM is ready reach it once it is.
 */
final class Event
{
    /** @var array<string, string> the declared events' payload types, by name */
    private static array $declared = [];

    /** Declares the event; names must be unique, and those starting with "myphp:" are myphp's own. */
    public function __construct(public readonly string $name, public readonly string $payloadType = 'mixed')
    {
        if (str_starts_with($name, 'myphp:')) {
            throw new InvalidArgumentException("myphp: event \"$name\": names starting with \"myphp:\" are reserved");
        }
        if (isset(self::$declared[$name])) {
            throw new InvalidArgumentException("myphp: event \"$name\" is already declared");
        }
        self::$declared[$name] = $payloadType;
    }

    /** The event's name (mygo's Name). */
    public function name(): string
    {
        return $this->name;
    }

    /** Sends the event to the page of $w, or to the pages of its web views for a window showing native UI but untrusted ones (PageOptions::$untrusted; mygo's Emit). */
    public function emit(Window $w, mixed $payload = null): void
    {
        if ($w->isDestroyed()) {
            throw new \RuntimeException('myphp: the window has been destroyed');
        }
        foreach ($w->pages() as $p) {
            if (!$p->isUntrusted()) { $p->event($this->name, $payload); }
        }
    }

    /** Sends the event to one page: a window's, or a web view's (mygo's EmitPage). */
    public function emitPage(Page $p, mixed $payload = null): void
    {
        if ($p->isDestroyed()) {
            throw new \RuntimeException('myphp: the page has been destroyed');
        }
        $p->event($this->name, $payload);
    }

    /** Sends the event to every window's page, and the web views' (mygo's Broadcast). */
    public function broadcast(mixed $payload = null): void
    {
        foreach (Window::all() as $w) {
            foreach ($w->pages() as $p) {
                if (!$p->isUntrusted()) { $p->event($this->name, $payload); } // but untrusted pages (PageOptions::$untrusted)
            }
        }
    }

    /** @internal the declared events, for TypeScript @return array<string, string> */
    public static function declared(): array
    {
        return self::$declared;
    }
}
