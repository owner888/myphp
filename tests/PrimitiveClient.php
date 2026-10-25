<?php
// A text input client of the tests' own (mygo's primitiveClient), for text_input_client_test and transform_test.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{TextInputClient, TextInputRange, TextInputSelection, TextState};

/** A document of the test's, as mygo's primitiveClient: UTF-16 ranges over $text. */
final class PrimitiveClient implements TextInputClient
{
    public ?TextInputRange $mark = null;
    public int $changes = 0, $unmarks = 0;

    public function __construct(public string $text, public TextInputSelection $sel) {}

    private function byte(int $u16): int { return TextState::byteIndex($this->text, $u16); }
    private function u16(string $s): int { return intdiv(strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')), 2); }
    public function textForRange(TextInputRange $r): array
    {
        [$a, $z] = [$this->byte($r->start), $this->byte($r->end)];
        return [substr($this->text, $a, $z - $a), new TextInputRange($this->u16(substr($this->text, 0, $a)), $this->u16(substr($this->text, 0, $z)))];
    }
    public function selection(): TextInputSelection { return $this->sel; }
    public function markedRange(): ?TextInputRange { return $this->mark; }
    private function rangeFor(?TextInputRange $r): TextInputRange { return $r ?? $this->mark ?? $this->sel->range; }
    private function replace(TextInputRange $r, string $s): void
    {
        [$a, $z] = [$this->byte($r->start), $this->byte($r->end)];
        $this->text = substr($this->text, 0, $a) . $s . substr($this->text, $z);
        $end = $r->start + $this->u16($s);
        $this->sel = new TextInputSelection(new TextInputRange($end, $end));
        $this->changes++;
    }
    public function replaceText(?TextInputRange $r, string $s): void { $this->replace($this->rangeFor($r), $s); $this->mark = null; }
    public function setMarkedText(?TextInputRange $r, string $s, TextInputRange $selected): void
    {
        $wanted = $this->rangeFor($r);
        $this->replace($wanted, $s);
        $this->mark = new TextInputRange($wanted->start, $wanted->start + $this->u16($s));
        $this->sel = new TextInputSelection(new TextInputRange($this->mark->start + $selected->start, $this->mark->start + $selected->end));
    }
    public function unmarkText(): void { $this->mark = null; $this->unmarks++; }
    public function boundsForRange(TextInputRange $r): ?array { return [[$r->start * 4.0, 5.0, max(1.0, ($r->end - $r->start) * 4.0), 20.0], $r]; }
    public function indexForPoint(float $x, float $y): ?int { return max(0, min((int)($x / 4), $this->u16($this->text))); }
}
