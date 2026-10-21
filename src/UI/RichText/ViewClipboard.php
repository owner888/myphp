<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

use MyPHP\Transfer\{Data, Format, FormatUnavailable, Item, Representation};

/**
 * Copying and pasting rich text (mygo's richtext clipboard.go): the
 * editor's own format, HTML and plain text out; the richest of those,
 * RTF, Markdown or plain text in.
 *
 * @internal part of View
 */
trait ViewClipboard
{
    /** $t as the clipboard takes it: in the format of the editor, as HTML and as plain text (mygo's clipboardData). */
    public static function clipboardData(Text $t): Data
    {
        return new Data(new Item(
            Representation::bytes(RichText::FORMAT, Clip::encode($t)),
            Representation::bytes(Format::HTML, $t->html()),
            Representation::bytes(Format::TEXT, $t->plainText()),
        ));
    }

    /** Puts the selection on the clipboard. */
    private function copySelection(): void
    {
        [$a, $z] = $this->sel();
        if ($a === $z || $this->services === null) { return; }
        ($this->services->writeClipboardData)(self::clipboardData($this->shown->slice($a, $z)));
    }

    /** Replaces the selection with what the clipboard holds: the richest format the editor reads, or its plain text in the typing attributes when matching the style. */
    private function paste(bool $matchStyle): void
    {
        if ($this->services === null) { return; }
        $t = null;
        if (!$matchStyle) {
            $d = ($this->services->readClipboardData)(RichText::FORMAT, Format::HTML, RichText::RTF, Format::MARKDOWN, Format::TEXT);
            $read = function (string $f) use ($d): string {
                try { return $d->read($f); } catch (FormatUnavailable) { return ''; }
            };
            if (($b = $read(RichText::FORMAT)) !== '') { $t = Clip::decode($b); }
            if ($t === null && ($b = $read(Format::HTML)) !== '') { $t = RichText::parseHTML($b); }
            // TextEdit, Pages and WordPad copy RTF without HTML.
            if ($t === null && ($b = $read(RichText::RTF)) !== '') { $t = RichText::parseRTF($b); }
            // Markdown apps and Markdown views copy it.
            if ($t === null && ($b = $read(Format::MARKDOWN)) !== '') { $t = RichText::parseMarkdown($b); }
            if ($t === null && ($b = $read(Format::TEXT)) !== '') {
                $this->insert($b);
                return;
            }
        }
        if ($t === null) {
            $s = ($this->services->readClipboard)();
            if ($s !== '') { $this->insert($s); }
            return;
        }
        $this->record(false);
        $d = $this->doc;
        $this->sync();
        [$a, $z] = $this->sel(); // as the sync moved it
        $this->publish();
        $this->editInStep(fn(Edit $e) => $e->replace($a, $z, $t));
        $caret = $a + $t->len();
        $this->selAnchor = $this->caret = $caret;
        $d->anchor = $d->caret = $caret;
        $this->mode = self::NEW_STEP; $this->coalesce = false;
        $this->reveal = true;
    }
}
