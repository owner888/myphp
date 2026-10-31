<?php
declare(strict_types=1);

namespace MyPHP\UI\Markdown;

use MyPHP\UI\{Context, Direction, Node, Role, SVG};

/** A code block as Options::$codeBlock and Options::$code build it (mygo's CodeBlockView). */
final class CodeBlockView
{
    private static ?SVG $copyIcon = null, $checkIcon = null;

    /** @internal */
    public function __construct(
        /** The language its fence names, as "go", and the whole info string after the fence. */
        public readonly string $language,
        public readonly string $info,
        /** A block still being written, whose closing fence has not come: heavy renderers, as of diagrams, wait for it to close. */
        public readonly bool $open,
        /** The block's in the Document. */
        public readonly int $index,
        private ViewState $st,
    ) {}

    /** The block's code, its lines ending with newlines. */
    public function code(): string { return $this->st->doc->lines($this->index); }

    /** @internal */
    public function state(): ViewState { return $this->st; }

    /** Builds a button copying the block's code, which shows that it did for two seconds, and waits for a block being written to close (mygo's CopyButton). */
    public function copyButton(Context $c): Node
    {
        $th = $this->st->th;
        $cs = $this->st->codeState($this->index);
        $now = $c->runtime?->now ?? hrtime(true);
        $copied = $cs->copiedAt !== 0 && $now - $cs->copiedAt < 2_000_000_000;
        $tx = $c->texts();
        self::$copyIcon ??= SVG::parse('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3"><rect x="5.5" y="5.5" width="8" height="8" rx="1.6"/><path d="M10.5 3.6V3.2A1.7 1.7 0 0 0 8.8 1.5H4.2A1.7 1.7 0 0 0 2.5 3.2v4.6a1.7 1.7 0 0 0 1.7 1.7h.4"/></svg>');
        self::$checkIcon ??= SVG::parse('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 8.5l3 3 6-7"/></svg>');
        $btn = $c->buttonBase()->padding($th->em(0.2), $th->em(0.45))->radius(5)->label($tx->get('copyCode'));
        $btn->gap = $th->em(0.3);
        $btn->disabled = $this->open;
        if ($c->hovered($btn) && !$this->open) {
            $btn->background($th->text[0], $th->text[1] * 0.08);
        }
        [$icon, $label] = $copied ? [self::$checkIcon, $tx->get('copied')] : [self::$copyIcon, $tx->get('copy')];
        $size = $th->em(0.95);
        $c->icon($icon, $size, $th->muted[0]);
        $c->text($label)->fontSize($th->em(0.8))->textColor($th->muted[0], $th->muted[1]);
        $c->end();
        if (!$this->open && $c->clicked($btn)) {
            ($c->runtime->writeClipboard)($this->code());
            $cs->copiedAt = $now;
            $c->runtime->after(2.0);
        }
        return $btn;
    }

    /** Builds the block's code, highlighted, selectable with the view's text, scrolling sideways unless Options::$wrapCode (mygo's Body). */
    public function body(Context $c): Node
    {
        $st = $this->st;
        $th = $st->th;
        $cs = $st->codeState($this->index);
        $st->highlight($cs, $this->index);
        $pad = $th->em($th->codeBlockPadding);
        $outer = $st->o->wrapCode ? $c->column() : $c->row()->alignItems(Node::START)->scroll($cs->scroll, true, false); // sideways (mygo's ScrollHorizontal)
        // Code reads left to right in a right-to-left interface too, as in editors (mygo's).
        $outer->direction(Direction::LeftToRight)->alignSelf(Node::STRETCH)->font($th->codeFont)->fontSize($th->em($th->codeSize))->lineHeight($th->codeLineHeight);
        $col = $c->column()->padding($th->em(0.25), $pad, $pad, $pad);
        if (!$st->o->wrapCode) {
            $col->shrink(0);
        }
        foreach ($cs->chunks as $k => $ch) {
            $el = $st->o->wrapCode ? $c->richText($ch['spans'], wrap: true) : $c->richText($ch['spans'])->noWrap();
            $st->note($el->id, $this->index, $k * CodeState::CHUNK_LINES);
        }
        $c->end();
        $c->end();
        return $outer;
    }
}
