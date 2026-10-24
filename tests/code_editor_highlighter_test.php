<?php
// A code editor with a highlighter of its own, as a parser's (mygo's
// highlighter_test.go): its tokens, and the structure it knows, which
// Select Larger, brackets, folds and indentation take.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Context, Key};
use MyPHP\UI\Editor\{BracketMatcher, Buffer, Change, Command, Folder, Highlighter, Indenter, IndentStyle, Language, Languages, Range, Snapshot, State, SyntaxSelector, Token, TokenHighlighter};

require_once __DIR__ . '/EditorTest.php';

$edOf = fn(State $st) => (fn() => $this->ed)->call($st);

/** Stands for a parser's highlighter, as tree-sitter's would be: it follows the edits, works "in the background" for a while, and knows the structure of the text. */
final class ParserHighlighter implements Highlighter, SyntaxSelector, Folder, BracketMatcher, Indenter
{
    public int $edits = 0;
    /** Calls to highlight until it is ready. */
    public int $pending = 0;

    public function __construct(public Snapshot $text) {}

    public function edit(Snapshot $old, Snapshot $new, array $changes): void
    {
        // The changes and the texts give back the new text.
        $b = ''; $at = 0;
        foreach ($changes as $c) {
            $b .= $old->slice($at, $c->oldStart) . $new->slice($c->newStart, $c->newEnd);
            $at = $c->oldEnd;
        }
        $b .= $old->slice($at, $old->len());
        if ($b !== (string)$new) { throw new \RuntimeException("changes do not make the new text: $b"); }
        $this->text = $new;
        $this->edits++;
        $this->pending = 2;
    }

    public function highlight(int $last, float $budget): bool
    {
        if ($this->pending > 0) { $this->pending--; return false; }
        return true;
    }

    /** Makes "fn" a keyword. */
    public function tokens(int $i, string $text, array $dst): array
    {
        $from = 0;
        while (($k = strpos($text, 'fn', $from)) !== false) {
            if ($k > 0) { $dst[] = [$k, Token::TEXT]; }
            $dst[] = [$k + 2, Token::KEYWORD];
            $from = $k + 2;
        }
        if ($from < strlen($text)) { $dst[] = [strlen($text), Token::TEXT]; }
        return [$dst, $this->pending === 0];
    }

    /** The whole text is the node around anything. */
    public function enclosing(int $start, int $end): ?Range { return new Range(0, $this->text->len()); }

    /** Line 0 folds to the end. */
    public function foldEnd(int $line): ?int { return $line === 0 ? $this->text->lineCount() - 1 : null; }

    /** The first and last bytes are brackets around everything. */
    public function enclosingBrackets(int $start, int $end): ?array { return [new Range(0, 2), new Range($this->text->len() - 3, $this->text->len())]; }

    /** Return indents two levels. */
    public function indentAfter(int $offset): ?int { return 2; }
}

$made = null;
$lang = new Language('Parsed', highlighter: function (Snapshot $text) use (&$made) { return $made = new ParserHighlighter($text); });
$st = new State(new Buffer("fn a() do\n  body\nend"));
$st->indent = IndentStyle::IndentSpaces; $st->tabSize = 2;
$st->buffer->setLanguage($lang);
$node = null;
$h = new Headless(function (Context $c) use ($st, &$node) { $node = $st->view($c); $node->grow = 1; }, 500, 300);
$h->paints = true;
$h->frame(1);
$h->rt->focusOn($node->id);
$h->frame();
check($made !== null, 'the language\'s highlighter is made');
$ed = $edOf($st);
same([2, Token::KEYWORD], $ed->view->line(0)->toks[0] ?? null, 'its tokens color the lines');
// Edits reach it, and the frames go on while it works.
$st->select(0, 0);
$h->input->client->replaceText(null, 'x');
$h->frame();
same([1, 0], [$made->edits, $made->pending], 'edits reach it, and the frames go on while it works');
check($ed->view->line(0)->exact, 'the tokens of a line are no guess once it is done');
$st->select(5, 5);
$st->run(Command::SELECT_LARGER);
same([0, $st->buffer->len()], [$st->selection()->start(), $st->selection()->end()], 'Select Larger takes its node');
$st->select(5, 5);
$st->run(Command::MOVE_TO_BRACKET);
same($st->buffer->len() - 3, $st->cursor(), 'moving to the bracket takes its brackets');
$st->fold(0);
$h->frame();
same([0], $st->folded(), 'folding takes its folds');
$st->run(Command::UNFOLD_ALL);
$st->select($st->buffer->len(), $st->buffer->len());
$st->run(Command::NEWLINE);
check(str_ends_with((string)$st->buffer, "end\n    "), 'Return takes its indentation');

// The tokenizer of a language is its default highlighter; a language without one leaves text plain.
$b = new Buffer("x := `a\nb`");
$b->setLanguage(Languages::named('go'));
$hl = $b->highlighter();
check($hl instanceof TokenHighlighter, 'the tokenizer\'s highlighter');
$hl->highlight(1, 3600);
[$toks, $final] = $hl->tokens(1, $b->line(1), []);
check($final && $toks !== [] && $toks[0][1] === Token::STRING, 'a raw string goes on to line 1');
$b->setLanguage(new Language('Plain'));
check($b->highlighter() === null, 'plain text is not highlighted');

// The find bar's buttons take clicks, not the editor around them; opening the bar finds the word at the caret, and selects its match.
$ct = new EditorTest('', '|a a a');
$ct->key(Key::COMMAND, Key::F, 'f');
$ct->h->frame();
$ct->want('[a] a a', 'the word at the caret found');
$ct->h->clickText('↓');
same(1, $ct->st->findMatches()[1], 'Next match');
$ct->h->clickText('✕');
check(!$edOf($ct->st)->find->open, 'Close closes the bar');

/** Colors from the text it follows, as a highlighter that parses the whole text does, rather than from the line it is given; $behind notes a line asked of it before it heard of its text. */
final class SnapshotHighlighter implements Highlighter
{
    /** @var list<string> */
    public array $behind = [];

    public function __construct(public Snapshot $text) {}
    public function edit(Snapshot $old, Snapshot $new, array $changes): void { $this->text = $new; }
    public function highlight(int $last, float $budget): bool { return true; }

    public function tokens(int $i, string $text, array $dst): array
    {
        if ($i >= $this->text->lineCount() || $this->text->slice($this->text->lineStart($i), $this->text->lineEnd($i)) !== $text) { $this->behind[] = $text; }
        $dst[] = [strlen($text), Token::KEYWORD];
        return [$dst, true];
    }
}

// An edit reaches the highlighter before a line of its text is laid out, as when the selection is set before the next frame.
$sh = null;
$lang = new Language('Snapshot', highlighter: function (Snapshot $text) use (&$sh) { return $sh = new SnapshotHighlighter($text); });
$st = new State(new Buffer(''));
$st->buffer->setLanguage($lang);
$h = new Headless(function (Context $c) use ($st, &$node) { $node = $st->view($c); $node->grow = 1; }, 500, 300);
$h->paints = true;
$h->frame();
$st->buffer->set('SELECT 1');
$st->select(8, 8);
$h->frame();
same([], $sh->behind, 'no tokens asked of a text the highlighter has not heard of');
