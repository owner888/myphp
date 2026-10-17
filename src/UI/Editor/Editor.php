<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

use MyPHP\L10n;
use MyPHP\UI\{Runtime, TextInputClient, TextPainter, Theme as UITheme};

/**
 * A code editor (mygo's editor): a view of a buffer with selections, the
 * input that edits it, and its overlays. A State keeps one; a diff viewer
 * one a side. Its methods are those of mygo's editor's files, a trait
 * each: edit, commands, lines, multicursor, brackets, fold, input, paint,
 * build and lsp.
 */
final class Editor implements TextInputClient
{
    use EditorEdit, EditorCommands, EditorLines, EditorMulticursor, EditorBrackets, EditorFold, EditorInput, EditorPaint, EditorBuild, EditorLsp, EditorGitDiff;

    /** Asks the next frame to scroll the newest caret into view: as little as shows it with a margin, centered, or at the top. */
    public const REVEAL_NONE = 0, REVEAL_FIT = 1, REVEAL_CENTER = 2, REVEAL_TOP = 3;
    /** What part of the editor a point is over. */
    public const REGION_TEXT = 0, REGION_GUTTER = 1, REGION_VSCROLL = 2, REGION_HSCROLL = 3;
    /** The width of the scroll bars, their tracks and what takes presses. */
    public const SCROLLBAR_WIDTH = 10.0;

    public View $view;
    public Options $o;
    public Theme $theme;
    /** MyPHP's strings in the window's language, as of the last frame. */
    public L10n $texts;

    /** @var ?list<Sel> null until the editor is set up */
    public ?array $sels = null;
    public int $nextId = 0;
    /** The press dragging a selection. */
    public Gesture $gesture;
    /** @var list<int> the selections Add Selection Above and Below made, as Zed's, to take them back going the other way */
    public array $addStack = [];
    public int $addDir = 0;
    /** @var list<list<Sel>> the selections Undo Selection takes back, and gives again */
    public array $selUndo = [], $selRedo = [];
    /** @var list<array{0: int, 1: int}> folds, each hiding the lines after its start up to its end */
    public array $folds = [];
    /** @var list<array{0: int, 1: int}> the composition, in bytes per selection */
    public array $marked = [];
    public bool $markedActive = false;
    /** @var list<array{sel: int, at: int, close: string}> the closers the editor typed: the selection's id, the offset of the closing text, and it */
    public array $autoclosed = [];

    public float $scrollY = 0.0;
    public float $scrollX = 0.0;
    public int $anchorLine = 0;
    public float $anchorOff = 0.0;
    public int $reveal = self::REVEAL_NONE;
    /** @var array{0: float, 1: float, 2: float, 3: float} the element's box of the last frame, in the window */
    public array $r = [0.0, 0.0, 0.0, 0.0];
    public float $gutterW = 0.0;
    public float $textX = 0.0;
    public float $viewH = 0.0;
    /** @var array{0: int, 1: int} the first and last lines the last frame showed */
    public array $shown = [0, 0];
    /** The editor and its window have the keyboard. */
    public bool $focused = false;
    public float $blinkStart = 0.0;
    public ?Runtime $services = null;
    public ?Binding $chord = null;
    /** @var array{0: float, 1: float} the pointer, in the element */
    public array $pointer = [0.0, 0.0];
    public bool $pointerIn = false;
    public int $hoverLine = -1;
    /** The part of the editor under the pointer, which its cursor and the scroll bars' highlight show. */
    public int $region = self::REGION_TEXT;
    /** A press on a scroll bar: active, horizontal, and where in the thumb the press caught it. */
    public array $scrollbar = ['active' => false, 'horizontal' => false, 'grab' => 0.0];
    public float $frameNow = 0.0;
    public bool $needsBuild = false;
    /** An edit found the buffer edited since the editor followed it, for its command to run again (retry). */
    public bool $stale = false;

    public bool $refocus = false;
    public bool $wholeWord = false;
    /** @var list<Sel> */
    public array $larged = [];
    public bool $toggledWrap = false;
    public bool $toggledNumbers = false;
    public string $suggestion = '';

    public Finder $find;
    public GoToLine $goTo;
    public Completions $complete;
    public Hover $hover;
    /** @var list<Diagnostic> */
    public array $diags = [];
    public ?GitDiff $gitDiff = null;
    /** The brackets around the newest caret, found once for a version and a caret: [version, hlGen, at, ?[open, close]], or null. */
    public ?array $brackets = null;

