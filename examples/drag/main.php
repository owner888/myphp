<?php
// Drag gestures, as SwiftUI's: a card the pointer drags anywhere, which
// stays where it is let go; a puck thrown along a track to the stop where
// the throw would carry it, on a spring; a sheet pulled up or down to its
// heights, rubber-banded past them; words dragged into a basket, which
// lights up when one is over it and takes it in, the word flying to its
// place there (matchedGeometryEffect); a click on one takes it back.
// Run: myphp run -d macos examples/drag
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\Content;

require __DIR__ . '/../../src/autoload.php'; // MyPHP, and its declarative views (VStack, Text…)

/** Where the card, the puck and the sheet rest, and how far the pointer drags them now; the words in the row and in the basket. */
final class Board
{
    public float $cardX = 0, $cardY = 0, $puck = 0, $sheet = 180;
    public CGSize $card;
    public float $puckDrag = 0, $sheetDrag = 0;
    public bool $holding = false, $targeted = false;
    /** @var list<string> */
    public array $words = ['apple', 'pear', 'plum'], $basket = [];

    public function __construct() { $this->card = CGSize::zero(); }

    /** The stop of $stops nearest $x. @param list<float> $stops */
    public static function nearest(array $stops, float $x): float
    {
        usort($stops, fn($a, $b) => abs($a - $x) <=> abs($b - $x));
        return $stops[0];
    }

    /** $v within $lo and $hi, stretching past them as a rubber band does, as a scroll view past its ends. */
    public static function rubberBand(float $v, float $lo, float $hi): float
    {
        $over = fn(float $d) => 60 * (1 - 1 / ($d / 60 * 0.55 + 1));
        return $v < $lo ? $lo - $over($lo - $v) : ($v > $hi ? $hi + $over($v - $hi) : $v);
    }

    /** Moves $word from the row to the basket, or back. */
    public function move(string $word, bool $toBasket): void
    {
        [$from, $to] = $toBasket ? ['words', 'basket'] : ['basket', 'words'];
        if (!in_array($word, $this->$from, true)) { return; }
        $this->$from = array_values(array_diff($this->$from, [$word]));
        $this->$to[] = $word;
    }
}

$s = new Board();
App::setName('Drag');
App::whenReady(function () use ($s) {
    new Window(new WindowOptions(title: 'MyPHP native — Drag', width: 640, height: 720,
        content: Content::file(__DIR__ . '/view.php', $s)));
});
App::run();
