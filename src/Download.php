<?php
declare(strict_types=1);

namespace MyPHP;

/** A download that ended, given to a page's 'downloadDone' listeners (mygo's Download): $error is why it failed, null when it did not. */
final class Download
{
    public function __construct(
        public readonly string $url,
        public readonly string $path,
        public readonly ?string $error = null,
    ) {}

    /** @internal the file name a download suggests, without directories (mygo's DownloadStarted) */
    public static function fileName(string $suggested): string
    {
        $name = basename('/' . str_replace('\\', '/', $suggested));
        return $name === '' || $name === '.' || $name === '..' ? 'download' : $name;
    }

    /** @internal $path, or numbered as "name (2).ext" when a file has its name (mygo's uniquePath) */
    public static function uniquePath(string $path): string
    {
        if (!file_exists($path) && !is_link($path)) {
            return $path;
        }
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $base = $ext === '' ? $path : substr($path, 0, -strlen($ext) - 1);
        for ($i = 2; ; $i++) {
            $p = "$base ($i)" . ($ext === '' ? '' : ".$ext");
            if (!file_exists($p) && !is_link($p)) {
                return $p;
            }
        }
    }
}
