<?php
declare(strict_types=1);

namespace MyPHP;

final class MessageOptions
{
    /** @param string[] $buttons the first is the default unless $defaultButton says otherwise */
    public function __construct(
        public ?Window $parent = null,
        /** 'none', 'info', 'warning', 'error' or 'question' */
        public string $type = 'info',
        public string $title = '',
        public string $message = '',
        public string $detail = '',
        public array $buttons = [],
        public int $defaultButton = 0,
        public int $cancelButton = -1,
        public string $checkboxLabel = '',
        public bool $checkboxChecked = false,
    ) {}
}
