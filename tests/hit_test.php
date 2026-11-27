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

// What draws over a button takes the tap, as SwiftUI's views that draw do: a Color, a Text, an Image, a filled shape, a
// background; the view around it up to one taking taps gets it, the button covered none. Color.clear, a stack's empty
// room, an opacity of 0 and allowsHitTesting(false) let it through; a button's own label is the button's.
$over = fn(\ArrayObject $log, $cover) => ZStack()($button('A', $log), $cover);
same([], $tapped(fn($log) => $over($log, Color(\Color::blue)->frame(width: 200, height: 200))), 'a Color over it takes the tap');
same([], $tapped(fn($log) => $over($log, RoundedRectangle(cornerRadius: 12)->fill(\Color::gray)->frame(width: 200, height: 200))), 'a filled shape over it takes the tap');
same([], $tapped(fn($log) => $over($log, Text('Over'))), 'a Text over it takes the tap where it is');
same([], $tapped(fn($log) => $over($log, VStack()(Spacer())->frame(width: 200, height: 200)->background(\Color::white))), 'a background over it takes the tap');
same(['A'], $tapped(fn($log) => $over($log, Color(\Color::clear)->frame(width: 200, height: 200))), 'Color.clear lets it through');
same(['A'], $tapped(fn($log) => $over($log, VStack()(Spacer())->frame(width: 200, height: 200))), 'a stack\'s empty room lets it through');
same(['A'], $tapped(fn($log) => $over($log, Color(\Color::blue)->frame(width: 200, height: 200)->opacity(0))), 'a Color of opacity 0 lets it through');
same(['A'], $tapped(fn($log) => $over($log, Color(\Color::blue)->frame(width: 200, height: 200)->allowsHitTesting(false))), 'allowsHitTesting(false) lets it through');
same(['A'], $tapped(fn($log) => ZStack()(Button(action: fn() => $log[] = 'A', label: VStack()(Image(systemName: 'star'), Text('A')))->frame(width: 200, height: 200))),
    'the label of a button is the button\'s');
same(['Outer'], $tapped(fn($log) => $over($log, Color(\Color::blue)->frame(width: 200, height: 200))->frame(width: 300, height: 300)->onTapGesture(fn() => $log[] = 'Outer')),
    'the view around what covers it, taking taps, gets the tap');
