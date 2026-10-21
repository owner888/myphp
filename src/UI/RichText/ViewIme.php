<?php
declare(strict_types=1);

namespace MyPHP\UI\RichText;

use MyPHP\UI\{TextInputRange, TextInputSelection, TextState};
use MyPHP\UI\Editor\Rope;

/**
 * The editor as the client of the system's input methods (mygo's richtext
 * ime.go): they read the text around the caret and compose there, in
 * UTF-16 units of the text with the composition, which is a virtual
 * insertion at the caret until it is committed, as one step of undo with
 * its start.
 *
 * @internal part of View
 */
trait ViewIme
{
    /** The UTF-16 offset of rune $i of the text shown. */
    private function units(int $i): int
    {
        $t = $this->shown->text;
        return Rope::prefix($t, Rope::byteOfRune($t, max(0, min($i, $this->shown->len()))))->units;
    }

    /** The rune holding UTF-16 unit $u, or after it. */
    private function runeOfUnits(int $u): int { return Rope::runeOfUnit($this->shown->text, max(0, $u)); }

    private static function countUnits(string $s): int { return TextState::utf16Index($s, strlen($s)); }

    /** The rune of the text with the composition at UTF-16 unit $u of it. */
    private function virtualRune(int $u): int
    {
        $start = $this->units($this->caret); $n = self::countUnits($this->compose);
        if ($u <= $start || $this->compose === '') { return $this->runeOfUnits($u); }
        if ($u <= $start + $n) {
            return $this->caret + mb_strlen(substr($this->compose, 0, TextState::byteIndex($this->compose, $u - $start)), 'UTF-8');
        }
        return $this->runeOfUnits($u - $n) + mb_strlen($this->compose, 'UTF-8');
    }

    /** The UTF-16 offset of rune $i of the text with the composition. */
    private function virtualUnits(int $i): int
    {
        $n = mb_strlen($this->compose, 'UTF-8');
        if ($i <= $this->caret || $n === 0) { return $this->units($i); }
        if ($i <= $this->caret + $n) {
            return $this->units($this->caret) + self::countUnits(mb_substr($this->compose, 0, $i - $this->caret, 'UTF-8'));
        }
        return $this->units($i - $n) + self::countUnits($this->compose);
    }

    public function textForRange(TextInputRange $r): array
    {
        $this->sync();
        $r = $r->start > $r->end ? new TextInputRange($r->end, $r->start) : $r;
        $a = $this->virtualRune($r->start); $z = $this->virtualRune($r->end);
        $actual = new TextInputRange($this->virtualUnits($a), $this->virtualUnits($z));
        $n = mb_strlen($this->compose, 'UTF-8');
        if ($n === 0) { return [$this->shown->slice($a, $z)->string(), $actual]; }
        $out = '';
        if ($a < $this->caret) { $out .= $this->shown->slice($a, min($z, $this->caret))->string(); }
        if ($a < $this->caret + $n && $z > $this->caret) {
            $from = max(0, $a - $this->caret);
            $out .= mb_substr($this->compose, $from, min($n, $z - $this->caret) - $from, 'UTF-8');
        }
        if ($z > $this->caret + $n) { $out .= $this->shown->slice(max($a - $n, $this->caret), $z - $n)->string(); }
        return [$out, $actual];
    }

    public function selection(): TextInputSelection
    {
        $this->sync();
        if ($this->compose !== '') {
            $start = $this->units($this->caret);
            return new TextInputSelection(new TextInputRange($start + $this->composeSelected->start, $start + $this->composeSelected->end));
        }
        [$a, $z] = $this->sel();
        return new TextInputSelection(new TextInputRange($this->units($a), $this->units($z)), $this->caret === $a && $a !== $z);
    }

    public function markedRange(): ?TextInputRange
    {
        if ($this->compose === '') { return null; }
        $start = $this->units($this->caret);
        return new TextInputRange($start, $start + self::countUnits($this->compose));
    }

