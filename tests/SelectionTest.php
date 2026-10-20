<?php
// Helpers of the tests of selectable text and its menus.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Key, Node};

/** The window point of rune $at of the paragraph whose text is $text, in the middle of its line. */
function selectableAt(Headless $h, string $text, int $at): array
{
    $found = selectableNode($h->root, $text) ?? throw new \RuntimeException("no selectable paragraph \"$text\"");
    [$x, $y, $lh] = $found->textLayout()->caret($at);
    return [$found->x + $x, $found->y + $y + $lh / 2];
}

function selectableNode(Node $n, string $text): ?Node
{
    if ($n->kind === Node::RICH && $n->textScope !== 0 && $n->plainText() === $text) {
        return $n;
    }
    foreach ($n->kids as $k) {
        if (($f = selectableNode($k, $text)) !== null) { return $f; }
    }
    return null;
}

/** A drag from rune $from of $first to rune $to of $last: a new gesture. */
function selectBetween(Headless $h, string $first, int $from, string $last, int $to, int $clicks = 1): void
{
    [$x0, $y0] = selectableAt($h, $first, $from);
    [$x1, $y1] = selectableAt($h, $last, $to);
    $h->input->mouse($x0, $y0);
    $h->input->push(['down', $x0, $y0, 0, $clicks, 0]);
    $h->frame();
    $h->drag($x1, $y1);
    $h->release($x1, $y1);
}

function clip(Headless $h): string { return ($h->rt->readClipboard)(); }
function setClip(Headless $h, string $s): void { ($h->rt->writeClipboard)($s); }
function cmdKey(Headless $h, string $c): void { $h->shortcut($c, Key::COMMAND); }

