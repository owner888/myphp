<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

use MyPHP\UI\Theme as UITheme;

/**
 * Colors editors and diffs (mygo's editor.Theme). A color is [0xRRGGBB,
 * alpha]; colors left null take those of defaults() for the window's
 * theme, which follow it.
 */
final class Theme
{
    /** @var array<string, ?array{0: int, 1: float}> */
    public array $c = [];
    /** @var array<int, array{color: ?array{0: int, 1: float}, italic: bool, bold: bool}> the styles of the kinds of tokens; a null color is the text's */
    public array $tokens = [];

    /** The names of the colors: the editor's background and text, the gutter's numbers (of the lines with a caret too), the lines with a caret, selected text with and without the focus, carets, the brackets around the caret, the find bar's matches and the one chosen, other occurrences of the selected text, indent guides (the one around the caret), wrap guides, whitespace shown, the severities of diagnostics, and the colors of changes. */
    public const NAMES = ['background', 'text', 'lineNumber', 'activeLineNumber', 'activeLine', 'selection', 'inactiveSelection', 'cursor', 'matchingBracket',
        'match', 'currentMatch', 'occurrence', 'indentGuide', 'activeIndentGuide', 'wrapGuide', 'whitespace', 'error', 'warning', 'info', 'hint', 'added', 'deleted', 'modified'];

    /** The code theme for a window's theme: its background and text, with GitHub's colors for code (mygo's DefaultTheme). */
    public static function defaults(UITheme $t): self
    {
        $ct = new self;
        $ct->c = [
            'background' => [$t->background, 1.0], 'text' => [$t->text, 1.0], 'lineNumber' => [$t->textMuted, 0.75], 'selection' => [$t->selection, $t->selectionAlpha],
            'cursor' => [$t->accent, 1.0], 'wrapGuide' => [$t->border, 0.6], 'indentGuide' => [$t->border, 0.55], 'whitespace' => [$t->textMuted, 0.45],
            'activeLineNumber' => [$t->text, 1.0], 'inactiveSelection' => [$t->selection, $t->selectionAlpha * 0.55], 'activeIndentGuide' => [$t->textMuted, 0.6],
        ];
        $hex = fn(string $h) => [hexdec(ltrim($h, '#')), 1.0];
        if ($t->dark) {
            $ct->c += ['activeLine' => [0xffffff, 0.045], 'matchingBracket' => [0xffffff, 0.35], 'match' => [0xffd23c, 0.22], 'currentMatch' => [0xffa028, 0.5], 'occurrence' => [0xffffff, 0.1],
                'error' => $hex('#f85149'), 'warning' => $hex('#d29922'), 'info' => $hex('#58a6ff'), 'hint' => $hex('#8b949e'),
                'added' => $hex('#5ecc71'), 'deleted' => $hex('#ff6762'), 'modified' => $hex('#69b1ff')];
            $tok = [Token::KEYWORD => '#ff7b72', Token::OPERATOR => '#ff7b72', Token::TYPE => '#ffa657', Token::FUNCTION => '#d2a8ff', Token::META => '#d2a8ff',
                Token::PROPERTY => '#79c0ff', Token::CONSTANT => '#79c0ff', Token::NUMBER => '#79c0ff', Token::ATTRIBUTE => '#79c0ff', Token::STRING => '#a5d6ff',
                Token::ESCAPE => '#79c0ff', Token::REGEXP => '#7ee787', Token::TAG => '#7ee787', Token::COMMENT => '#8b949e', Token::HEADING => '#79c0ff',
                Token::LINK => '#a5d6ff', Token::CODE => '#a5d6ff', Token::INSERTED => '#7ee787', Token::DELETED => '#ffa198', Token::INVALID => '#f85149'];
        } else {
            $ct->c += ['activeLine' => [0x000000, 0.035], 'matchingBracket' => [0x000000, 0.3], 'match' => [0xffc800, 0.3], 'currentMatch' => [0xff8c00, 0.45], 'occurrence' => [0x000000, 0.07],
                'error' => $hex('#d1242f'), 'warning' => $hex('#9a6700'), 'info' => $hex('#0969da'), 'hint' => $hex('#6e7781'),
                'added' => $hex('#0dbe4e'), 'deleted' => $hex('#ff2e3f'), 'modified' => $hex('#009fff')];
            $tok = [Token::KEYWORD => '#cf222e', Token::OPERATOR => '#cf222e', Token::TYPE => '#953800', Token::FUNCTION => '#8250df', Token::META => '#8250df',
                Token::PROPERTY => '#0550ae', Token::CONSTANT => '#0550ae', Token::NUMBER => '#0550ae', Token::ATTRIBUTE => '#0550ae', Token::STRING => '#0a3069',
                Token::ESCAPE => '#0550ae', Token::REGEXP => '#116329', Token::TAG => '#116329', Token::COMMENT => '#6e7781', Token::HEADING => '#0550ae',
                Token::LINK => '#0a3069', Token::CODE => '#0a3069', Token::INSERTED => '#116329', Token::DELETED => '#82071e', Token::INVALID => '#d1242f'];
        }
        for ($k = 0; $k < Token::KINDS; $k++) { $ct->tokens[$k] = ['color' => isset($tok[$k]) ? $hex($tok[$k]) : null, 'italic' => false, 'bold' => false]; }
        $ct->tokens[Token::HEADING]['bold'] = true;
        $ct->tokens[Token::STRONG]['bold'] = true;
        $ct->tokens[Token::EMPHASIS]['italic'] = true;
        return $ct;
    }

