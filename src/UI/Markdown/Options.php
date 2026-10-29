<?php
declare(strict_types=1);

namespace MyPHP\UI\Markdown;

use Closure;
use MyPHP\UI\Markdown\Parser\Extensions;

/**
 * How views show Markdown (mygo's markdown.Options): their theme, what links
 * and images do, how blocks render, and how text being written comes in.
 * Keep one in the app's state and give it to every view of a kind, as the
 * messages of a chat.
 */
final class Options
{
    /** @var array<string, array{0: bool, 1: \MyPHP\UI\Image|\MyPHP\UI\SVG|null}> the images loaded, by URL: whether done, and the image */
    public array $images = [];

    /**
     * @param ?Closure(string): void $onLink
     * @param ?Closure(string): (\MyPHP\UI\Image|\MyPHP\UI\SVG|null) $loadImage
     * @param ?Closure(\MyPHP\UI\Context, CodeBlockView): void $codeBlock
     * @param array<string, Closure(\MyPHP\UI\Context, CodeBlockView): void> $code
     * @param ?Closure(\MyPHP\UI\Context, string): void $math
     * @param ?Closure(\MyPHP\UI\Context, string): void $html
     * @param ?Closure(\MyPHP\UI\Context, Parser\Document, int): bool $block
     * @param ?Closure(string, float): ?InlineObject $inlineMath
     */
    public function __construct(
        /** The look: Theme::defaultTheme() of the window's theme when null, whose values those it leaves unset take. */
        public ?Theme $theme = null,
        /** The syntax read beyond CommonMark: Extensions::DEFAULT when 0. */
        public int $extensions = 0,
        /**
         * Handles a click on a link, given its destination. Without it,
         * links to http, https and mailto addresses open, a path without a
         * scheme goes there in the router around the view, a link to
         * #a-heading scrolls to that heading, and links of other schemes do
         * nothing.
         */
        public ?Closure $onLink = null,
        /** Loads the image at a URL for the views to show, in a fiber of its own (App::go), so a loader that waits (Image decoding off the main thread, a download) lets the app run; until it has, they show its description, as they do without it. Markdown::fileImages loads files. */
        public ?Closure $loadImage = null,
        /** Builds code blocks in place of Markdown::defaultCodeBlock, which it can call to wrap it; $code builds those of the languages it lists, as "mermaid", in place of either. */
        public ?Closure $codeBlock = null,
        public array $code = [],
        /** Wraps the lines of code blocks rather than scrolling them sideways. */
        public bool $wrapCode = false,
        /** Builds blocks of display math from their TeX, which show as their TeX without it; math within text shows as its TeX, unless $inlineMath makes it an object. */
        public ?Closure $math = null,
        /** Builds HTML blocks from their HTML, which show as their HTML without it; within text, the tags of formatting (<b>, <i>, <code>, <br>, <mark>, <sub>…) apply, and other tags show as text. */
        public ?Closure $html = null,
        /** Asked first for each block, builds it in its place when it returns true. */
        public ?Closure $block = null,
        /** How Markdown::stream shows text as it comes: new Reveal() when null. */
        public ?Reveal $reveal = null,
        /** Leaves the selection to a selectable container around the view, as one across a chat's messages, rather than giving the view one of its own. */
        public bool $shareSelection = false,
        /**
         * Makes math within text an object of the text (mygo's InlineMath),
         * from its TeX and the size of the text around it in points: one on
         * the baseline, which wraps with the words and copies as its source,
         * as $x_i$. When it returns null, the math shows as its TeX. It is
         * asked again whenever the paragraph changes, as every frame of a
         * paragraph streaming in: keep what it makes.
         *
         *     $o->inlineMath = fn(string $tex, float $size) => ($f = $app->formula($tex, $size)) === null ? null
         *         : new InlineObject($f->width, $f->ascent, $f->descent, $f->draw(...));
         */
        public ?Closure $inlineMath = null,
    ) {}

    /** The extensions read: Extensions::DEFAULT for 0. */
    public function ext(): int { return $this->extensions === 0 ? Extensions::DEFAULT : $this->extensions; }
}
