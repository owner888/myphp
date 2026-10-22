<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Updater\Native;

use MyPHP\UI\{Context, Font, Node, ScrollState, Theme};

/**
 * Release notes in native UI, in the manner of the web page's (mygo's
 * native/notes.go): their text is selectable, and links open in the
 * browser. Like the page's notes, whose dir is auto, they are laid out
 * from the right when the language they are written in is written from
 * right to left, whatever the window's.
 *
 * @internal
 */
final class Notes
{
    /** The sizes of headings of levels 1, 2 and the others, relative to the text. */
    private const HEADING_SIZES = [16 / 13, 14 / 13, 1];

    private function __construct(private readonly Theme $t, private readonly bool $rtl) {}

    /** Builds $blocks, Markdown::parse's. @param list<array<string, mixed>> $blocks */
    public static function build(Context $c, Theme $t, array $blocks): void
    {
        $n = new self($t, self::rightToLeft($blocks));
        $c->column();
        $n->blocks($c, $blocks, false, 0);
        $c->end();
    }

    /** Whether the first strongly directional letter of $blocks is of a script written from right to left. @param list<array<string, mixed>> $blocks */
    public static function rightToLeft(array $blocks): bool
    {
        $found = null;
        $text = function (string $s) use (&$found): void {
            if ($found === null && preg_match('/\p{L}/u', $s, $m) === 1) {
                $found = preg_match('/[\p{Hebrew}\p{Arabic}\p{Syriac}\p{Thaana}\p{Nko}\p{Samaritan}\p{Mandaic}\p{Adlam}]/u', $m[0]) === 1;
            }
        };
        $inlines = function (array $ins) use (&$inlines, $text): void {
            foreach ($ins as $in) {
                $text($in['text'] ?? '');
                $inlines($in['children'] ?? []);
            }
        };
        $walk = function (array $bs) use (&$walk, $inlines, $text): void {
            foreach ($bs as $b) {
                $inlines($b['inlines'] ?? []);
                $text($b['text'] ?? '');
                foreach ($b['items'] ?? [] as $item) {
                    $walk($item);
                }
                $walk($b['blocks'] ?? []);
            }
        };
        $walk($blocks);
        return $found ?? false;
    }

    /** Builds blocks, apart by the room between paragraphs, or by none in the items of a list ($tight); $depth counts the lists around them. @param list<array<string, mixed>> $blocks */
    private function blocks(Context $c, array $blocks, bool $tight, int $depth): void
    {
        foreach ($blocks as $i => $b) {
            $gap = match (true) {
                $i === 0 || $tight => 0,
                $b['kind'] === 'heading' => 12,
                $blocks[$i - 1]['kind'] === 'heading' => 4,
                default => 8,
            };
            $this->block($c, $b, $depth, $gap);
        }
    }

    /** @param array<string, mixed> $b */
    private function block(Context $c, array $b, int $depth, float $gap): void
    {
        $t = $this->t;
        switch ($b['kind']) {
            case 'heading':
                $size = self::HEADING_SIZES[min($b['level'], count(self::HEADING_SIZES)) - 1];
                $this->paragraph($c, $b['inlines'], $t->fontSize * $size, true)->margin($gap, 0, 0, 0);
                return;
            case 'list':
                $c->column()->margin($gap, 0, 0, 0);
                foreach ($b['items'] as $i => $item) {
                    $row = $c->row()->align(Node::START);
                    if ($this->rtl) {
                        $row->reverse();
                    }
                    // The marker ends next to the item.
                    $marker = $c->text(!empty($b['ordered']) ? ($i + 1) . '.' : self::bullet($depth))->size(14, 0)->shrink(0);
                    if ($this->rtl) {
                        $marker->margin(0, 0, 0, 6);
                    } else {
                        $marker->textAlign(Node::END)->margin(0, 6, 0, 0);
                    }
                    $c->column(0, 0, 1);
                    $this->blocks($c, $item, true, $depth + 1);
                    $c->end();
                    $c->end();
                }
                $c->end();
                return;
            case 'quote':
                $quote = $c->column()->margin($gap, 0, 0, 0)->borderColor($t->border)->textColor($t->textMuted);
                if ($this->rtl) {
                    $quote->borderWidth(0, 3, 0, 0)->padding(0, 10, 0, 0);
                } else {
                    $quote->borderWidth(0, 0, 0, 3)->padding(0, 0, 0, 10);
                }
                $this->blocks($c, $b['blocks'], false, $depth);
                $c->end();
                return;
            case 'code':
                $box = $c->column()->margin($gap, 0, 0, 0)->background(...self::track($t))->round(3);
                $box->scroll($c->runtime?->local($box->id, fn() => new ScrollState) ?? new ScrollState, horizontal: true, vertical: false);
                $c->column()->padding(6, 8);
                $c->text($b['text'], $t->fontSize * 12 / 13, style: Font::MONO)->noWrap()->selectable();
                $c->end();
                $c->end();
                return;
            case 'rule':
                $c->divider()->margin($gap, 0, 0, 0);
                return;
        }
        $this->paragraph($c, $b['inlines'], null, false)->margin($gap, 0, 0, 0);
    }

    /** The text of a paragraph or heading, which the user may select. @param list<array<string, mixed>> $inlines */
    private function paragraph(Context $c, array $inlines, ?float $size, bool $bold): Node
    {
        $p = $c->richText([], $size, wrap: true);
        if ($bold) {
            $p->bold();
        }
        return $p->children(fn() => $this->inlines($c, $inlines))->selectable();
    }

    /** The marker of the items of a list in $depth lists, as browsers draw them. */
    private static function bullet(int $depth): string
    {
        return match ($depth) { 0 => '•', 1 => '◦', default => '▪' };
    }

    /** Builds the text of a paragraph inside it: links open in the browser. @param list<array<string, mixed>> $inlines */
    private function inlines(Context $c, array $inlines): void
    {
        $t = $this->t;
        foreach ($inlines as $in) {
            switch ($in['kind']) {
                case 'text':
                    $c->text($in['text']);
                    break;
                case 'code':
                    [$rgb, $alpha] = self::track($t);
                    $c->text($in['text'], $t->fontSize * 12 / 13, style: Font::MONO)->textBackground(Theme::mix(self::panel($t), $rgb, $alpha));
                    break;
                case 'em':
                    $c->richText([])->italic()->children(fn() => $this->inlines($c, $in['children']));
                    break;
                case 'strong':
                    $c->richText([])->bold()->children(fn() => $this->inlines($c, $in['children']));
                    break;
                case 'link':
                    $c->link(self::plain($in['children']), $in['url']);
                    break;
            }
        }
    }

    /** The text of inlines, without their styles. @param list<array<string, mixed>> $inlines */
    private static function plain(array $inlines): string
    {
        $s = '';
        foreach ($inlines as $in) {
            $s .= $in['kind'] === 'text' || $in['kind'] === 'code' ? $in['text'] : self::plain($in['children']);
        }
        return $s;
    }

    /** The background of the release notes. */
    public static function panel(Theme $t): int
    {
        return $t->dark ? 0x141414 : 0xffffff;
    }

    /** The background of code, and its opacity. @return array{0: int, 1: float} */
    public static function track(Theme $t): array
    {
        return $t->dark ? [0xffffff, 0.12] : [0x000000, 0.1];
    }
}
