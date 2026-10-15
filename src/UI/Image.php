<?php
declare(strict_types=1);

namespace MyPHP\UI;

use FFI\CData;
use MyPHP\Native\ImageIO;
use MyPHP\Native\Mem;
use RuntimeException;

/**
 * A decoded image the UI draws with Context::image(): RGBA pixels at
 * their own size (at most $max on a side), put into the image atlas the
 * first frame they are drawn. Make one once and keep it in the app's
 * state; it is not a per-frame object.
 */
final class Image
{
    private static int $next = 0;
    public readonly string $key;
    public readonly int $w, $h;
    private ?CData $pix;
    private int $addr;

    private function __construct(array $decoded)
    {
        $this->key = 'img' . ++self::$next;
        $this->w = $decoded['w']; $this->h = $decoded['h'];
        $this->pix = $decoded['pix']; $this->addr = $decoded['addr'];
    }

    public static function fromBytes(string $bytes, int $max = 1024): self
    {
        return new self(ImageIO::decode($bytes, $max));
    }

    public static function fromFile(string $path, int $max = 1024): self
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException("cannot read image $path");
        }
        return self::fromBytes($bytes, $max);
    }

    /** The atlas slot, added on first use (the pixels are then freed). */
    public function slot(Atlas $atlas): array
    {
        $slot = $atlas->get($this->key);
        if ($slot === null) {
            if ($this->pix === null) {
                throw new RuntimeException('the image left the atlas and its pixels are gone');
            }
            $slot = $atlas->add($this->key, $this->w, $this->h, $this->addr);
            Mem::free($this->pix);
            $this->pix = null;
        }
        return $slot;
    }
}
