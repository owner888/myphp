<?php
// Flyouts from a small window of native UI (mygo's examples/flyout): a list
// of colors, a note to edit and an About box with the look of the system's
// popovers on macOS. They take the keyboard, and close as the user clicks
// elsewhere or presses Escape. They extend beyond the window, and flip or
// slide to stay on the screen.
// Run: ./run.sh flyout
declare(strict_types=1);

use MyPHP\{App, Rectangle, Window, WindowOptions};
use MyPHP\UI\{Content, Context, Node, TextState};

require __DIR__ . '/../../src/autoload.php'; // MyPHP, and its declarative views (VStack, Text…)

const COLORS = [['Red', 0xff3b30], ['Orange', 0xff9500], ['Yellow', 0xffcc00], ['Green', 0x34c759], ['Blue', 0x007aff], ['Purple', 0xaf52de]];

/** The app's state, read and changed on the main thread by the views (view.php, and list.php, editor.php and about.php for the flyouts) and the flyouts' callbacks. */
final class Flyouts
{
    public ?Window $win = null;
    /** The open list of colors, the open note editor and the open About box, or null. */
    public ?Window $list = null, $editor = null, $about = null;
    /** The chosen color, and the one the keys move to in the list. */
    public int $color = 0, $hover = 0;
    public TextState $note;

    public function __construct() { $this->note = new TextState(); }
}

/** The box a flyout draws, the window itself drawing nothing: the window's shadow outlines it on macOS. */
function panel(Context $c): Node
{
    return $c->column()->fill()->background($c->theme->surface)->radius(10);
}

/** The box of $n as an anchor, in points from the top-left of the window's content. */
function anchor(Context $c, Node $n): Rectangle
{
    [$x, $y, $w, $h] = $c->bounds($n);
    return new Rectangle((int)$x, (int)$y, (int)$w, (int)$h);
}

$a = new Flyouts();
App::whenReady(function () use ($a) {
    $a->win = new Window(new WindowOptions(title: 'Flyout', width: 300, height: 240, content: Content::file(__DIR__ . '/view.php', $a)));
});
App::run();
