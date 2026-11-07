<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use Closure;

/**
 * A binding of a value computed rather than kept, as SwiftUI's
 * Binding(get:set:): a Picker of the paragraph's style, read from the
 * document and set by a command. Given where a control takes a variable:
 * Picker('Style', selection: new Binding(get: fn() => …, set: fn($v) => …)).
 */
final class Binding
{
    public function __construct(public readonly Closure $get, public readonly Closure $set) {}

    public function get(): mixed { return ($this->get)(); }

    public function set(mixed $value): void { ($this->set)($value); }
}
