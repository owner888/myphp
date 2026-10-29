<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

use JsonException;

/**
 * The JSON of a Text, as the clipboard's RichText::FORMAT holds it and
 * Text::marshalJSON() writes it (mygo's richtext clipText): paragraphs of
 * a style each, of runs of text and attributes. Attachments keep their
 * names, URLs and sizes; images and drawings are the app's to save.
 *
 * @internal
 */
final class Clip
{
    /** The last text copied in this process, by the name its format carries, so that pasting it keeps its attachments. @var array<string, Text> */
    private static array $copies = [];
    private static int $copyName = 0;
    /** Starts the names, random to the process, so that no other app's copy has one. */
    private static string $process = '';

    /** $t in the clipboard's format. */
    public static function encode(Text $t): string
    {
        self::$process = self::$process ?: bin2hex(random_bytes(13));
        $name = self::$process . '-' . base_convert((string)++self::$copyName, 10, 36);
        self::$copies = [$name => $t];
        $c = self::of($t, false); // IDs are the app's, of paragraphs where they are: a paste makes new ones (Text::replace)
        return json_encode(['copy' => $name] + $c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** The text of the clipboard's format, or null. */
    public static function decode(string $b): ?Text
    {
        try {
            $c = json_decode($b, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($c)) { return null; }
        if (is_string($c['copy'] ?? null) && isset(self::$copies[$c['copy']])) { return self::$copies[$c['copy']]; }
        if (!is_array($c['paragraphs'] ?? null) || $c['paragraphs'] === []) { return null; }
        return self::text($c);
    }

    /** The JSON form of $t, its paragraphs' IDs with $ids. @return array{paragraphs: list<array>} */
    public static function of(Text $t, bool $ids): array
    {
        $paras = [];
        foreach ($t->paragraphs() as $p) {
            $st = $p->style;
            $style = array_filter([
                'heading' => $st->heading, 'list' => $st->list->value, 'checked' => $st->checked, 'indent' => $st->indent,
                'align' => $st->align, 'quote' => $st->quote, 'codeBlock' => $st->codeBlock, 'id' => $ids ? $st->id : '',
            ]);
            $cp = ['style' => (object)$style];
            foreach ($p->runs as $r) {
                $a = $r->attributes;
                $attrs = array_filter([
                    'font' => $a->font, 'size' => $a->size, 'weight' => $a->weight, 'italic' => $a->italic, 'underline' => $a->underline,
                    'strikethrough' => $a->strikethrough,
                    'color' => $a->color !== null && $a->color[1] > 0 ? HtmlWriter::cssColor($a->color) : '',
                    'background' => $a->background !== null && $a->background[1] > 0 ? HtmlWriter::cssColor($a->background) : '',
                    'code' => $a->code, 'link' => $a->link, 'tag' => $a->tag,
                ]);
                $cr = ['text' => $r->text, 'attributes' => (object)$attrs];
                if (($at = $a->attachment) !== null) {
                    [$w, $h] = $at->size();
                    $cr['attachment'] = (object)array_filter(['name' => $at->name, 'url' => $at->url, 'width' => $w, 'height' => $h]);
                }
                $cp['runs'][] = $cr;
            }
            $paras[] = $cp;
        }
        return ['paragraphs' => $paras];
    }

    /** The text of the JSON form $c. */
    public static function text(array $c): Text
    {
        $str = fn (array $m, string $k): string => is_string($m[$k] ?? null) ? $m[$k] : '';
        $num = fn (array $m, string $k): float => is_int($m[$k] ?? null) || is_float($m[$k] ?? null) ? (float)$m[$k] : 0.0;
        $int = fn (array $m, string $k): int => is_int($m[$k] ?? null) ? $m[$k] : 0;
        $bool = fn (array $m, string $k): bool => ($m[$k] ?? false) === true;
        $paras = [];
        foreach ($c['paragraphs'] ?? [] as $cp) {
            if (!is_array($cp)) { continue; }
            $s = is_array($cp['style'] ?? null) ? $cp['style'] : [];
            $p = new Paragraph(new ParagraphStyle(
                heading: $int($s, 'heading'), list: ListKind::tryFrom($int($s, 'list')) ?? ListKind::NoList, checked: $bool($s, 'checked'),
                indent: $int($s, 'indent'), align: $int($s, 'align'), quote: $bool($s, 'quote'), codeBlock: $bool($s, 'codeBlock'), id: $str($s, 'id'),
            ));
            foreach (is_array($cp['runs'] ?? null) ? $cp['runs'] : [] as $cr) {
                if (!is_array($cr)) { continue; }
                $ca = is_array($cr['attributes'] ?? null) ? $cr['attributes'] : [];
                $a = new Attributes(
                    font: $str($ca, 'font'), size: $num($ca, 'size'), weight: $int($ca, 'weight'), italic: $bool($ca, 'italic'),
                    underline: $bool($ca, 'underline'), strikethrough: $bool($ca, 'strikethrough'),
                    color: HtmlWriter::parseCSSColor($str($ca, 'color')), background: HtmlWriter::parseCSSColor($str($ca, 'background')),
                    code: $bool($ca, 'code'), link: HtmlWriter::safeLink($str($ca, 'link')), tag: $str($ca, 'tag'),
                );
                if (is_array($at = $cr['attachment'] ?? null)) {
                    $a->attachment = new Attachment(width: HtmlBuilder::side($num($at, 'width')), height: HtmlBuilder::side($num($at, 'height')),
                        name: $str($at, 'name'), url: $str($at, 'url'));
                }
                $p->runs[] = new Run($str($cr, 'text'), $a);
            }
            $paras[] = $p;
        }
        return $paras === [] ? new Text : Text::of(...$paras);
    }
}
