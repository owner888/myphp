<?php
// A window in the style of a native macOS app, in native UI (mygo's
// examples/vibrancy): a sidebar over a translucent material, under a hidden
// title bar whose window controls sit over the view, inset over the
// sidebar. Pick a material in the sidebar to see it behind the window.
// Run: ./run.sh vibrancy
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, Context, Font, Node, SVG, ScrollState};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

const TITLE_BAR_HEIGHT = 52; // the height of an inset title bar
const SIDEBAR_WIDTH = 220;

function icon(string $shapes): SVG
{
    return SVG::parse('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round">' . $shapes . '</svg>');
}

/** The materials the sidebar offers: [value of WindowOptions::$vibrancy, label, what it is for], by section. */
function sections(): array
{
    return [
        ['Window areas', icon('<rect x="1.5" y="2.5" width="13" height="11" rx="2"/><path d="M1.5 5.5h13"/>'), [
            ['sidebar', 'Sidebar', 'The background of sidebars, like this one.'],
            ['titlebar', 'Title bar', 'The background of title bars and toolbars.'],
            ['header', 'Header', 'Headers and footers inside content.'],
            ['window', 'Window', 'The background of opaque windows.'],
            ['content', 'Content', 'The background of opaque content.'],
            ['under-window', 'Under window', 'What shows under the background of a window.'],
            ['under-page', 'Under page', 'The area behind the pages of a document.'],
        ]],
        ['Transient UI', icon('<path d="M3 2.5h10A1.5 1.5 0 0 1 14.5 4v6a1.5 1.5 0 0 1-1.5 1.5H7.5L4.5 14v-2.5H3A1.5 1.5 0 0 1 1.5 10V4A1.5 1.5 0 0 1 3 2.5z"/>'), [
            ['menu', 'Menu', 'The background of menus.'],
            ['popover', 'Popover', 'The background of popovers.'],
            ['hud', 'HUD', 'The background of heads-up displays.'],
            ['sheet', 'Sheet', 'The background of sheets.'],
            ['tooltip', 'Tooltip', 'The background of tooltips.'],
            ['selection', 'Selection', 'The highlight of selected content.'],
            ['fullscreen-ui', 'Full screen UI', 'The background of full screen modal interfaces.'],
        ]],
    ];
}

/** The state the window shows. */
final class Vibrancy
{
    public ?Window $win = null;
    /** The material chosen in the sidebar. */
    public string $chosen = 'sidebar';
    /** The sidebar is hidden. */
    public bool $collapsed = false;
    /** The material shows behind the pane too. */
    public bool $seeThrough = false;
    public ScrollState $scroll;
    /** @var array<string, ?bool> the sections, open */
    public array $open = [];
    public SVG $sidebarIcon;

    public function __construct()
    {
        $this->scroll = new ScrollState();
        $this->sidebarIcon = icon('<rect x="1.5" y="2.5" width="13" height="11" rx="2"/><path d="M6 2.5v11"/>');
    }

    /** @return array{string, string, string} */
    public function find(): array
    {
        foreach (sections() as [, , $items]) { foreach ($items as $m) { if ($m[0] === $this->chosen) { return $m; } } }
        return sections()[0][2][0];
    }
}

