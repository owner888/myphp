<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

use Closure;

/** Configure an editor (mygo's richtext.EditorOptions). */
final class EditorOptions
{
    /**
     * @param ?Closure(string): void $onLink
     * @param ?Closure(): void $onLinkCommand
     * @param ?Closure(string, \MyPHP\UI\Span): void $tagStyle
     */
    public function __construct(
        /** Names the editor to assistive technology; "Rich text" when empty. */
        public string $label = '',
        /** Shows while the text is empty. */
        public string $placeholder = '',
        /** The text's family and size, the window theme's when unset (mygo's Font). */
        public string $font = '',
        public float $fontSize = 0,
        /** Shows the text without letting the user change it: it is selected and copied, and its links and tasks are clicked. */
        public bool $readOnly = false,
        /** Turns off the Markdown shortcuts: "# " at the start of a paragraph making a heading, **bold** making bold, and the others. */
        public bool $noMarkdown = false,
        /** Opens the URL of a link clicked, instead of the system, which opens only those Context::safeToOpen() allows. */
        public ?Closure $onLink = null,
        /** Runs for ⌘K, as an app asks for the URL of a link in a popover and sets it with Document::setLink(). Without it, ⌘K links a selected URL to itself. */
        public ?Closure $onLinkCommand = null,
        /** Styles the runs that carry a tag, as an app shows mentions or comments: it changes the span it gets. */
        public ?Closure $tagStyle = null,
        /** How the text looks: colors, code, headings, lists and quotations. What it leaves unset, and all of it when null, takes the window theme's Theme::defaultTheme(). */
        public ?Theme $theme = null,
    ) {}
}
