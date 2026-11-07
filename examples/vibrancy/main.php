<?php
// A window in the style of a native macOS app, in native UI (mygo's
// examples/vibrancy): a sidebar over a translucent material, under a hidden
// title bar whose window controls sit over the view, inset over the
// sidebar. Pick a material in the sidebar to see it behind the window.
// Run: ./run.sh vibrancy
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, SVG};

require __DIR__ . '/../../src/autoload.php'; // MyPHP, and its declarative views (VStack, Text…)


function icon(string $shapes): SVG
{
    return SVG::parse('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round">' . $shapes . '</svg>');
}

/** The materials the sidebar offers: [value of WindowOptions::$vibrancy, label, what it is for], by section; made once, as its icons are parsed SVGs, whose trees are cycles to collect. */
function sections(): array
{
    static $sections = null;
    return $sections ??= [
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
    /** Whether the sidebar shows. */
    public NavigationSplitViewVisibility $visibility = NavigationSplitViewVisibility::all;
    /** The material shows behind the pane too. */
    public bool $seeThrough = false;
    /** The window shows a material: macOS's. */
    public bool $vibrant = PHP_OS_FAMILY === 'Darwin';

    /** @return array{string, string, string} */
    public function find(): array
    {
        foreach (sections() as [, , $items]) { foreach ($items as $m) { if ($m[0] === $this->chosen) { return $m; } } }
        return sections()[0][2][0];
    }
}

$s = new Vibrancy();
App::setName('Vibrancy');
App::whenReady(function () use ($s) {
    // The title bar hidden, the traffic lights inset over the sidebar; the material shows wherever the view draws no background (Context::vibrancy tells where it does).
    $s->win = new Window(new WindowOptions(title: 'Vibrancy', width: 880, height: 640, minWidth: 560, minHeight: 400, titleBarStyle: 'hiddenInset', vibrancy: 'sidebar',
        content: Content::file(__DIR__ . '/view.php', $s)));
});
App::run();
