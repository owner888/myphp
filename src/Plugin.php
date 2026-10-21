<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;

/**
 * A plugin of the app (mygo's Plugin), added with App::use(): a PHP
 * service pages call as "plugin:<name>.<method>", and what it sets up.
 * Plugins are PHP; their page side is a TypeScript client of their own
 * over window.myphp.call (the plugin's client/ directory).
 */
final class Plugin
{
    public function __construct(
        /** Identifies the plugin: lowercase letters, digits and hyphens, starting with a letter, as "sqlite". "myphp" and "mygo" are reserved. */
        public readonly string $name,
        /** Bound as "plugin:<name>", when not null, as Ipc::bind binds a service. */
        public readonly ?object $service = null,
        /** Runs once, when App::use takes the plugin, to add listeners and the like. Its exception makes App::use throw. */
        public readonly ?Closure $setup = null,
    ) {}
}
