<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

/** A run of text with its attributes (mygo's richtext.Run). */
final class Run
{
    public function __construct(
        public string $text = '',
        public Attributes $attributes = new Attributes,
    ) {}
}
