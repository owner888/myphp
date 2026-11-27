<?php
// Where views overlap, a tap goes to the one on top, as SwiftUI's topmost view takes it: the one a ZStack builds last,
// or the one of the higher zIndex; a button inside a view taking taps takes its own; allowsHitTesting(false) lets it through.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Content, Context};

require_once __DIR__ . '/../src/UI/Declarative/functions.php';

$tapped = function (\Closure $body): array {
    $log = new \ArrayObject();
    $h = new Headless(fn(Context $c) => Content::render(fn() => $body($log), $c), 300, 300);
    $h->frame(2);
    $h->click(150, 150);
    $h->frame(2);
    return $log->getArrayCopy();
};
$button = fn(string $name, \ArrayObject $log) => Button($name, action: fn() => $log[] = $name)->frame(width: 200, height: 200);

same(['B'], $tapped(fn($log) => ZStack()($button('A', $log), $button('B', $log))), 'ZStack(A, B): B, on top, takes the tap');
same(['A'], $tapped(fn($log) => ZStack()($button('A', $log)->zIndex(1), $button('B', $log))), 'A of zIndex 1 is on top, and takes it');
same(['A'], $tapped(fn($log) => ZStack()($button('A', $log), $button('B', $log)->allowsHitTesting(false))), 'B not hit-testable: A under it takes it');
same(['Inner'], $tapped(fn($log) => VStack()($button('Inner', $log))->frame(width: 300, height: 300)->onTapGesture(fn() => $log[] = 'Outer')),
    'a button inside a view taking taps takes its own');
same(['Outer'], $tapped(fn($log) => VStack()(Text('Label'))->frame(width: 300, height: 300)->onTapGesture(fn() => $log[] = 'Outer')),
    'a tap on a label in it goes to the view around');
same(['A'], $tapped(fn($log) => ZStack()($button('A', $log))), 'a single button takes it');

// A stroked shape ringing a button as its overlay (a card's or a field's border) leaves the tap to the button, with no
// allowsHitTesting(false): a tap goes to the topmost view there that takes taps.
same(['A'], $tapped(fn($log) => ZStack()($button('A', $log)->overlay(RoundedRectangle(cornerRadius: 12)->stroke(\Color::gray, lineWidth: 1)))),
    'a stroked overlay: the button under it takes the tap');