    /**
     * What an editor last copied, which pasting its text in any editor
     * takes the pieces and lines of, as Zed keeps them with the
     * clipboard's text: its text, its pieces, and which were copied as
     * whole lines.
     * @var array{text: string, pieces: list<string>, lines: list<bool>}
     */
    public static array $lastClip = ['text' => '', 'pieces' => [], 'lines' => []];

    /** The text engine editors measure their lines with before their first frame, as State's methods called before building it: App's when null; a headless run sets its own. */
    public static ?TextPainter $text = null;

    public function __construct()
    {
        $this->view = new View;
        $this->o = new Options;
        $this->gesture = new Gesture;
        $this->find = new Finder;
        $this->goTo = new GoToLine;
        $this->complete = new Completions;
        $this->hover = new Hover;
        $this->texts = L10n::in('en');
    }

    /** Sets the editor up for a frame, with the text engine its lines are measured with. */
    public function init(Buffer $buf, Options $o, UITheme $t, TextPainter $m): void
    {
        $this->o = $o;
        $this->theme = Theme::resolve($o->theme, $t);
        $this->view->theme = $this->theme;
        $this->view->readOnly = $o->readOnly;
        $this->view->style = $o->fontStyle;
        if ($this->view->buf !== null && $this->view->buf !== $buf) { $this->resetForBuffer(); }
        $this->view->setBuffer($buf);
        if ($this->sels === null) {
            $this->sels = [new Sel(0, 0, -1.0, 0)];
            $this->nextId = 1;
            $this->blinkStart = hrtime(true) / 1e9;
            $this->hover->asked = -1;
            $this->hoverLine = -1;
        }
        if ($this->view->lineHeight == 0) { $this->view->setStyle($m, $o->fontSize, $o->lineHeight, 1.0, $this->tabSize()); }
    }

    public function buf(): ?Buffer { return $this->view->buf; }

    /** The columns of a tab. */
    public function tabSize(): int
    {
        if ($this->o->tabSize > 0) { return $this->o->tabSize; }
        $l = $this->language();
        return $l !== null && $l->tabSize > 0 ? $l->tabSize : 4;
    }

    public function hardTabs(): bool
    {
        return match ($this->o->indent) {
            State::INDENT_TABS => true,
            State::INDENT_SPACES => false,
            default => $this->language()?->hardTabs ?? false,
        };
    }

    public function language(): ?Language { return $this->buf()?->language(); }

    /** The language's extra characters of words. */
    public function wordChars(): string { return $this->language()?->wordChars ?? ''; }

    /** Follows the buffer's edits: selections, folds, diagnostics and the overlays move with the text. */
    public function sync(): void
    {
        $f = $this->find;
        $searched = !$f->stale && $f->version === $this->view->version;
        $this->view->sync(function (array $cs) use ($f, $searched) {
            foreach ($this->sels as $i => $s) { $this->sels[$i] = self::mapSel($s, $cs); }
            foreach (['selUndo', 'selRedo'] as $h) {
                foreach ($this->$h as $k => $sels) {
                    foreach ($sels as $i => $s) { $this->$h[$k][$i] = self::mapSel($s, $cs); }
                }
            }
            foreach ($f->scope ?? [] as $i => $s) { $f->scope[$i] = self::mapSel($s, $cs); }
            foreach ($this->folds as $i => [$a, $b]) { $this->folds[$i] = [Change::mapLine($a, $cs), Change::mapLine($b, $cs)]; }
            $this->folds = array_values(array_filter($this->folds, fn(array $fo) => $fo[1] > $fo[0]));
            foreach ($this->autoclosed as $i => $a) { $this->autoclosed[$i]['at'] = Change::mapOffset($a['at'], $cs, Change::RIGHT); }
            foreach ($this->diags as $d) {
                $d->start = Change::mapOffset($d->start, $cs, Change::RIGHT);
                $d->end = max(Change::mapOffset($d->end, $cs, Change::LEFT), $d->start);
            }
            foreach ($this->marked as $i => [$a, $b]) { $this->marked[$i] = [Change::mapOffset($a, $cs, Change::LEFT), Change::mapOffset($b, $cs, Change::RIGHT)]; }
            // The view stays on the line it shows.
            $this->anchorLine = Change::mapLine($this->anchorLine, $cs);
            $this->complete->moved($cs);
            $this->hover->close();
            if ($searched) { $f->moved($cs); } else { $f->stale = true; }
        });
        if ($searched && !$f->stale) { $f->version = $this->view->version; }
        $this->sels = Sel::merge($this->sels);
    }

