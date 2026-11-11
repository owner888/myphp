<?php
declare(strict_types=1);

namespace MyPHP\UI\Declarative;

use MyPHP\UI\{Context, Font, Node, Span};

/** Text, as SwiftUI's Text: in the environment's font and color, wrapping at the room it is given. */
final class Text extends View
{
    /**
     * $text the key of its translation in the app's catalogs, $args its
     * placeholders' values (Localized); $verbatim: shown as it is, neither
     * translated nor read as Markdown.
     * $parts: Texts shown one after the other, each in its own style (Text + Text).
     * @param array<string, mixed> $args
     * @param list<Text> $parts
     */
    public function __construct(public readonly string $text, public readonly bool $verbatim = false, public readonly array $args = [], public readonly array $parts = []) {}

    protected function build(Context $c, Env $env): ?Node
    {
        if ($this->parts !== []) {
            $n = $c->richText($this->spans($env), wrap: true);
            self::style($n, $env);
            return $n;
        }
        $text = $this->verbatim ? $this->text : Localized::string($this->text, $env, $this->args);
        $spans = $this->verbatim ? null : self::markdown($text);
        $n = $spans === null ? $c->text($text) : $c->richText($spans, wrap: true);
        self::style($n, $env);
        self::onAccent($c, $n, $env);
        return $n;
    }

    /** The spans of the parts, each in its own style over $env's: its color, weight, slant, family and size, and a background highlighting it. @return list<Span> */
    private function spans(Env $env): array
    {
        $out = [];
        foreach ($this->parts as $p) {
            if (!$p instanceof self) { throw new \InvalidArgumentException('Text([…]) joins Texts, not ' . get_debug_type($p)); }
            $e = $env->with($p->sets);
            $bg = null;
            $bgAlpha = null;
            foreach ($p->mods as [$name, $a]) {
                if ($name === 'background') { $bg = $a[0]->rgb($e); $bgAlpha = $a[0]->alpha < 1.0 ? $a[0]->alpha : null; } else { throw new \LogicException("a Text joined in Text([…]) takes styles and background(), not $name()"); }
            }
            $weight = $e->weight?->value ?? $e->font?->weight?->value;
            $style = ($weight !== null && $weight >= 700 ? Font::BOLD : 0) | ($e->italic ? Font::ITALIC : 0) | ($e->mono || $e->font?->mono ? Font::MONO : 0);
            foreach ($p->parts !== [] ? $p->spans($e) : [new Span($p->verbatim ? $p->text : Localized::string($p->text, $e, $p->args))] as $s) {
                $s->style |= $style;
                $s->color ??= $e->color?->rgb($e);
                if ($e->color !== null && $e->color->alpha < 1.0) { $s->alpha ??= $e->color->alpha; }
                $s->background ??= $bg;
                if ($bgAlpha !== null) { $s->backgroundAlpha ??= $bgAlpha; }
                $s->underline = $s->underline || $e->underline;
                $s->strikethrough = $s->strikethrough || $e->strikethrough;
                $s->size ??= $e->font?->size;
                if ($weight !== null && $weight !== 400 && $weight !== 700) { $s->weight ??= $weight; }
                $out[] = $s;
            }
        }
        return $out;
    }

    /**
     * The spans of $text's inline Markdown, as SwiftUI's Text reads it:
     * **bold**, *italic*, `code`, ~~struck~~; null when it has none.
     * @return list<Span>|null
     */
    public static function markdown(string $text): ?array
    {
        if (!preg_match('/\*\*.+?\*\*|\*[^*\s][^*]*\*|`[^`]+`|~~.+?~~/u', $text)) { return null; }
        $spans = [];
        $at = 0;
        preg_match_all('/\*\*(.+?)\*\*|\*([^*\s][^*]*)\*|`([^`]+)`|~~(.+?)~~/u', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $g) {
            if ($g[0][1] > $at) { $spans[] = new Span(substr($text, $at, $g[0][1] - $at)); }
            $spans[] = match (true) {
                ($g[1][1] ?? -1) >= 0 => new Span($g[1][0], style: Font::BOLD),
                ($g[2][1] ?? -1) >= 0 => new Span($g[2][0], style: Font::ITALIC),
                ($g[3][1] ?? -1) >= 0 => new Span($g[3][0], style: Font::MONO),
                default => new Span($g[4][0], strikethrough: true),
            };
            $at = $g[0][1] + strlen($g[0][0]);
        }
        if ($at < strlen($text)) { $spans[] = new Span(substr($text, $at)); }
        return $spans;
    }

    /** In a chosen row, filled with the accent, primary and secondary are the accent's text color, secondary fainter, as SwiftUI's hierarchical styles in a selected row. */
    private static function onAccent(Context $c, Node $n, Env $env): void
    {
        $s = $env->color?->system;
        if (($s !== \Color::secondary && $s !== \Color::primary) || $env->color->value !== 0 || ($in = $c->inheritedText()->color) !== $env->theme->accentText || $in === $env->theme->text) { return; }
        $n->textColor($in, $s === \Color::secondary ? 0.75 : 1.0);
    }

    /** Gives text node $n the environment's font, weight, slant, color, alignment and line limit. @internal */
    public static function style(Node $n, Env $env): void
    {
        $env->font?->apply($n);
        if ($env->weight !== null) { $n->fontWeight($env->weight->value); }
        if ($env->italic) { $n->italic(); }
        if ($env->underline) { $n->underline(); }
        if ($env->strikethrough) { $n->strikethrough(); }
        if ($env->mono) { $n->font('monospace'); }
        if ($env->color !== null) { $n->textColor($env->color->rgb($env), $env->color->alpha); }
        if ($env->textAlign !== null) { $n->textAlign($env->textAlign->align()); }
        if ($env->lineLimit !== null) { $env->lineLimit === 1 ? $n->singleLine() : $n->maxLines($env->lineLimit); }
        if ($env->redacted === \RedactionReasons::placeholder) {
            $bg = $env->theme->border;
            $n->textColor($bg)->background($bg)->radius(4);
        }
    }
}
