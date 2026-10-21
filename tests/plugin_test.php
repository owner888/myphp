<?php
// Plugins (mygo's plugin.go and the context of a call): App::use's checks,
// a plugin's service bound as "plugin:<name>" and left out of the
// TypeScript client, CallContext given to the methods that take one, and
// its end.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\{App, CallContext, Channel, Ipc, Plugin, TypeScript};

/** A plugin's service, as a page calls it. */
final class EchoService
{
    public ?CallContext $seen = null;

    /** Echoes its arguments, noting the call's context. @param list<int> $more */
    public function echo(CallContext $ctx, string $a, int $b = 2, int ...$more): array
    {
        $this->seen = $ctx;
        return [$a, $b, $more];
    }
}

(function () {
    $thrown = function (\Closure $fn): string {
        try { $fn(); } catch (\RuntimeException $e) { return $e->getMessage(); }
        return '';
    };
    foreach (['', 'Echo', '9lives', 'with space', 'a_b'] as $bad) {
        check(str_contains($thrown(fn() => App::use(new Plugin($bad))), 'must be lowercase'), "the name \"$bad\" is refused");
    }
    check(str_contains($thrown(fn() => App::use(new Plugin('myphp'))), 'reserved'), 'myphp is reserved');
    $ran = 0;
    $svc = new EchoService;
    App::use(new Plugin('echo-test', $svc, function () use (&$ran) { $ran++; }));
    same(1, $ran, 'setup runs once');
    check(Ipc::pluginUsed('echo-test') && isset(Ipc::services()['plugin:echo-test']), 'the service is bound as plugin:echo-test');
    check(str_contains($thrown(fn() => App::use(new Plugin('echo-test'))), 'already used'), 'a plugin used twice');
    check(str_contains($thrown(fn() => App::use(new Plugin('failing', null, fn() => throw new \RuntimeException('no')))), 'plugin failing: no'), 'a setup that throws');
    check(!str_contains(TypeScript::generate(), 'plugin:echo-test'), "the app's TypeScript client leaves the plugin to its own");

    // A CallContext parameter takes no argument of the page.
    $ctx = new CallContext(null);
    $m = Ipc::services()['plugin:echo-test']['methods']['echo'];
    $args = Ipc::arguments($m, ['x', 5, 6, 7], $ctx, fn(int $id) => throw new \LogicException('no channel'));
    same([$ctx, 'x', 5, 6, 7], $args, 'the context is given, the page\'s arguments follow');
    same(['x', 5, [6, 7]], $m->invokeArgs($svc, $args), 'the call');
    same($ctx, $svc->seen, 'the method sees the context');
    same([$ctx, 'y'], Ipc::arguments($m, ['y'], $ctx, fn(int $id) => throw new \LogicException('no channel')), 'defaults for what the page leaves out');

    // The context ends once: what waits for it runs, at once when it already ended.
    $runs = [];
    $stop = $ctx->afterFunc(function () use (&$runs) { $runs[] = 'a'; });
    $ctx->afterFunc(function () use (&$runs) { $runs[] = 'b'; });
    $stopped = $ctx->afterFunc(function () use (&$runs) { $runs[] = 'c'; });
    check($stopped(), 'a stop before the end stops it');
    check(!$stopped(), 'and only once');
    check(!$ctx->done(), 'not done yet');
    $ctx->cancel();
    $ctx->cancel();
    same(['a', 'b'], $runs, 'what waited ran once, but what was stopped');
    check($ctx->done() && !$stop(), 'done, and nothing left to stop');
    $ctx->afterFunc(function () use (&$runs) { $runs[] = 'late'; });
    same(['a', 'b', 'late'], $runs, 'after the end, at once');
})();