    /**
     * Moves a selection through changes: a caret with the text inserted
     * at it, and a selection keeping what it holds, the text inserted at
     * its edges left out, as Zed's anchors. A new Sel: selections are
     * values, as mygo's.
     * @param list<Change> $cs
     */
    public static function mapSel(Sel $s, array $cs): Sel
    {
        $s = clone $s;
        if ($s->empty()) {
            $s->anchor = Change::mapOffset($s->anchor, $cs, Change::RIGHT);
            $s->head = $s->anchor;
            return $s;
        }
        $start = Change::mapOffset($s->start(), $cs, Change::RIGHT);
        $end = max($start, Change::mapOffset($s->end(), $cs, Change::LEFT));
        if ($s->head < $s->anchor) { $s->head = $start; $s->anchor = $end; } else { $s->anchor = $start; $s->head = $end; }
        return $s;
    }

    /** Copies of selections, which can change without changing them: selections are values, as mygo's. @param list<Sel> $sels @return list<Sel> */
    public static function cloneSels(array $sels): array
    {
        return array_map(fn(Sel $s) => clone $s, $sels);
    }

    /** Whether two lists of selections are the same. @param list<Sel> $a @param list<Sel> $b */
    public static function sameSels(array $a, array $b): bool
    {
        if (count($a) !== count($b)) { return false; }
        foreach ($a as $i => $s) {
            $t = $b[$i];
            if ($s->anchor !== $t->anchor || $s->head !== $t->head || $s->goal != $t->goal || $s->id !== $t->id) { return false; }
        }
        return true;
    }

    /** Forgets what the editor knew of the buffer it showed, as it shows another: its carets, folds, marks and what the app gave it of the old text. */
    public function resetForBuffer(): void
    {
        $this->sels = [new Sel(0, 0, -1.0, $this->nextId)];
        $this->nextId++;
        $this->selUndo = []; $this->selRedo = [];
        $this->folds = []; $this->autoclosed = []; $this->marked = []; $this->diags = [];
        $this->view->inlays = []; $this->suggestion = '';
        $this->complete->close();
        $this->hover->close();
        $this->find->scope = null; $this->find->stale = true; $this->find->lowerVer = 0; $this->find->lower = '';
        $this->goTo->open = false;
        $this->gesture->active = false;
        $this->brackets = null;
        $this->scrollY = 0.0; $this->scrollX = 0.0; $this->anchorLine = 0; $this->anchorOff = 0.0;
    }

    /** Sets the selections, noting the old ones for Undo Selection. @param list<Sel> $sels */
    public function setSels(array $sels, int $reveal): void
    {
        if (!self::sameSels($sels, $this->sels)) {
            $this->dropSuggestion();
            $this->selUndo[] = self::cloneSels($this->sels);
            if (count($this->selUndo) > 1024) { array_shift($this->selUndo); }
            $this->selRedo = [];
        }
        $this->sels = Sel::merge($sels);
        if ($reveal !== self::REVEAL_NONE) { $this->reveal = max($this->reveal, $reveal); }
        $this->blinkStart = hrtime(true) / 1e9;
        $this->autoclosed = [];
        // What select next, add cursor and select larger go on from.
        $this->wholeWord = false;
        $this->addStack = [];
        $this->larged = [];
    }

    /** The newest selection, a copy. */
    public function primary(): Sel { return clone $this->sels[Sel::newest($this->sels)]; }

    public function newSel(int $anchor, int $head): Sel
    {
        $this->nextId++;
        return new Sel($anchor, $head, -1.0, $this->nextId);
    }

    /** The lines folds hide, merged. @return list<array{0: int, 1: int}> */
    public function hiddenSpans(): array
    {
        $spans = [];
        foreach ($this->folds as [$a, $b]) { if ($b > $a) { $spans[] = [$a + 1, $b]; } }
        usort($spans, fn(array $x, array $y) => $x[0] <=> $y[0]);
        $out = [];
        foreach ($spans as $s) {
            $n = count($out);
            if ($n > 0 && $s[0] <= $out[$n - 1][1] + 1) { $out[$n - 1][1] = max($out[$n - 1][1], $s[1]); continue; }
            $out[] = $s;
        }
        return $out;
    }