function view(Context $c, Vibrancy $a): void
{
    $t = $c->theme;
    // The material shows wherever the view draws no background, where the window shows one: the root draws none then, nor does the sidebar.
    $vibrant = $c->vibrancy();
    if ($vibrant) { $c->root->color = null; }
    $bar = $c->titleBar();
    $row = $c->row(0, 0, 1)->align(Node::STRETCH);
    $shown = $c->animate($row, 'sidebar', $a->collapsed ? 0 : 1, 0.25);
      // The sidebar slides out to the left as it hides.
      $w = SIDEBAR_WIDTH * $shown;
      $c->row()->size($w, 0)->shrink(0)->align(Node::STRETCH)->clip();
        $side = $c->column()->size(SIDEBAR_WIDTH, 0)->shrink(0)->margin(0, 0, 0, $w - SIDEBAR_WIDTH);
        if (!$vibrant) { $side->background($t->surface); }
          sidebar($c, $a, $vibrant);
        $c->end();
      $c->end();
      if ($w > 0) { $c->box(1, 0, $t->border); }
      pane($c, $a, $vibrant, 20 + (1 - $shown) * ($bar['left'] + 36));
    $c->end();
    // Next to the window controls on the left, whether the sidebar shows or not.
    $label = $a->collapsed ? 'Show Sidebar' : 'Hide Sidebar';
    $toggle = $c->key('toggle')->buttonBase()->absolute()->left($bar['left'] + 8)->top((TITLE_BAR_HEIGHT - 28) / 2)->size(32, 28)->round(6)->label($label)->tooltip($label);
    if ($c->hovered($toggle)) { $toggle->background($t->surfaceHover); }
      $c->icon($a->sidebarIcon, 16, $t->textMuted);
    $c->end();
    if ($c->clicked($toggle)) { $a->collapsed = !$a->collapsed; }
}

/** The materials to choose from, under the room the window controls take. */
function sidebar(Context $c, Vibrancy $a, bool $vibrant): void
{
    $c->box(0, TITLE_BAR_HEIGHT, 0)->fillWidth()->shrink(0)->dragWindow()->color = null;
    $list = $c->sidebar($a->chosen, function () use ($c, $a) {
        foreach (sections() as [$title, $icon, $items]) {
            $a->open[$title] ??= true;
            $c->sidebarSection($title, $a->open[$title], function () use ($c, $icon, $items) {
                foreach ($items as [$value, $label, $about]) { $c->sidebarItem($value, $label, $icon)->tooltip($about); }
            });
        }
    }, SIDEBAR_WIDTH)->grow(1)->label('Materials');
    if ($vibrant) { $list->color = null; }
    if ($c->changed($list)) { $a->win?->setVibrancy($a->chosen); }
}

/** The material chosen, and how to choose it in code; its title starts left of the window controls when the sidebar hides. */
function pane(Context $c, Vibrancy $a, bool $vibrant, float $titleLeft): void
{
    $t = $c->theme;
    [$value, $label, $about] = $a->find();
    $bar = $c->titleBar();
    $pane = $c->column(0, 0, 1)->min(0, 0);
    if (!$a->seeThrough || !$vibrant) { $pane->background($t->background); }
      $c->row()->size(0, TITLE_BAR_HEIGHT)->fillWidth()->shrink(0)->align(Node::CENTER)->padding(0, $bar['right'] + 20, 0, $titleLeft)->dragWindow();
        $c->text($label, 15, style: Font::BOLD)->singleLine();
      $c->end();
      $c->divider();
      $c->column(0, 0, 1)->scroll($a->scroll);
        $c->column(0, 16)->padding(24, 28)->maxWidth(560);
          $c->text($about, 15);
          $c->text("new WindowOptions(vibrancy: '$value')  // or \$win->setVibrancy('$value')", 12)->font('monospace')->padding(12, 14)->round(8)->background($t->surface)->selectable();
          $c->text('A material shows wherever the view draws no background: the sidebar has none, this pane has one.')->wrap(500);
          $c->checkbox('Show the material behind this pane too', $a->seeThrough)->disabled(!$vibrant);
          $c->text($vibrant ? 'The material is the system\'s, behind the window, and follows the appearance and the desktop.'
              : 'This window shows no material here, so the sidebar draws a background of its own: materials show on macOS.', 12, $t->textMuted)->wrap(500)->margin(8, 0, 0, 0);
        $c->end();
      $c->end();
    $c->end();
}

$s = new Vibrancy();
App::setName('Vibrancy');
App::whenReady(function () use ($s) {
    // The title bar hidden, the traffic lights inset over the sidebar; the material shows wherever the view draws no background (Context::vibrancy tells where it does).
    $s->win = new Window(new WindowOptions(title: 'Vibrancy', width: 880, height: 640, minWidth: 560, minHeight: 400, titleBarStyle: 'hiddenInset', vibrancy: 'sidebar',
        content: Content::view(fn(Context $c) => view($c, $s))));
});
App::run();