    /** This theme with its null colors filled from the defaults for $t; the defaults when $ct is null. */
    public static function resolve(?self $ct, UITheme $t): self
    {
        $def = self::defaults($t);
        if ($ct === null) { return $def; }
        $out = clone $ct;
        foreach (self::NAMES as $n) { $out->c[$n] ??= $def->c[$n]; }
        for ($k = 0; $k < Token::KINDS; $k++) {
            $out->tokens[$k] ??= $def->tokens[$k];
            $out->tokens[$k]['color'] ??= $def->tokens[$k]['color'];
        }
        return $out;
    }

    /** The color of a kind of token: its style's, else the text's. @return array{0: int, 1: float} */
    public function tokenColor(int $k): array
    {
        $c = $this->tokens[$k]['color'] ?? null;
        return $c !== null && $c[1] > 0 ? $c : $this->c['text'];
    }

    /** The color of diagnostics of a severity (Diagnostic). @return array{0: int, 1: float} */
    public function severityColor(int $s): array
    {
        return match ($s) {
            Diagnostic::WARNING => $this->c['warning'],
            Diagnostic::INFO => $this->c['info'],
            Diagnostic::HINT => $this->c['hint'],
            default => $this->c['error'],
        };
    }

    /** $a mixed toward $b by $t in Oklab, as people see the colors between them (mygo's mixLab): [rgb, alpha]. */
    public static function mixLab(array $a, array $b, float $t): array
    {
        $dec = function (float $v): float { $x = abs($v); return $x <= 0.04045 ? $v / 12.92 : ($v < 0 ? -1 : 1) * (($x + 0.055) / 1.055) ** 2.4; };
        $enc = function (float $v): float { $x = abs($v); return $x <= 0.0031308 ? $v * 12.92 : ($v < 0 ? -1 : 1) * (1.055 * $x ** (1 / 2.4) - 0.055); };
        $cbrt = fn(float $v) => $v < 0 ? -((-$v) ** (1 / 3)) : $v ** (1 / 3);
        $lab = function (int $rgb) use ($dec, $cbrt): array {
            $r = $dec((($rgb >> 16) & 255) / 255); $g = $dec((($rgb >> 8) & 255) / 255); $b = $dec(($rgb & 255) / 255);
            $l = $cbrt(0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b);
            $m = $cbrt(0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b);
            $q = $cbrt(0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b);
            return [0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $q, 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $q, 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $q];
        };
        [$l1, $a1, $b1] = $lab($a[0]); [$l2, $a2, $b2] = $lab($b[0]);
        $L = $l1 + ($l2 - $l1) * $t; $A = $a1 + ($a2 - $a1) * $t; $B = $b1 + ($b2 - $b1) * $t;
        $lc = ($L + 0.3963377774 * $A + 0.2158037573 * $B) ** 3; $mc = ($L - 0.1055613458 * $A - 0.0638541728 * $B) ** 3; $sc = ($L - 0.0894841775 * $A - 1.2914855480 * $B) ** 3;
        $ch = fn(float $v) => (int)($enc(max(0.0, min($v, 1.0))) * 255 + 0.5);
        $r = $ch(4.0767416621 * $lc - 3.3077115913 * $mc + 0.2309699292 * $sc);
        $g = $ch(-1.2684380046 * $lc + 2.6097574011 * $mc - 0.3413193965 * $sc);
        $bb = $ch(-0.0041960863 * $lc - 0.7034186147 * $mc + 1.7076147010 * $sc);
        return [$r << 16 | $g << 8 | $bb, $a[1] * (1 - $t) + $b[1] * $t];
    }

    /** Whether a background is dark. */
    public static function isDark(array $c): bool
    {
        return 0.2126 * (($c[0] >> 16) & 255) + 0.7152 * (($c[0] >> 8) & 255) + 0.0722 * ($c[0] & 255) < 128;
    }

    /**
     * The tints of changes over the background (mygo's diffColors): the
     * background of changed lines, of their gutters, the words changed in
     * them, and the bars and numbers.
     * @return array<string, array{0: int, 1: float}> addedLine, deletedLine, addedGutter, deletedGutter, addedWord, deletedWord, added, deleted, modified
     */
    public function diff(bool $dark): array
    {
        [$line, $gutter, $word] = $dark ? [0.2, 0.15, 0.2] : [0.12, 0.09, 0.15];
        $bg = $this->c['background'][1] == 0 ? [0xffffff, 1.0] : $this->c['background'];
        $added = $this->c['added']; $deleted = $this->c['deleted'];
        return [
            'addedLine' => self::mixLab($bg, $added, $line), 'deletedLine' => self::mixLab($bg, $deleted, $line),
            'addedGutter' => self::mixLab($bg, $added, $gutter), 'deletedGutter' => self::mixLab($bg, $deleted, $gutter),
            'addedWord' => [$added[0], $added[1] * $word], 'deletedWord' => [$deleted[0], $deleted[1] * $word],
            'added' => $added, 'deleted' => $deleted, 'modified' => $this->c['modified'],
        ];
    }
}
