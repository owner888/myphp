<?php
declare(strict_types=1);

namespace MyPHP;

final class MessageOptions
{
    /**
     * @param string[] $buttons the first is the default unless $defaultButton says otherwise
     * @param int $defaultButton the button Enter activates, or -1 for none
     * @param int $cancelButton the button Escape and Cmd+. activate, also when it is the default; -1 for the first labeled Cancel or No
     * @param int[] $destructiveButtons the buttons whose actions delete data or can't be undone, which macOS sets apart and may keep Enter from activating, even as the default (mygo's DestructiveButtons)
     */
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
        public array $destructiveButtons = [],
        public string $checkboxLabel = '',
        public bool $checkboxChecked = false,
    ) {}
}
