<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\TextState;

/**
 * Persistent editing state for a TextField, searchable() or findNavigator() bar. @internal
 */
final class FieldKept
{
    public TextState $state;

    public function __construct(public string $app = '')
    {
        $this->state = new TextState($app);
    }
}
