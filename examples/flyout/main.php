<?php
// Flyouts from a small window of native UI (mygo's examples/flyout): a list
// of colors, a note to edit and an About box with the look of the system's
// popovers on macOS. They take the keyboard, and close as the user clicks
// elsewhere or presses Escape. They extend beyond the window, and flip or
// slide to stay on the screen.
// Run: ./run.sh flyout
declare(strict_types=1);

use MyPHP\{App, FlyoutOptions, Rectangle, Window, WindowOptions};
use MyPHP\UI\{Content, Context, Key, Node, TextState};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

const COLORS = [['Red', 0xff3b30], ['Orange', 0xff9500], ['Yellow', 0xffcc00], ['Green', 0x34c759], ['Blue', 0x007aff], ['Purple', 0xaf52de]];

/** The app's state, read and changed on the main thread by the views and the flyouts' callbacks. */
final class Flyouts
{
    public ?Window $win = null;
    /** The open list of colors, the open note editor and the open About box, or null. */
    public ?Window $list = null, $editor = null, $about = null;
    /** The chosen color, and the one the keys move to in the list. */
    public int $color = 0, $hover = 0;
    public TextState $note;

    public function __construct() { $this->note = new TextState(); }

    public function view(Context $c): void
    {
        $t = $c->theme;
        $c->column(16, 12)->fill()->center();
          $c->row(0, 8)->alignItems(Node::CENTER);
            $c->box(14, 14, COLORS[$this->color][1])->radius(7);
            $b = $c->key('color')->button(COLORS[$this->color][0] . ' ▾');
            if ($c->clicked($b)) { $this->toggleList(anchor($c, $b)); }
          $c->end();
          $b = $c->key('note')->button('Edit note…');
          if ($c->clicked($b)) { $this->openEditor(anchor($c, $b)); }
          $b = $c->key('about')->button('About');
          if ($c->clicked($b)) { $this->openAbout(anchor($c, $b)); }
          $c->text($this->note->text === '' ? 'No note' : $this->note->text, 12, $t->textMuted);
        $c->end();
    }

    /** Opens the list of colors below the button, or closes it. */
    private function toggleList(Rectangle $button): void
    {
        if ($this->list !== null) { $this->list->close(); return; }
        $this->hover = $this->color;
        $this->list = Window::flyout(new FlyoutOptions(parent: $this->win, anchor: $button, gap: 4, width: 180, height: count(COLORS) * 28 + 12,
            focusable: true, shadow: true, content: Content::view($this->listView(...))));
        $this->list->on('closed', function () { $this->list = null; $this->win->invalidate(); });
        $this->win->invalidate();
    }

    public function listView(Context $c): void
    {
        $t = $c->theme;
        $n = count(COLORS);
        if ($c->shortcut(Key::DOWN)) { $this->hover = ($this->hover + 1) % $n; }
        if ($c->shortcut(Key::UP)) { $this->hover = ($this->hover + $n - 1) % $n; }
        if ($c->shortcut(Key::RETURN)) { $this->choose($this->hover); }
        $c->root->color = null;
        panel($c)->padding(6);
          foreach (COLORS as $i => [$name, $color]) {
              $row = $c->key($i)->row(0, 8)->height(28)->padding(0, 8)->alignItems(Node::CENTER)->radius(6);
              if ($i === $this->hover) { $row->background($t->accent); }
              if ($c->hovered($row) && $this->hover !== $i) { $this->hover = $i; $c->runtime?->animate(); }
              if ($c->clicked($row)) { $this->choose($i); }
                $c->box(12, 12, $color)->radius(6);
                $text = $c->text($name);
                if ($i === $this->hover) { $text->textColor($t->accentText); }
              $c->end();
          }
        $c->end();
    }

    private function choose(int $i): void
    {
        $this->color = $i;
        $this->list?->close();
        $this->win->invalidate();
    }

    /** Opens the note editor to the right of the button, which takes the keyboard. */
    private function openEditor(Rectangle $button): void
    {
        if ($this->editor !== null) { return; }
        $this->editor = Window::flyout(new FlyoutOptions(parent: $this->win, anchor: $button, placement: FlyoutOptions::RIGHT_START, gap: 8, width: 260, height: 96,
            focusable: true, shadow: true, content: Content::view($this->editorView(...))));
        $this->editor->on('closed', function () { $this->editor = null; $this->win->invalidate(); });
    }

    public function editorView(Context $c): void
    {
        $c->root->color = null;
        panel($c)->padding(12)->gap(8);
          $c->text('Note')->bold();
          $c->key('note')->input($this->note, 'Write something', 0)->autoFocus();
          if ($c->shortcut(Key::RETURN)) { $this->editor?->close(); }
        $c->end();
    }

    /** Opens the About box above the button, as a popover on macOS. */
    private function openAbout(Rectangle $button): void
    {
        if ($this->about !== null) { return; }
        $this->about = Window::flyout(new FlyoutOptions(parent: $this->win, anchor: $button, placement: FlyoutOptions::TOP, gap: 6, width: 240, height: 84,
            focusable: true, shadow: true, popover: true, content: Content::view($this->aboutView(...))));
        $this->about->on('closed', function () { $this->about = null; });
    }

    public function aboutView(Context $c): void
    {
        $c->root->color = null;
        $box = $c->vibrancy() ? $c->column()->fill() : panel($c); // over the popover's material; without one, a panel
        $box->padding(12)->gap(4);
          $c->text('Flyout')->bold();
          $c->text('Windows owned by their parent, placed next to an anchor.', 12, $c->theme->textMuted);
        $c->end();
    }
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
    $a->win = new Window(new WindowOptions(title: 'Flyout', width: 300, height: 240, content: Content::view($a->view(...))));
});
App::run();
