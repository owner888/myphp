<?php
declare(strict_types=1);

namespace MyPHP;

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
            foreach ($m->getParameters() as $p) {
                self::checkType($p->getType(), "$name.{$m->getName()}(\${$p->getName()})");
            }
            self::checkType($m->getReturnType(), "$name.{$m->getName()}()");
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
    public static function handle(string $raw, string $secret): ?array
    {
        try {
            $msg = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return null;
        }
        if (($msg['t'] ?? null) !== 'call') {
            return null;
        }
        $id = $msg['id'] ?? 0;
        if (!hash_equals($secret, (string)($msg['k'] ?? ''))) {
            return ['t' => 'reply', 'id' => $id, 'k' => $secret, 'ok' => false, 'e' => 'rejected: wrong secret'];
        }
        [$result, $error] = self::call((string)($msg['m'] ?? ''), $msg['a'] ?? []);
        return $error === null
            ? ['t' => 'reply', 'id' => $id, 'k' => $secret, 'ok' => true, 'v' => $result]
            : ['t' => 'reply', 'id' => $id, 'k' => $secret, 'ok' => false, 'e' => $error];
    }

    /** @return array{0: mixed, 1: ?string} result and error message */
    private static function call(string $qualified, array $args): array
    {
        [$service, $method] = array_pad(explode('.', $qualified, 2), 2, '');
        $m = self::$services[$service]['methods'][$method] ?? null;
        if ($m === null) {
            return [null, "no bound method $qualified"];
        }
        try {
            return [$m->invokeArgs(self::$services[$service]['object'], array_values($args)), null];
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
