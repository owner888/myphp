<?php
declare(strict_types=1);

namespace MyPHP;

final class MessageResult
{
    public function __construct(public int $button, public bool $checkboxChecked) {}
}
