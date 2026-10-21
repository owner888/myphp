<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

use MyPHP\UI\{Context, Node};

/**
 * The package of rich text (mygo's ui/richtext): its editor, its codecs
 * and the clipboard's formats; Text, Document and their parts are its
 * types.
 */
final class RichText
{
    /**
     * The clipboard's format of rich text copied from an Editor: its
     * paragraphs, styles and runs as JSON, which other myphp and MyGo apps
     * paste whole, as Lexical and Slate copy a format of their own first.
     */
    public const FORMAT = 'application/x-mygo-rich-text';
    /** The format of Rich Text Format, which macOS apps (TextEdit, Notes, Pages) copy. */
    public const RTF = 'text/rtf';

    /**
     * The rich text of Markdown, CommonMark with GitHub's extensions and
     * footnotes, as Text::markdown() writes it: headings, lists and task
     * lists, quotations, blocks of code, bold, italic, strikethrough, code,
     * links, images, and the HTML tags of these marks and of underlines,
     * <u>. A list item's paragraphs after its first are indented under it;
     * a table's rows are paragraphs of their cells apart by tabs, the
     * header's bold. Math, which the editor does not show, stays as
     * written: \[ is an escaped bracket, as everywhere but in math.
     */
    public static function parseMarkdown(string $s): Text { return MarkdownReader::parse($s); }

    /**
     * The rich text of an HTML document or fragment, as a paste from a
     * browser, mail or a word processor brings: paragraphs, headings,
     * lists, task lists, quotations, blocks of code, alignment, bold,
     * italic, underline, strikethrough, code and links. Fonts, sizes and
     * colors are left out, as in Notes, so that what is pasted takes the
     * editor's.
     */
    public static function parseHTML(string $s): Text { return HtmlBuilder::parse($s); }

    /**
     * The rich text of a document in Rich Text Format, as macOS apps
     * (TextEdit, Pages) and WordPad copy it: paragraphs, their alignment
     * and lists, bold, italic, underline, strikethrough and links. Fonts,
     * sizes and colors are left out, as parseHTML() leaves them.
     */
    public static function parseRTF(string $data): Text { return RtfReader::parse($data); }

    /**
     * An editor of $doc's rich text, as NSTextView edits attributed text
     * (mygo's Editor): bold, italic, underline, strikethrough, code, fonts,
     * sizes and colors, links, headings, lists, tasks, quotations, blocks of
     * code, alignment and attachments, with the shortcuts of word
     * processors (⌘B, ⌘I, ⌘U, ⌘K, ⇧⌘7, ⇧⌘8, ⌘], …), undo of formats with
     * the text, rich copy and paste, and input methods composing in the
     * typing attributes. The app keeps $doc from frame to frame:
     *
     *     $doc = new Document(Text::new('Hello'));
     *     // In the view:
     *     $e = RichText::editor($c, $doc);
     *     $e->grow = 1;
     *     if ($c->changed($e)) { $app->save($doc->text()); }
     *
     * A toolbar formats the selection through $doc ($doc->toggleBold(),
     * $doc->setHeading(), …) and shows its formats with $doc->formatting().
     * Context::changed() reports the edits the user made in the editor, not
     * those the app made through $doc. It lays out and draws only the
     * paragraphs in view, so a long document edits as a short one.
     */
    public static function editor(Context $c, Document $doc, ?EditorOptions $options = null): Node
    {
        return View::build($c, $doc, $options, true);
    }

    /** An editor without padding, background, border or corners, for an editor of the app's own look (mygo's EditorBase). */
    public static function editorBase(Context $c, Document $doc, ?EditorOptions $options = null): Node
    {
        return View::build($c, $doc, $options, false);
    }
}
