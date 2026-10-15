<?php
declare(strict_types=1);

namespace MyPHP;

final class OpenDialogOptions
{
    /** @param FileFilter[] $filters */
    public function __construct(
        public ?Window $parent = null,
        public string $title = '',
        public string $defaultPath = '',
        public string $buttonLabel = '',
        public string $message = '',
        public array $filters = [],
        public bool $directory = false,
        public bool $multiple = false,
        public bool $showHiddenFiles = false,
        public bool $createDirectories = true,
    ) {}
}
