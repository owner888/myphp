<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

use Closure;
use MyPHP\App;
use MyPHP\UI\{Context, Font, Node, Theme as UITheme};

/**
 * A code editor's state, kept by the app across frames (mygo's
 * editor.State): the buffer it edits, its options, and what the editor
 * keeps of its own, as its selections, folds and scroll position. Set the
 * options before building the editor (view()); the defaults are those of
 * Zed.
 */
final class State
{
    /** What indenting inserts: as the language's files usually do (Go's tabs), spaces or tabs. */
    public const INDENT_AUTO = 0, INDENT_SPACES = 1, INDENT_TABS = 2;
    /** How the gutter numbers lines. */
    public const LINE_NUMBERS_ON = 0, LINE_NUMBERS_RELATIVE = 1, LINE_NUMBERS_OFF = 2;
    /** Where spaces and tabs show: within selections, nowhere, everywhere, at the ends of lines, or but single spaces between words. */
    public const WHITESPACE_SELECTION = 0, WHITESPACE_NONE = 1, WHITESPACE_ALL = 2, WHITESPACE_TRAILING = 3, WHITESPACE_BOUNDARY = 4;
    /**
     * The shape of the carets, as Zed's CursorShape: a bar before the
     * character at the caret; a block covering the character after it, a
     * space's width at the end of a line, translucent so that the
     * character shows, outlined without the keyboard focus, as Vim's and
     * Zed's; or an underline under the character after it.
     */
    public const CURSOR_BAR = 0, CURSOR_BLOCK = 1, CURSOR_UNDERLINE = 2;

    /** The text the editor edits; one is made when null. */
    public ?Buffer $buffer = null;
    /** The font's size, 13 when 0, and its style bits: the system's monospaced font by default. */
    public float $fontSize = 0.0;
    public int $fontStyle = Font::MONO;
    /** The height of lines as a multiple of the font's size, 1.5 when 0. */
    public float $lineHeight = 0.0;
    /** How many columns a tab and a level of indentation take: the language's, else 4, when 0. */
    public int $tabSize = 0;
    public int $indent = self::INDENT_AUTO;
    /** Wraps lines at the editor's width, or at $wrapColumn columns when narrower. */
    public bool $softWrap = false;
    public int $wrapColumn = 0;
    public int $lineNumbers = self::LINE_NUMBERS_ON;
    /** Makes the text selectable and copyable, not editable. */
    public bool $readOnly = false;
    public int $whitespace = self::WHITESPACE_SELECTION;
    /** @var list<int> guides at these columns, as 80 and 100 */
    public array $wrapGuides = [];
    /** Hides the lines that show indentation levels. */
    public bool $noIndentGuides = false;
    /** Stops scrolling at the last line rather than letting it scroll up to the top. */
    public bool $noScrollPastEnd = false;
    /** Stops closing brackets and quotes as they are typed. */
    public bool $noAutoClose = false;
    /** Hides the fold markers of the gutter and turns folding off. */
    public bool $noFolding = false;
    /** Stops highlighting the lines with a caret. */
    public bool $noActiveLine = false;
    public int $cursorShape = self::CURSOR_BAR;
    /** Makes the editor as high as its lines, to show in a scroll view or a list, rather than scrolling them itself. */
    public bool $fitContent = false;
    /** Shows while the buffer is empty. */
    public string $placeholder = '';
    /** Names the editor for assistive technology. */
    public string $label = '';
    /** Colors the editor; Theme::defaults of the window's theme when null. */
    public ?Theme $theme = null;
    /** @var list<Binding> key bindings added, or replacing the default ones for their keys (Keymap::defaults) */
    public array $bindings = [];
    /** The text the buffer's changes are shown against in the gutter, as the version of a file in Git: bars mark lines added and changed, and a mark where lines were deleted. */
    public ?Buffer $diffBase = null;

