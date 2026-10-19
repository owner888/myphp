<?php
declare(strict_types=1);

namespace MyPHP;

use Generator;
use JsonException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Throwable;

/**
 * Bound services: PHP objects whose public methods pages call by
 * "Service.method", with JSON arguments and results, as mygo's Bind.
 * Calls run on the main thread, one at a time, in the order they arrive.
 * A Channel parameter streams values to the page (see Channel); a method
 * returning a Generator streams each yield through its first Channel
 * parameter and replies with the generator's return value, the run loop
 * turning between yields. "myphp:window.*" calls are the page's window
 * controls (window.myphp.window in the page).
 */
final class Ipc
{
    /** @var array<string, array{object: object, methods: array<string, ReflectionMethod>, doc: string}> */
    private static array $services = [];

    /** Exposes the public methods of $service under its short class name, or $name. */
    public static function bind(object $service, ?string $name = null): void
    {
        $rc = new ReflectionClass($service);
        $name ??= $rc->getShortName();
        if (isset(self::$services[$name])) {
            throw new RuntimeException("Ipc: service $name is bound twice");
        }
        $methods = [];
        foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->isStatic() || $m->isConstructor() || str_starts_with($m->getName(), '__')) {
                continue;
            }
            $channels = false;
            foreach ($m->getParameters() as $p) {
                $t = $p->getType();
                if ($t instanceof ReflectionNamedType && $t->getName() === Channel::class) {
                    $channels = true;
                    continue;
                }
                self::checkType($t, "$name.{$m->getName()}(\${$p->getName()})");
            }
            $rt = $m->getReturnType();
            if ($rt instanceof ReflectionNamedType && $rt->getName() === Generator::class) {
                $channels || throw new RuntimeException("Ipc: $name.{$m->getName()}() returns a Generator but has no Channel parameter to stream through");
            } else {
                self::checkType($rt, "$name.{$m->getName()}()");
            }
            $methods[$m->getName()] = $m;
        }
        self::$services[$name] = ['object' => $service, 'methods' => $methods, 'doc' => (string)$rc->getDocComment()];
    }

    private static function checkType($type, string $where): void
    {
        if ($type instanceof ReflectionNamedType && in_array($type->getName(), ['callable', 'Closure', 'resource', 'iterable', 'Generator'], true)) {
            throw new RuntimeException("Ipc: $where uses {$type->getName()}, which JSON cannot carry");
        }
    }

    /** @return array<string, array{object: object, methods: array<string, ReflectionMethod>, doc: string}> */
    public static function services(): array
    {
        return self::$services;
    }

    /**
     * Handles one message a page posted. Returns the reply to deliver, or
     * null for messages that need none.
     */
    public static function handle(string $raw, string $secret, Window $window): ?array
    {
        try {
            $msg = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return null;
        }
        $t = $msg['t'] ?? null;
        if ($t !== 'call' && $t !== 'chan-close' && $t !== 'chan-ack') {
            return null;
        }
        $id = $msg['id'] ?? 0;
        if (!hash_equals($secret, (string)($msg['k'] ?? ''))) {
            return $t === 'call' ? ['t' => 'reply', 'id' => $id, 'k' => $secret, 'ok' => false, 'e' => 'rejected: wrong secret'] : null;
        }
        if ($t === 'chan-close') {
            $window->channel((int)($msg['c'] ?? 0))?->closedByPage();
            return null;
        }
        if ($t === 'chan-ack') {
            return null; // no back-pressure: the main thread cannot wait for the page
        }
        $method = (string)($msg['m'] ?? '');
        $args = array_values($msg['a'] ?? []);
        if (str_starts_with($method, 'myphp:window.')) {
            [$result, $error] = self::windowCall($window, substr($method, strlen('myphp:window.')), $args);
            return self::reply($id, $secret, $result, $error);
        }
        [$service, $name] = array_pad(explode('.', $method, 2), 2, '');
        $m = self::$services[$service]['methods'][$name] ?? null;
        if ($m === null) {
            return self::reply($id, $secret, null, "no bound method $method");
        }
        $channels = [];
        foreach ($m->getParameters() as $i => $p) {
            $pt = $p->getType();
            if ($pt instanceof ReflectionNamedType && $pt->getName() === Channel::class) {
                $channels[] = $args[$i] = $window->openChannel((int)($args[$i] ?? 0), $secret);
            }
        }
        try {
            $result = $m->invokeArgs(self::$services[$service]['object'], $args);
        } catch (Throwable $e) {
            self::closeAll($channels);
            return self::reply($id, $secret, null, $e->getMessage());
        }
        if ($result instanceof Generator) {
            self::pump($result, $channels, $window, $id, $secret);
            return null; // the reply comes when the generator ends
        }
        self::closeAll($channels);
        return self::reply($id, $secret, $result, null);
    }

    private static function reply(int $id, string $secret, mixed $result, ?string $error): array
    {
        return $error === null
            ? ['t' => 'reply', 'id' => $id, 'k' => $secret, 'ok' => true, 'v' => $result]
            : ['t' => 'reply', 'id' => $id, 'k' => $secret, 'ok' => false, 'e' => $error];
    }

    /** @param list<Channel> $channels */
    private static function closeAll(array $channels): void
    {
        foreach ($channels as $ch) {
            $ch->close();
        }
    }

    /**
     * Runs a generator one yield per run-loop turn: each yield goes to the
     * first channel; a channel the page closed is thrown into the
     * generator; the return value is the call's reply.
     * @param list<Channel> $channels
     */
    private static function pump(Generator $gen, array $channels, Window $window, int $id, string $secret): void
    {
        $out = $channels[0];
        $step = function () use (&$step, $gen, $channels, $out, $window, $id, $secret) {
            try {
                if ($out->isClosed() && $gen->valid()) {
                    $gen->throw(new ChannelClosed("channel {$out->id} is closed"));
                }
                if (!$gen->valid()) {
                    self::closeAll($channels);
                    $window->page()?->deliver(self::reply($id, $secret, $gen->getReturn(), null));
                    return;
                }
                $out->send($gen->current());
                $gen->next();
            } catch (Throwable $e) {
                self::closeAll($channels);
                $window->page()?->deliver(self::reply($id, $secret, null, $e->getMessage()));
                return;
            }
            App::later($step);
        };
        App::later($step);
    }

    /** The page's window controls: window.myphp.window.minimize() and friends. */
    private static function windowCall(Window $w, string $name, array $args): array
    {
        try {
            return [match ($name) {
                'Minimize' => $w->minimize(),
                'Maximize' => $w->maximize(),
                'Unmaximize' => $w->unmaximize(),
                'ToggleMaximize' => $w->toggleMaximize(),
                'IsMaximized' => $w->isMaximized(),
                'ToggleFullScreen' => $w->toggleFullScreen(),
                'Close' => $w->close(),
                'SetTitle' => $w->setTitle((string)($args[0] ?? '')),
                default => throw new RuntimeException("no window control $name"),
            }, null];
        } catch (Throwable $e) {
            return [null, $e->getMessage()];
        }
    }

    /** Sends an event to every page open. */
    public static function emit(string $name, mixed $payload = null): void
    {
        foreach (Window::all() as $w) {
            $w->send($name, $payload);
        }
    }
}
