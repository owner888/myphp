<?php
declare(strict_types=1);

namespace MyPHP;

/** How Page::printToPDF() prints (mygo's PDFOptions): by default Letter pages, portrait, margins of 0.4 inch, no backgrounds, as a browser's print dialog. */
final class PDFOptions
{
    public function __construct(
        /** The size of the paper; Letter for null. */
        public ?PageSize $pageSize = null,
        public bool $landscape = false,
        /** The margins of every page; 0.4 inch on every side for null, new Margins() for none. */
        public ?Margins $margins = null,
        /** Prints background colors and images, which printing leaves out by default. */
        public bool $background = false,
    ) {}
}