    /** Asks the app for completions where the caret is, as a word is typed or Ctrl+Space pressed: answer with showCompletions, at once or later. @var ?Closure(CompletionRequest): void */
    public ?Closure $onCompletion = null;
    /** Asks the app what to show for the text under the pointer once it rests there: answer with showHover. @var ?Closure(HoverRequest): void */
    public ?Closure $onHover = null;
    /** Called for Cmd+click and F12 on a word, as to go to its definition, with its offset. @var ?Closure(int): void */
    public ?Closure $onDefinition = null;
    /** Called for a click on a line's number, as to set a breakpoint. @var ?Closure(int): void */
    public ?Closure $onGutterClick = null;
    /** Items added to the editor's context menu, after its own. @var ?Closure(): list<\MyPHP\MenuItem> */
    public ?Closure $contextMenu = null;
    /** @var list<GutterMark> marks lines in the gutter, as breakpoints or bookmarks: a dot of their color */
    public array $gutterMarks = [];
    /**
     * @var list<LineBackground> fill the rows of lines with colors, under
     * the text and the selections, as the statement a key runs: not the
     * gutter, nor the lines folds hide. They keep their line numbers as
     * the text is edited, unlike diagnostics and inlays, which move with
     * it: compute them again when the buffer's version changes.
     */
    public array $lineBackgrounds = [];
    /**
     * Sees each input event before the editor, as keys for a mode of the
     * app's such as Vim's: returning true takes it, and the editor leaves
     * it. Text typed at the carets comes as TEXT; what an input method
     * composes, commits or replaces goes to the editor alone. The key after
     * the first of a chord of bindings, as Cmd+K Cmd+0, is the chord's.
     * @var ?Closure(\MyPHP\UI\InputEvent): bool
     */
    public ?Closure $onInput = null;
    /** Turns the system's input methods off while the editor has the keyboard (Node::inputMethod): keys type the characters of the keyboard's layout, which come to onInput as TEXT. */
    public bool $noInputMethod = false;

    /**
     * Build the editor's find bar, go to line bar, completions and hovers
     * in place of its own (FindBar::default, GoToLineBar::default,
     * CompletionMenu::default and HoverCard::default, which they can call
     * to wrap them): each is given the node the editor places, open, to
     * style and fill, and a handle to what the part shows and does:
     * fn(Context, Node, FindBar).
     */
    public ?Closure $renderFindBar = null, $renderGoToLine = null, $renderCompletions = null, $renderHover = null;

    private Editor $ed;

    public function __construct(?Buffer $buffer = null)
    {
        $this->buffer = $buffer;
        $this->ed = new Editor;
    }

    /**
     * Builds a code editor of the buffer, as Zed's (mygo's editor.View):
     * syntax highlighting, several carets and selections, soft wraps,
     * folding, finding and replacing, completions, diagnostics, and the
     * commands and key bindings of code editors (Command, Keymap). Give
     * the node it returns a size, as grow; with fitContent it is as high
     * as its lines. It lays out and draws only the lines in view, shaping
     * each row once and keeping it while frames draw it, whatever the
     * size of the text.
     */
    public function view(Context $c): Node
    {
        $this->buffer ??= new Buffer('');
        return $this->ed->build($c, $this->buffer, $this->options());
    }

