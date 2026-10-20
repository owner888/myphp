<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

use MyPHP\UI\{Context, Node, Span};

/** What an editor shows of the text under the pointer, as State::$renderHover builds it (mygo's HoverCard): the diagnostics there, and what the app gave showHover. */
final class HoverCard
{
    public function __construct(private Context $c, private Editor $ed) {}

    /** The diagnostics of the text hovered. @return list<Diagnostic> */
    public function diagnostics(): array { return $this->ed->hover->diags ?? []; }

    /** The text showHover gave, and content() builds what its function builds, when it gave one. */
    public function text(): string { return $this->ed->hover->text; }

    public function content(): void
    {
        if (($b = $this->ed->hover->build) !== null) { $b($this->c); }
    }

    /** The text hovered, from start to end. @return array{0: int, 1: int} */
    public function range(): array { return [$this->ed->hover->start, $this->ed->hover->end]; }

    /** The editor's font size and style bits, and its colors. */
    public function fontSize(): float { return $this->ed->view->size; }
    public function fontStyle(): int { return $this->ed->view->style; }
    public function theme(): Theme { return $this->ed->theme; }

    /** Builds the editor's own hover into $panel, as Zed's (mygo's DefaultHover): the diagnostics with bars of their severity's color, then the text in the editor's font and what the app builds. */
    public static function default(Context $c, Node $panel, HoverCard $h): void
    {
        $t = $c->theme;
        $ct = $h->theme();
        $panel->dir = Node::COLUMN; $panel->pad = $t->space(2); $panel->gap = $t->space(1.5);
        $panel->color = $t->surface; $panel->round($t->radius)->border($t->border)->shadow(4, 16, 0.2);
        foreach ($h->diagnostics() as $d) {
            $c->row(0, $t->space(1.5));
              $sc = $ct->severityColor($d->severity);
              $c->box(3, 0, $sc[0])->round(1.5)->alignSelf(Node::STRETCH);
              $c->richText([new Span($d->message . ($d->source !== '' ? ' (' . $d->source . ')' : ''))], $t->fontSize - 1, null, $t->space(140));
            $c->end();
        }
        if (($text = $h->text()) !== '') { $c->text($text, $h->fontSize(), $ct->c['text'][0], $h->fontStyle()); }
        $h->content();
    }
}
