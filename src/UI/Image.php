<?php
declare(strict_types=1);

namespace MyPHP\UI;

use FFI\CData;
use MyPHP\Native\CoreImage;
use MyPHP\Native\Mem;
use RuntimeException;

/**
 * A decoded image the UI draws with Context::image(): RGBA pixels at
 * their own size (at most $max on a side), put into the image atlas the
 * first frame they are drawn and again whenever the atlas starts over,
 * so the pixels stay (w*h*4 bytes). Make one once and keep it in the
 * app's state; it is not a per-frame object.
 */
final class Image
{
    private static int $next = 0;
    /** What the atlas holds it by, its size and its pixels' address: update() changes them where the size changes. */
    public string $key;
    public int $w, $h;
    private CData $pix;
    public int $addr;
    /** That update() gave it new pixels the atlas has yet to take. @internal */
    public bool $updated = false;
    /** @internal how many times update() gave it new pixels, which a texture a shader reads it from was made of */
    public int $version = 0;
    /** Shows its pixels as squares when scaled up, sampled at the nearest rather than blended, as pixel art wants (mygo's Bitmap.Pixelated); its levels, smaller, stay smooth. */
    public bool $pixelated = false;
    /** @internal the canvas whose bitmap this is: its pixels are on the GPU (Canvas::bitmap) */
    public ?Canvas $canvas = null;
    /** @var list<Image> the image halved once, twice…, made as a smaller showing asks (mygo's levels) */
    private array $levels = [];

    private function __construct(array $decoded)
    {
        $this->key = 'img' . ++self::$next;
        $this->w = $decoded['w']; $this->h = $decoded['h'];
        $this->pix = $decoded['pix']; $this->addr = $decoded['addr'];
    }

    /**
     * Decodes a PNG, JPEG, HEIC or whatever else ImageIO reads, upright as
     * its orientation says, at most $max pixels on a side (mygo's
     * DecodeBitmap), off the main thread: in a fiber (App::go) it waits
     * while the app draws and takes events; outside one, it blocks until
     * the image is there.
     */
    public static function fromBytes(string $bytes, int $max = 1024): self
    {
        return new self(CoreImage::decode($bytes, $max));
    }

    /** An image of $w × $h premultiplied RGBA pixels, four bytes a pixel, top row first. */
    public static function fromPixels(int $w, int $h, string $rgba): self
    {
        $n = $w * $h * 4;
        if (strlen($rgba) !== $n) { throw new RuntimeException("image {$w}x{$h} needs $n bytes, got " . strlen($rgba)); }
        $pix = Mem::alloc($n);
        \FFI::memcpy($pix, $rgba, $n);
        return new self(['w' => $w, 'h' => $h, 'pix' => $pix, 'addr' => Mem::addr($pix)]);
    }

    /** @internal the bitmap of $c, whose pixels the GPU holds */
    public static function ofCanvas(Canvas $c): self
    {
        $pix = Mem::alloc(4);
        $img = new self(['w' => $c->w, 'h' => $c->h, 'pix' => $pix, 'addr' => Mem::addr($pix)]);
        $img->canvas = $c;
        return $img;
    }

    /**
     * SF Symbol $name (gearshape, play.fill, speaker.wave.2.fill…), the
     * system's icon of that name, at $size points in $rgb, of $weight
     * (NSFontWeight: -0.8 ultra-light, 0 regular, 0.23 semibold, 0.4 bold,
     * 0.62 black), in $scale pixels a point (2 for a Retina screen, which
     * Context::image shows it at its size on): macOS only. Throws for a
     * name the system has no symbol of.
     */
    public static function symbol(string $name, float $size, int $rgb = 0xffffff, float $weight = 0.0, float $scale = 2.0): self
    {
        [$w, $h, $rgba] = \MyPHP\Native\Symbols::draw($name, $size, $weight, $rgb, $scale);
        return self::fromPixels($w, $h, $rgba);
    }

