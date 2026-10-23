<?php
declare(strict_types=1);

namespace MyPHP;

/** The size of a sheet of paper in inches, portrait (mygo's PageSize, and its PageLetter… as static methods). */
final class PageSize
{
    public function __construct(
        public readonly float $width,
        public readonly float $height,
    ) {}

    public static function letter(): self { return new self(8.5, 11); }
    public static function legal(): self { return new self(8.5, 14); }
    public static function tabloid(): self { return new self(11, 17); }
    public static function a3(): self { return new self(11.69, 16.54); }
    public static function a4(): self { return new self(8.27, 11.69); }
    public static function a5(): self { return new self(5.83, 8.27); }
}
