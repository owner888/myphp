<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use MyPHP\Logger;

/** IINA's iina.console: the plugin's log, under its subsystem; what is not a string written as JSON. */
final class ConsoleApi extends Api
{
    public function log(mixed ...$args): void
    {
        $this->instance->log(count($args) === 1 ? self::text($args[0]) : implode('', array_map(fn($a) => self::text($a) . (is_array($a) || is_object($a) ? "\n" : ' '), $args)), Logger::DEBUG);
    }

    public function warn(mixed $message): void
    {
        $this->instance->log(self::text($message), Logger::WARNING);
    }

    public function error(mixed $message): void
    {
        $this->instance->log(self::text($message), Logger::ERROR);
    }

    private static function text(mixed $v): string
    {
        return match (true) {
            is_string($v) => $v,
            $v === null => '<null>',
            is_bool($v) => $v ? 'true' : 'false',
            is_int($v), is_float($v) => (string)$v,
            $v instanceof \Stringable => (string)$v,
            default => (string)json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
        };
    }
}