    public function replaceText(?TextInputRange $r, string $s): void
    {
        $this->sync();
        if ($this->opts->readOnly) { return; }
        if ($r === null && $this->compose !== '') {
            $this->compose = '';
            $this->joinStep();
            $this->replace($this->caret, $this->caret, $s);
        } elseif ($r === null) {
            $this->insert($s);
            $this->typed($s);
        } else {
            $a = $this->virtualRune($r->start); $z = $this->virtualRune($r->end);
            if ($a > $z) { [$a, $z] = [$z, $a]; }
            $transaction = $this->composing;
            $this->commitCompose();
            if (!$transaction) { $this->record(false); } else { $this->joinStep(); }
            $this->replace($a, $z, $s);
        }
        $this->compose = ''; $this->composing = false;
        $this->composeCaret = 0; $this->composeSelected = new TextInputRange(0, 0);
        $this->reveal = true;
        $this->publish();
    }

    public function setMarkedText(?TextInputRange $r, string $s, TextInputRange $selected): void
    {
        $this->sync();
        if ($this->opts->readOnly) { return; }
        if ($this->compose === '' || $r !== null) {
            [$a, $z] = $this->sel();
            if ($r !== null) {
                $a = $this->virtualRune($r->start); $z = $this->virtualRune($r->end);
                if ($a > $z) { [$a, $z] = [$z, $a]; }
            }
            $transaction = $this->composing && $this->compose !== '';
            $this->commitCompose();
            if (!$transaction) { $this->record(false); } else { $this->joinStep(); }
            $this->composing = true;
            if ($a !== $z) { $this->replace($a, $z, ''); }
        }
        [$from, $to] = $selected->start > $selected->end ? [$selected->end, $selected->start] : [$selected->start, $selected->end];
        $n = self::countUnits($s);
        $from = min($from, $n); $to = min($to, $n);
        $from = self::countUnits(substr($s, 0, TextState::byteIndex($s, $from)));
        $to = self::countUnits(substr($s, 0, TextState::byteIndex($s, $to)));
        $this->compose = $s; $this->composeSelected = new TextInputRange($from, $to);
        $this->composeCaret = mb_strlen(substr($s, 0, TextState::byteIndex($s, $to)), 'UTF-8');
        $this->reveal = true;
        $this->publish();
    }

    public function unmarkText(): void
    {
        $transaction = $this->composing;
        $this->commitCompose();
        // GTK and IMM32 can end the composition before committing their replacement, which shares its step of undo.
        $this->composing = $transaction;
        $this->publish();
    }

    public function boundsForRange(TextInputRange $r): ?array
    {
        $this->sync();
        if ($this->paras === [] || $this->width <= 0) { return null; }
        $i = $this->virtualRune(min($r->start, $r->end));
        // A rune of the text and how far past it in the composition.
        $base = $i; $extra = 0;
        if (($n = mb_strlen($this->compose, 'UTF-8')) > 0 && $i > $this->caret) {
            if ($i <= $this->caret + $n) {
                $base = $this->caret; $extra = $i - $this->caret;
            } else {
                $base = $i - $n;
            }
        }
        [$x, $y, $h] = $this->caretAt($base, $extra, $this->affinity);
        $b = [$this->padX + $x, $this->padY + $y - $this->scroll, 1.0, $h];
        $actual = new TextInputRange($this->virtualUnits($i), $this->virtualUnits($i));
        if ($r->end > $r->start) {
            [$x2] = $this->caretAt($base, $extra + 1, $this->affinity);
            if ($x2 > $x) {
                $b[2] = $x2 - $x;
                $actual->end = $this->virtualUnits($i + 1);
            }
        }
        return [$b, $actual];
    }

    public function indexForPoint(float $x, float $y): ?int
    {
        $this->sync();
        if ($this->width <= 0) { return null; }
        [$cx, $cy] = $this->contentPoint($x, $y);
        [$para, $l] = $this->layoutPositionAt($cx, $cy);
        $n = mb_strlen($this->compose, 'UTF-8');
        // The rune of the text with the composition: the layout of the caret's paragraph holds it, those after it are past it.
        $i = $this->shown->len() + $n;
        if ($para >= 0) {
            $i = $this->shown->paraStart($para) + $l;
            if ($n > 0 && $para > $this->shown->paraAt($this->caret)) { $i += $n; }
        }
        return $this->virtualUnits($i);
    }
}
