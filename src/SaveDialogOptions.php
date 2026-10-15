<?php
declare(strict_types=1);

namespace MyPHP;

final class SaveDialogOptions
{
    /** @param FileFilter[] $filters */
    public function __construct(
        public ?Window $parent = null,
        public string $title = '',
        public string $defaultPath = '',
        public string $buttonLabel = '',
        public string $message = '',
        public string $nameFieldLabel = '',
        public array $filters = [],
        public bool $showHiddenFiles = false,
        public bool $createDirectories = true,
    ) {}
}
