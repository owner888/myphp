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
     * @param array<string, mixed> $args
     */
    public function __construct(public readonly string $text, public readonly bool $verbatim = false, public readonly array $args = []) {}

    protected function build(Context $c, Env $env): ?Node
    {
        $text = $this->verbatim ? $this->text : Localized::string($this->text, $env, $this->args);
        $spans = $this->verbatim ? null : self::markdown($text);
        $n = $spans === null ? $c->text($text) : $c->richText($spans, wrap: true);
        self::style($n, $env);
        return $n;
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

    /** Gives text node $n the environment's font, weight, slant, color, alignment and line limit. @internal */
    public static function style(Node $n, Env $env): void
    {
        $env->font?->apply($n);
        if ($env->weight !== null) { $n->fontWeight($env->weight->value); }
        if ($env->italic) { $n->italic(); }
        if ($env->mono) { $n->font('monospace'); }
        if ($env->color !== null) { $n->textColor($env->color->rgb($env)); }
        if ($env->textAlign !== null) { $n->textAlign($env->textAlign->align()); }
        if ($env->lineLimit !== null) { $env->lineLimit === 1 ? $n->singleLine() : $n->maxLines($env->lineLimit); }
    }
}
