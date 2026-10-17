<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

use Closure;
use MyPHP\UI\Font;

/**
 * A code editor's options for a frame (mygo's editor options), from a
 * State, or from a diff viewer for each of its sides.
 */
final class Options
{
    public bool $readOnly = false;
    /** The font's size, 13 when 0, and its style bits (Font). */
    public float $fontSize = 0.0;
    public int $fontStyle = Font::MONO;
    public float $lineHeight = 0.0;
    public int $tabSize = 0;
    public int $indent = State::INDENT_AUTO;
    public bool $wrap = false;
    public int $wrapColumn = 0;
    public int $lineNumbers = State::LINE_NUMBERS_ON;
    public int $whitespace = State::WHITESPACE_SELECTION;
    /** @var list<int> */
    public array $wrapGuides = [];
    public bool $indentGuides = true;
    public bool $scrollPastEnd = true;
    public bool $autoClose = true;
    public bool $folding = true;
    public bool $activeLine = true;
    public int $cursorShape = State::CURSOR_BAR;
    public bool $fitContent = false;
    public string $placeholder = '';
    public ?Theme $theme = null;
    /** @var list<Binding> */
    public array $bindings = [];
    /** @var list<GutterMark> */
    public array $gutterMarks = [];
    /** @var list<LineBackground> */
    public array $backgrounds = [];
    public ?Buffer $diffBase = null;
    /** @var ?Closure(CompletionRequest): void */
    public ?Closure $onCompletion = null;
    /** @var ?Closure(HoverRequest): void */
    public ?Closure $onHover = null;
    /** @var ?Closure(int): void */
    public ?Closure $onDefinition = null;
    /** @var ?Closure(int): void */
    public ?Closure $onGutterClick = null;
    /** @var ?Closure(): list<\MyPHP\MenuItem> */
    public ?Closure $contextMenu = null;
    /** @var ?Closure(\MyPHP\UI\InputEvent): bool */
    public ?Closure $onInput = null;
    public bool $noInputMethod = false;
    /** The parts' render functions, null for the editor's own. */
    public ?Closure $renderFindBar = null, $renderGoToLine = null, $renderCompletions = null, $renderHover = null;
    /** Shows a diff in the editor. */
    public ?Decor $decor = null;
    /** Draws no vertical scroll bar. */
    public bool $noScrollbar = false;
    /** How many digits the gutter has room for at least. */
    public int $minDigits = 0;
    /** Scrolls the sides of a split diff together. */
    public ?SharedScroll $sharedScroll = null;
    /** Leaves room for a diff's + and - before the text. */
    public bool $signs = false;
}