    private function options(): Options
    {
        $o = new Options;
        $o->readOnly = $this->readOnly; $o->fontSize = $this->fontSize; $o->fontStyle = $this->fontStyle; $o->lineHeight = $this->lineHeight;
        $o->tabSize = $this->tabSize; $o->indent = $this->indent; $o->wrap = $this->softWrap; $o->wrapColumn = $this->wrapColumn;
        $o->lineNumbers = $this->lineNumbers; $o->whitespace = $this->whitespace; $o->wrapGuides = $this->wrapGuides;
        $o->indentGuides = !$this->noIndentGuides; $o->scrollPastEnd = !$this->noScrollPastEnd && !$this->fitContent;
        $o->autoClose = !$this->noAutoClose; $o->folding = !$this->noFolding; $o->activeLine = !$this->noActiveLine;
        $o->cursorShape = $this->cursorShape; $o->fitContent = $this->fitContent; $o->placeholder = $this->placeholder; $o->label = $this->label;
        $o->theme = $this->theme; $o->bindings = $this->bindings; $o->gutterMarks = $this->gutterMarks; $o->backgrounds = $this->lineBackgrounds;
        $o->diffBase = $this->diffBase; $o->onCompletion = $this->onCompletion; $o->onHover = $this->onHover; $o->onDefinition = $this->onDefinition;
        $o->onGutterClick = $this->onGutterClick; $o->contextMenu = $this->contextMenu; $o->onInput = $this->onInput; $o->noInputMethod = $this->noInputMethod;
        $o->minDigits = 3;
        $o->renderFindBar = $this->renderFindBar; $o->renderGoToLine = $this->renderGoToLine;
        $o->renderCompletions = $this->renderCompletions; $o->renderHover = $this->renderHover;
        return $o;
    }

    /** The editor's selections, in order. @return list<Selection> */
    public function selections(): array
    {
        $this->ensure();
        return array_map(fn(Sel $s) => new Selection($s->anchor, $s->head), $this->ed->sels);
    }

    /** The newest selection, the one input methods and scrolling follow. */
    public function selection(): Selection
    {
        $this->ensure();
        $p = $this->ed->primary();
        return new Selection($p->anchor, $p->head);
    }

    /** Sets the selections, the last the newest, and scrolls it into view. */
    public function setSelections(Selection ...$sels): void
    {
        $this->ensure();
        if ($sels === []) { return; }
        $n = $this->ed->view->text->len();
        $out = [];
        foreach ($sels as $s) { $out[] = $this->ed->newSel(max(0, min($s->anchor, $n)), max(0, min($s->head, $n))); }
        $this->ed->setSels($out, Editor::REVEAL_FIT);
    }

    /** Selects the text from $start to $end, a caret alone at $end when they are equal. */
    public function select(int $start, int $end): void { $this->setSelections(new Selection($start, $end)); }

    /** The offset of the newest caret. */
    public function cursor(): int { return $this->selection()->head; }

    /** The line and column of the newest caret. */
    public function cursorPos(): Pos
    {
        $this->ensure();
        return Buffer::posOf($this->ed->view->text, $this->ed->primary()->head);
    }

    /** The text of the selections, joined by newlines. */
    public function selectedText(): string
    {
        $this->ensure();
        return $this->ed->selectedText();
    }

    /** Replaces each selection with $text, as typing or pasting it. */
    public function insert(string $text): void
    {
        $this->ensure();
        $this->ed->retry(function () use ($text) { $this->ed->paste($text); return true; });
    }

    /** Runs a command as its key would, and reports whether it did something. */
    public function run(int $cmd): bool
    {
        $this->ensure();
        return $this->ed->retry(fn() => $this->ed->run($cmd));
    }

    /** Puts the caret at a line and column, from 0, and scrolls it to the middle of the editor. */
    public function goTo(Pos $p): void
    {
        $this->ensure();
        $off = Buffer::offsetOf($this->ed->view->text, $p);
        $this->ed->setSels([$this->ed->newSel($off, $off)], Editor::REVEAL_CENTER);
    }

    /** Scrolls a line to the top of the editor. */
    public function scrollTo(int $line): void
    {
        $this->ensure();
        $this->ed->setScroll($this->ed->view->heights->top($line));
    }

    /** Opens the find bar with $query, and selects its first match after the caret. */
    public function find(string $query): void
    {
        $this->ensure();
        $f = $this->ed->find;
        $f->open = true; $f->query = $query; $f->stale = true;
        $f->step($this->ed, 1);
    }

    /** How many matches the find bar has, and which is chosen, from 0. @return array{0: int, 1: int} */
    public function findMatches(): array
    {
        $this->ensure();
        $f = $this->ed->find;
        if (!$f->open) { return [0, 0]; }
        $f->search($this->ed);
        return [count($f->matches), $f->current];
    }

