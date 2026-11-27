<?php
// A rounded clip inside another, as SwiftUI clips both: a picture clipped to a circle in a rounded card is round,
// its own mask taking the card's place where it lies wholly inside the card (the card's would cut no more of it).
// Where it reaches into a corner of the card rounded more than it, the card's mask stays: there the card cuts more.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\UI\{Content, Context};

require_once __DIR__ . '/../src/UI/Declarative/functions.php';

/** The mask a Canvas inside $view paints through: [its width, its top left radius]. */
$masked = function (\Closure $view): array {
    $seen = new \ArrayObject();
    $probe = fn() => Canvas(function (\GraphicsContext $g) use ($seen) { $g->withPainter(function (\MyPHP\UI\Painter $p) use ($seen) { $seen['mask'] = $p->displayList()->mask(); }); }); // made each frame, as a view is
    $h = new Headless(fn(Context $c) => Content::render(fn() => $view($probe), $c), 300, 300);
    $h->frame(2); $h->paint();
    $m = $seen['mask'] ?? [];
    return [$m[2] ?? null, $m[4] ?? null];
};
$card = fn(\Alignment $a, $content) => $content->frame(width: 200, height: 200, alignment: $a)->background(\Color::gray)->clipShape(RoundedRectangle(cornerRadius: 16)); // a box around it, $content placed in it by $a

same([40.0, 20.0], $masked(fn($probe) => $card(\Alignment::center, $probe()->frame(width: 40, height: 40)->clipShape(Circle()))),
    'a circle in the middle of a rounded card: round, its own mask');
same([40.0, 16.0], $masked(fn($probe) => $card(\Alignment::topLeading, $probe()->frame(width: 40, height: 40)->clipShape(RoundedRectangle(cornerRadius: 16)))),
    'at the card\'s corner, rounded as much as it: its own mask');
same([200.0, 16.0], $masked(fn($probe) => $card(\Alignment::topLeading, $probe()->frame(width: 40, height: 40)->clipShape(RoundedRectangle(cornerRadius: 4)))),
    'at the card\'s corner, rounded less: the card\'s mask, which cuts more there');
