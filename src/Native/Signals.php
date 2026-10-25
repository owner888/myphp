<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;

/**
 * SIGINT and SIGTERM quit the app like Quit, so the quit listeners run and
 * can cancel; a second signal exits right away (mygo's quitOnSignals).
 * `myphp dev` stops the app it runs with SIGTERM. The signals are taken
 * from their default action and delivered by a dispatch source on the
 * main queue, which the run loop drains: the handler is PHP on the main
 * thread. Signals the parent ignores, as SIGINT for background jobs, stay
 * ignored.
 *
 * @internal
 */
final class Signals
{
    private const SIGINT = 2, SIGTERM = 15, SIG_IGN = 1;

    private static ?FFI $c = null;
    /** @var list<mixed> the dispatch sources, which live as long as the app */
    private static array $sources = [];
    private static bool $asked = false;

    /** @param \Closure(): void $quit */
    public static function quitOn(\Closure $quit): void
    {
        $c = self::$c ??= FFI::cdef('
            struct sigaction { uintptr_t handler; uint32_t mask; int flags; };
            int sigaction(int sig, const struct sigaction *act, struct sigaction *old);
            void* dispatch_source_create(const void* type, uintptr_t handle, uintptr_t mask, void* queue);
            void dispatch_source_set_event_handler(void* source, void* handler);
            void dispatch_resume(void* object);
            extern const char _dispatch_source_type_signal;
            extern char _dispatch_main_q;
        ', '/usr/lib/libSystem.B.dylib');
        foreach ([self::SIGINT, self::SIGTERM] as $sig) {
            $old = $c->new('struct sigaction');
            $c->sigaction($sig, null, FFI::addr($old));
            if ($old->handler === self::SIG_IGN) {
                continue; // ignored by the parent
            }
            $ignore = $c->new('struct sigaction');
            $ignore->handler = self::SIG_IGN; // the dispatch source takes it instead
            $c->sigaction($sig, FFI::addr($ignore), null);
            $source = $c->dispatch_source_create(FFI::addr($c->_dispatch_source_type_signal), $sig, 0, FFI::addr($c->_dispatch_main_q));
            $c->dispatch_source_set_event_handler($source, ObjC::block('', function () use ($sig, $quit): void {
                if (self::$asked) {
                    exit(128 + $sig);
                }
                self::$asked = true;
                $quit();
            }, once: false));
            $c->dispatch_resume($source);
            self::$sources[] = $source;
        }
    }
}
