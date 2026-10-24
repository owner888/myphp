<?php
declare(strict_types=1);

namespace MyPHP;

/** Given to a page's 'willDownload' listeners (mygo's DownloadEvent): setting $path chooses where the file goes, preventDefault() cancels the download. */
final class DownloadEvent
{
    public bool $prevented = false;

    public function __construct(
        public readonly string $url,
        /** The file name the page or the server suggests. */
        public readonly string $suggestedName,
        /** Where the file is saved: by default the suggested name in the Downloads directory, numbered when a file has that name. A file at a path the listeners set is replaced. */
        public string $path,
    ) {}

    public function preventDefault(): void
    {
        $this->prevented = true;
    }
}
