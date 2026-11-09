<?php
// Charts in native UI (mygo's gallery Charts page), as Swift Charts': a
// bar chart whose month the pointer chooses; a live line whose points the
// pointer and the arrows choose; stacked areas; a scatter plot; a donut
// whose sector the pointer chooses.
// Run: myphp run -d macos examples/charts
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\Content;

// MyPHP, and its declarative views (VStack, Text…): loaded already in a phone's app, whose launcher loads them
class_exists(\MyPHP\App::class) || require __DIR__ . '/../../src/autoload.php';

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
    /** The month and the second the pointer is on, and the share of the donut, null for none. */
    public ?string $month = null;
    public ?\DateTimeImmutable $at = null;
    public ?float $angle = null;
    public int $stacking = 0;
    /** @var list<float> the last minute of samples, one a second */
    public array $samples = [];
    /** When the last sample was taken. */
    public \DateTimeImmutable $now;
    /** @var list<list<array{0: float, 1: float}>> weight and fuel use, by class */
    public array $scatter = [[], [], []];
    public ?Window $window = null;

    public function __construct()
    {
        $this->now = new \DateTimeImmutable('@' . time());
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
        $this->now = new \DateTimeImmutable('@' . time());
    }

    /** When sample $i was taken, a second apart up to the last. */
    public function timeOf(int $i): \DateTimeImmutable
    {
        return $this->now->modify(($i - count($this->samples) + 1) . ' seconds')->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    /** The platform of the donut's sector at $angle along their shares' sum. */
    public function platformAt(?float $angle): ?int
    {
        if ($angle === null) { return null; }
        $sum = 0;
        foreach (SHARES as $i => $v) { $sum += $v; if ($angle <= $sum) { return $i; } }
        return null;
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
