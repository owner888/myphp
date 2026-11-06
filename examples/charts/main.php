<?php
// Charts in native UI (mygo's gallery Charts page), built with UI\Chart:
// scales, axes and marks without a look, the marks nodes styled here, and
// lines, areas and slices paths these cards paint. A bar chart whose bars
// grow to new values and choose a month; a live line whose points the
// pointer and the arrows choose; stacked areas; a scatter plot; a donut.
// Run: ./run.sh charts
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\Content;

require __DIR__ . '/../../src/autoload.php'; // MyPHP, and its declarative views (VStack, Text…)

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
/** The series' colors, in light and dark windows alike. */
const COLORS = [0x3b82f6, 0x10b981, 0xf59e0b, 0xec4899];
const SOURCES = ['Search', 'Social', 'Direct'];
const VISITS = [
    [30, 34, 38, 36, 42, 47, 45, 52, 58, 55, 61, 66],
    [12, 15, 13, 19, 24, 22, 28, 31, 27, 35, 38, 36],
    [20, 19, 22, 21, 23, 22, 25, 24, 27, 26, 28, 30],
];
const PLATFORMS = ['macOS', 'Windows', 'Linux', 'Browsers'];
const SHARES = [46, 31, 14, 9];

/** The page's state: the sales and the month clicked, the samples of the live line and the point chosen, how the areas stack, the scatter's cars. */
final class Charts
{
    /** @var list<float> */
    public array $sales = [];
    public int $month = -1, $selected = -1, $stacking = 0;
    /** @var list<float> the last minute of samples, one a second */
    public array $samples = [];
    /** @var list<list<array{0: float, 1: float}>> weight and fuel use, by class */
    public array $scatter = [[], [], []];
    public ?Window $window = null;

    public function __construct()
    {
        mt_srand(12);
        foreach (MONTHS as $i => $_) {
            $this->sales[] = 40 + 50 * sin($i / 3.5) + 20 * mt_rand() / mt_getrandmax();
        }
        foreach ($this->scatter as $k => $_) {
            for ($i = 0; $i < 24; $i++) {
                $w = 1100 + 450 * $k + 170 * self::normal();
                $this->scatter[$k][] = [$w, 3 + $w / 420 + 0.9 * self::normal()];
            }
        }
        for ($i = 0; $i < 60; $i++) {
            $this->sample();
        }
    }

    private static function normal(): float
    {
        return sqrt(-2 * log(max(1e-9, mt_rand() / mt_getrandmax()))) * cos(2 * M_PI * mt_rand() / mt_getrandmax());
    }

    /** Takes a sample, which wanders. */
    public function sample(): void
    {
        $last = end($this->samples);
        $this->samples[] = max(0.05, min(0.95, ($last === false ? 0.5 : $last) + 0.12 * (mt_rand() / mt_getrandmax() - 0.5)));
        $this->samples = array_slice($this->samples, -60);
    }
}

$charts = new Charts;
App::whenReady(function () use ($charts): void {
    $charts->window = new Window(new WindowOptions(title: 'Charts', width: 1080, height: 760, content: Content::file(__DIR__ . '/view.php', $charts)));
    $tick = function () use (&$tick, $charts): void { // a sample a second
        $charts->sample();
        $charts->window?->invalidate();
        App::after(1, $tick);
    };
    App::after(1, $tick);
});
App::run();
