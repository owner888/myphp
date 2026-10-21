<?php
declare(strict_types=1);

namespace MyPHP\UI\Markdown;

use MyPHP\UI\Editor\{Languages, Tokenizer};
use MyPHP\UI\ScrollState;

/**
 * What a view keeps of a code block (mygo's codeState): the tokens of its
 * lines and the spans of its chunks of lines, to highlight only what
 * changed; when it was copied; its sideways scroll.
 */
final class CodeState
{
    /** Lines laid out as one text, so that a block growing as it streams lays out only its last chunk anew (mygo's chunkLines). */
    public const CHUNK_LINES = 32;

    public ?Tokenizer $tok = null;
    /** @var list<string> */
    public array $lines = [];
    /** @var list<int> the state each line ends in */
    public array $ends = [];
    /** @var list<list<array{0: int, 1: int}>> */
    public array $toks = [];
    /** @var list<array{spans: list<\MyPHP\UI\Span>, n: int, ok: bool}> */
    public array $chunks = [];
    /** When its code was copied, in hrtime nanoseconds; 0 for never. */
    public int $copiedAt = 0;
    public ScrollState $scroll;

    public function __construct(public readonly string $lang)
    {
        $l = $lang !== '' ? Languages::named($lang) : null;
        $this->tok = $l?->tokenizer;
        $this->scroll = new ScrollState();
    }
}
