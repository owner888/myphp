<?php
declare(strict_types=1);

namespace MyPHP\Media;

/** A chapter of a film (Matroska's ChapterAtom, an MP4's chapter track): its title, '' for none, and where it starts; it lasts until the next one, the last until the end. */
final class Chapter
{
    public function __construct(
        public readonly string $title,
        /** In seconds. */
        public readonly float $start,
    ) {}
}