    /** The top of $line's rows in the content. */
    public function rowTop(int $line): float { return $this->view->heights->geometry($line)->rows; }

    /** The rectangle [x, y, w, h] of the caret at $off, in the content, or null when its line does not show. @return ?array{0: float, 1: float, 2: float, 3: float} */
    public function caretRect(int $off): ?array
    {
        $v = $this->view;
        $line = $v->text->lineOf($off);
        if ($v->heights->isHidden($line)) { return null; }
        $l = $v->line($line);
        $d = $l->toDisp($off - $l->start, true);
        $ri = $l->rowOf($d, false);
        $x = $v->xOf($l, $l->rows[$ri], $d);
        $y = $this->rowTop($line) + $ri * $v->lineHeight;
        return [$x, $y, 2.0, $v->lineHeight];
    }

    /** The offset nearest to $x, $y in the content. */
    public function offsetAt(float $x, float $y): int
    {
        $v = $this->view;
        if ($v->text->lineCount() === 0) { return 0; }
        $line = $v->heights->lineAt($y);
        $g = $v->heights->geometry($line);
        if ($g->hidden) { return $v->text->lineEnd($line); }
        $l = $v->line($line);
        if ($y < $g->rows) {
            $ri = 0;
        } elseif ($y >= $g->below) {
            $ri = count($l->rows) - 1;
        } else {
            $ri = min((int)(($y - $g->rows) / $v->lineHeight), count($l->rows) - 1);
        }
        $d = $v->dispAt($l, $l->rows[$ri], $x);
        return $l->start + $l->fromDisp($d);
    }

    /** Where a caret at $off with goal x $goal goes moving $rows rows down (positive) or up, and the goal it keeps. @return array{0: int, 1: float} */
    public function verticalMove(int $off, float $goal, int $rows): array
    {
        $v = $this->view;
        $line = $v->text->lineOf($off);
        $l = $v->line($line);
        $d = $l->toDisp($off - $l->start, true);
        $ri = $l->rowOf($d, false);
        if ($goal < 0) { $goal = $v->xOf($l, $l->rows[$ri], $d); }
        $n = $v->text->lineCount();
        while ($rows !== 0) {
            if ($rows > 0) {
                if ($ri + 1 < count($l->rows)) {
                    $ri++;
                } else {
                    $next = $line + 1;
                    while ($next < $n && $v->heights->isHidden($next)) { $next++; }
                    if ($next >= $n) { return [$v->text->len(), $goal]; }
                    $line = $next; $l = $v->line($next); $ri = 0;
                }
                $rows--;
            } else {
                if ($ri > 0) {
                    $ri--;
                } else {
                    $prev = $line - 1;
                    while ($prev >= 0 && $v->heights->isHidden($prev)) { $prev--; }
                    if ($prev < 0) { return [0, $goal]; }
                    $line = $prev; $l = $v->line($prev);
                    $ri = count($l->rows) - 1;
                }
                $rows++;
            }
        }
        $dd = $v->dispAt($l, $l->rows[$ri], $goal);
        return [$l->start + $l->fromDisp($dd), $goal];
    }

    /** How far the content scrolls down. */
    public function scrollMax(): float
    {
        $total = $this->view->heights->total();
        if ($this->o->scrollPastEnd) { return max(0.0, $total - $this->view->lineHeight); }
        return max(0.0, $total - $this->viewH);
    }

    /** Scrolls to $y, keeping the place by its line. */
    public function setScroll(float $y): void
    {
        $y = max(0.0, min($y, $this->scrollMax()));
        $this->scrollY = $y;
        $this->anchorLine = $this->view->heights->lineAt($y);
        $this->anchorOff = $y - $this->view->heights->top($this->anchorLine);
    }

    /** Puts the scroll back where its line is, as the heights above it change. */
    public function restoreScroll(): void
    {
        $h = $this->view->heights;
        $line = min($this->anchorLine, max($h->n - 1, 0));
        $this->scrollY = max(0.0, min($h->top($line) + $this->anchorOff, $this->scrollMax()));
    }