    /** The image of file $path, as fromBytes decodes it. */
    public static function fromFile(string $path, int $max = 1024): self
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException("cannot read image $path");
        }
        return self::fromBytes($bytes, $max);
    }

    /**
     * Shows $w × $h premultiplied RGBA pixels in place of the image's, for
     * one that changes as video does (mygo's Bitmap.Update): of the same
     * size, they go into the image's slot of the atlas, which the GPU
     * uploads, where a new image a frame would take a slot each; of
     * another, the image is drawn anew. Its smaller levels are made again.
     */
    public function update(int $w, int $h, string $rgba): void
    {
        if ($this->canvas !== null) {
            throw new RuntimeException("myphp: a canvas's bitmap is drawn by Canvas::draw");
        }
        $n = $w * $h * 4;
        if (strlen($rgba) !== $n) { throw new RuntimeException("image {$w}x{$h} needs $n bytes, got " . strlen($rgba)); }
        if ($w !== $this->w || $h !== $this->h) {
            Mem::free($this->pix);
            $this->pix = Mem::alloc($n); $this->addr = Mem::addr($this->pix);
            $this->w = $w; $this->h = $h;
            $this->key = 'img' . ++self::$next; // a slot of its new size
        } else {
            $this->updated = true;
        }
        \FFI::memcpy($this->pix, $rgba, $n);
        $this->version++;
        $this->levels = [];
    }

    /** The size of the image in pixels, which Context::image shows as points (mygo's Size). @return array{0: int, 1: int} */
    public function size(): array
    {
        return [$this->w, $this->h];
    }

    /** Makes the image pixelated ($pixelated) and returns it. */
    public function pixelated(): self
    {
        $this->pixelated = true;
        return $this;
    }

    /** The pixels, premultiplied RGBA, four bytes a pixel, top row first. */
    public function pixels(): string
    {
        if ($this->canvas !== null) {
            throw new RuntimeException("myphp: a canvas's pixels are on the GPU");
        }
        return \FFI::string($this->pix, $this->w * $this->h * 4);
    }

    /**
     * The image, or a halving of it, for showing $shrink times smaller
     * than its pixels, as mygo's smaller: far smaller than its pixels, a
     * level of it, so it is sampled smooth rather than aliased. Levels are
     * made once and kept.
     */
    public function smaller(float $shrink): self
    {
        if ($shrink < 2 || $this->canvas !== null) { return $this; } // a canvas has no pixels here to halve
        $k = (int)log($shrink, 2); // halvings that stay as large
        $img = $this;
        for ($i = 0; $i < $k; $i++) {
            if ($i === count($this->levels)) {
                if ($img->w < 2 && $img->h < 2) { return $img; }
                $this->levels[] = $img->halved();
            }
            $img = $this->levels[$i];
        }
        return $img;
    }

    /** This image half as large, each pixel the average of the 2 × 2 it covers, of premultiplied colors; an odd last row or column counts twice. */
    private function halved(): self
    {
        $w = max(1, intdiv($this->w + 1, 2)); $h = max(1, intdiv($this->h + 1, 2));
        $src = \FFI::string($this->pix, $this->w * $this->h * 4); $stride = 4 * $this->w;
        $out = '';
        for ($y = 0; $y < $h; $y++) {
            $r0 = 2 * $y * $stride; $r1 = min(2 * $y + 1, $this->h - 1) * $stride;
            for ($x = 0; $x < $w; $x++) {
                $x0 = 8 * $x; $x1 = 4 * min(2 * $x + 1, $this->w - 1);
                for ($c = 0; $c < 4; $c++) {
                    $out .= chr(intdiv(ord($src[$r0 + $x0 + $c]) + ord($src[$r0 + $x1 + $c]) + ord($src[$r1 + $x0 + $c]) + ord($src[$r1 + $x1 + $c]) + 2, 4));
                }
            }
        }
        return self::fromPixels($w, $h, $out);
    }

    public function __destruct()
    {
        Mem::free($this->pix);
    }
}