    /** Folds the lines indented under $line, or up to its closing bracket, as the gutter's marker does; unfold unfolds them. */
    public function fold(int $line): void
    {
        $this->ensure();
        if (!$this->ed->isFolded($line)) { $this->ed->toggleFoldLine($line); }
    }

    public function unfold(int $line): void
    {
        $this->ensure();
        if ($this->ed->isFolded($line)) { $this->ed->toggleFoldLine($line); }
    }

    /** The lines folds start at. @return list<int> */
    public function folded(): array
    {
        $out = array_map(fn(array $f) => $f[0], $this->ed->folds);
        sort($out);
        return $out;
    }

    /**
     * Shows diagnostics of the text at $version, the buffer's version when
     * they were made: those of an older version move with the edits
     * since, as a language server's that arrive late.
     * @param list<Diagnostic> $ds
     */
    public function setDiagnostics(int $version, array $ds): void
    {
        $this->ensure();
        $ed = $this->ed;
        $ed->sync();
        $ds = array_map(fn(Diagnostic $d) => clone $d, $ds);
        if ($version !== $ed->view->version) {
            // Moved through the edits since, up to the text the editor shows.
            $entries = $this->buffer->entriesSince($version);
            if ($entries === null) { return; }
            foreach ($entries as [$ver, , $cs]) {
                if ($ver > $ed->view->version) { break; }
                foreach ($ds as $d) {
                    $d->start = Change::mapOffset($d->start, $cs, Change::RIGHT);
                    $d->end = max(Change::mapOffset($d->end, $cs, Change::LEFT), $d->start);
                }
            }
        }
        usort($ds, fn(Diagnostic $a, Diagnostic $b) => $a->start <=> $b->start);
        $ed->diags = $ds;
    }

    /** The diagnostics shown, where the edits since moved them. @return list<Diagnostic> */
    public function diagnostics(): array { return array_map(fn(Diagnostic $d) => clone $d, $this->ed->diags); }

    /** Shows completions of the word before the caret, which starts at offset $start (the request's offset less its prefix), or -1 for the word there now. Typing filters them; an empty list closes them. @param list<Completion> $items */
    public function showCompletions(int $start, array $items): void
    {
        $this->ensure();
        $this->ed->sync();
        $this->ed->showCompletions($start, $items);
    }

    /** Shows what the text from $start to $end is: $text in the editor's font, and what $build adds, as a hover of a language server. @param ?Closure(Context): void $build */
    public function showHover(int $start, int $end, string $text, ?Closure $build = null): void
    {
        $this->ensure();
        $h = $this->ed->hover;
        $h->shown = true; $h->start = $start; $h->end = $end; $h->text = $text; $h->build = $build;
        $h->diags ??= $this->ed->diagnosticsAt($start);
    }

    /** Shows inlay hints in the lines, as types and parameter names: they move with the edits until set again. @param list<Inlay> $inlays */
    public function setInlays(array $inlays): void
    {
        $this->ensure();
        $this->ed->setInlays($inlays);
    }

    /** Shows $text at $offset, muted, as an edit prediction: Tab inserts it, and an edit or a move of the caret drops it. An empty text takes it away. */
    public function setSuggestion(int $offset, string $text): void
    {
        $this->ensure();
        $this->ed->setSuggestion($offset, $text);
    }

    /** Whether the editor had the keyboard focus in the last frame. */
    public function focused(): bool { return $this->ed->focused; }

    /** The first and the last line, from 0, that the last frame showed, those hidden by folds among them. @return array{0: int, 1: int} */
    public function visibleLines(): array { return $this->ed->shown; }

    /** Readies the state for methods called before the editor is built. */
    private function ensure(): void
    {
        $this->buffer ??= new Buffer('');
        $ed = $this->ed;
        if ($ed->view->buf !== $this->buffer || $ed->sels === null) {
            $ed->tp ??= Editor::$text ?? App::text();
            $ed->init($this->buffer, $this->options(), UITheme::light(), $ed->tp);
        }
        $ed->sync();
    }
}