    /** Scrolls the newest caret into view. */
    public function doReveal(): void
    {
        if ($this->reveal === self::REVEAL_NONE || $this->viewH <= 0) { return; }
        $mode = $this->reveal;
        $this->reveal = self::REVEAL_NONE;
        $s = $this->primary();
        $v = $this->view;
        $line = $v->text->lineOf($s->head);
        // Unfold the line the caret is on.
        if ($v->heights->isHidden($line)) {
            $this->unfoldLine($line);
            $v->heights->setHidden($this->hiddenSpans());
        }
        $cr = $this->caretRect($s->head);
        if ($cr === null) { return; }
        $lh = $v->lineHeight;
        $top = $cr[1]; $bottom = $cr[1] + $lh;
        $h = $this->viewH;
        $margin = min(3 * $lh, max(0.0, ($h - $lh) / 2));
        switch ($mode) {
            case self::REVEAL_CENTER:
                if ($top < $this->scrollY || $bottom > $this->scrollY + $h) { $this->setScroll($top - ($h - $lh) / 2); }
                break;
            case self::REVEAL_TOP:
                $this->setScroll($top - $margin);
                break;
            default:
                if ($top - $margin < $this->scrollY) {
                    $this->setScroll($top - $margin);
                } elseif ($bottom + $margin > $this->scrollY + $h) {
                    $this->setScroll($bottom + $margin - $h);
                }
        }
        // Sideways.
        $w = $this->r[2] - $this->textX - 8;
        if (!$v->wrap && $w > 0) {
            $x = $cr[0];
            $em = $v->charWidth * 2;
            if ($x - $em < $this->scrollX) {
                $this->scrollX = max(0.0, $x - $em);
            } elseif ($x + $em > $this->scrollX + $w) {
                $this->scrollX = $x + $em - $w;
            }
        }
    }

    public function unfoldLine(int $line): void
    {
        $this->folds = array_values(array_filter($this->folds, fn(array $f) => !($line > $f[0] && $line <= $f[1])));
    }

    /** Whether the gutter numbers the lines, as the options say, or the opposite once Toggle Line Numbers ran. */
    public function numbersOn(): bool
    {
        return ($this->o->lineNumbers !== State::LINE_NUMBERS_OFF) !== $this->toggledNumbers;
    }

    /** The width of the gutter: the numbers, the fold markers and the bars of changes. */
    public function gutterWidth(): float
    {
        $v = $this->view;
        $w = 0.0;
        if ($this->numbersOn()) {
            $digits = max($this->o->minDigits, strlen((string)$v->text->lineCount()));
            $w += $digits * $v->charWidth + $v->charWidth * 1.5;
        }
        if ($this->o->folding && $this->o->decor === null) { $w += $v->lineHeight * 0.8; }
        if ($this->o->diffBase !== null) { $w += 6; }
        if ($this->o->gutterMarks !== [] && !$this->numbersOn()) { $w += $v->lineHeight * 0.7; }
        if ($w > 0) { $w += 4; }
        if ($this->o->decor !== null) {
            // Room for the change bars at the gutter's left edge.
            $w += 6;
        }
        return ceil($w);
    }

    /** The lines that show in the frame's view, through a clip in the content's coordinates. @return array{0: int, 1: int} */
    public function visibleLines(float $y0, float $y1): array
    {
        $h = $this->view->heights;
        return [$h->lineAt($y0), min($h->lineAt($y1), $h->n - 1)];
    }

    /** The bytes from $a to $b. */
    public function slice(int $a, int $b): string { return $this->view->text->slice($a, $b); }

    /** The text of the selections, joined by newlines. */
    public function selectedText(): string
    {
        $parts = [];
        foreach ($this->sels as $s) { if (!$s->empty()) { $parts[] = $this->slice($s->start(), $s->end()); } }
        return implode("\n", $parts);
    }

    /** Merges spans of lines, [from, to] inclusive, that overlap or touch. @param list<array{0: int, 1: int}> $spans @return list<array{0: int, 1: int}> */
    public static function mergeSpans(array $spans): array
    {
        usort($spans, fn(array $a, array $b) => $a[0] <=> $b[0]);
        $out = [];
        foreach ($spans as $s) {
            $n = count($out);
            if ($n > 0 && $s[0] <= $out[$n - 1][1] + 1) { $out[$n - 1][1] = max($out[$n - 1][1], $s[1]); continue; }
            $out[] = $s;
        }
        return $out;
    }

    /** Heights knowing only hidden spans, for whether a line is hidden. @param list<array{0: int, 1: int}> $hidden */
    public static function hiddenHeights(array $hidden): Heights
    {
        $h = new Heights;
        $h->setHidden($hidden);
        return $h;
    }
}
