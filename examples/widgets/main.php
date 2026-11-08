<?php
// The smaller widgets in one window: a switch, a segmented picker, tabs
// with their content, a disclosure group, steppers, a gauge, a combo box,
// suggestions, a token field, dates and a calendar, a color picker, a
// form, a toggle of toggles, breadcrumbs, alerts, a toast, icons, avatars,
// a list whose rows drag, a rating, a range slider and a popover: SwiftUI's
// where it has them, myphp's own (ComboBox, TokenField, Rating…) where it
// does not. What changed last shows at the bottom.
// Run: myphp run -d macos examples/widgets
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, SVG};

require __DIR__ . '/../../src/autoload.php'; // MyPHP, and its declarative views (VStack, Text…)

const FRUITS = ['Apple', 'Banana', 'Cherry', 'Grape', 'Mango', 'Orange', 'Peach', 'Pear', 'Pineapple', 'Plum'];
const CITIES = ['Beijing', 'Berlin', 'Boston', 'Chengdu', 'Chicago', 'Guangzhou', 'Hangzhou', 'London', 'Paris', 'Shanghai', 'Shenzhen', 'Tokyo'];
const TAGS = ['php', 'ui', 'metal', 'ffi', 'layout', 'text', 'input', 'native'];
const CRUMBS = ['Macintosh HD', 'Users', 'developer', 'Documents', 'Widgets'];
const LANGUAGES = ['en' => 'English', 'zh-Hans' => '简体中文', 'zh-Hant' => '繁體中文'];

/** The widgets' values, which the view binds; what changed last, as the bottom line says. */
final class Widgets
{
    public bool $wifi = true, $more = false, $asking = false, $resetting = false, $options = false, $bold = false, $italic = false;
    public bool $mail = true, $calendar = false, $notify = true;
    public int $view = 0, $tab = 0, $count = 3, $stars = 3, $crumb = 3, $size = 1, $color = 0x3b82f6;
    public float $scale = 1.0, $low = 20.0, $high = 70.0, $alpha = 1.0;
    public string $query = '', $fruit = 'Banana', $city = '', $name = '', $sort = 'Name', $chosen = '', $step = '';
    /** @var list<string> */
    public array $tags = ['php', 'ui'], $steps = ['Plan', 'Design', 'Build', 'Test', 'Ship'];
    /** @var array<string, bool> the sections of the tree open, and of the accordion */
    public array $open = ['src' => true], $sections = [true, false, false];
    public DateTimeImmutable $when;
    public ?string $toast = null;
    public string $last = 'nothing yet';
    /** @var array<string, SVG> parsed once, not in the view */
    public array $icons;

    public function __construct()
    {
        $this->when = new DateTimeImmutable('today 09:30');
        foreach (['check', 'star', 'bell', 'palette'] as $name) { $this->icons[$name] = SVG::fromFile(__DIR__ . "/icons/$name.svg"); }
    }

    /** What the bottom line reports a change of: the values, by name. @return array<string, mixed> */
    public function values(): array
    {
        $v = [];
        foreach (get_object_vars($this) as $k => $value) { $v[$k] = $value; } // copies: a property the views bind is a reference, which an array of them would share with the frame before
        unset($v['last'], $v['toast'], $v['icons'], $v['asking'], $v['resetting'], $v['options']);
        return $v;
    }

    /** The bottom line, of the values that changed from $old to $new. @param array<string, mixed> $old @param array<string, mixed> $new */
    public function changed(array $old, array $new): void
    {
        foreach ($new as $k => $v) {
            if ($v == ($old[$k] ?? null)) { continue; }
            $this->last = $k . ' ' . match (true) {
                is_bool($v) => $v ? 'on' : 'off',
                is_array($v) => implode(', ', array_map(fn($x) => is_bool($x) ? (int)$x : (string)$x, $v)),
                $v instanceof DateTimeImmutable => $v->format('Y-m-d H:i'),
                is_float($v) => rtrim(rtrim(sprintf('%.2f', $v), '0'), '.'),
                default => (string)$v,
            };
        }
    }

    /** Moves the steps at $rows before the one at $to, as the list's rows were dragged. @param list<int> $rows */
    public function move(array $rows, int $to): void
    {
        $moving = array_map(fn($r) => $this->steps[$r], $rows);
        $out = []; $placed = false;
        foreach ($this->steps as $i => $step) {
            if (!$placed && $i >= $to) { array_push($out, ...$moving); $placed = true; }
            if (!in_array($i, $rows, true)) { $out[] = $step; }
        }
        if (!$placed) { array_push($out, ...$moving); }
        $this->steps = $out;
    }
}

$s = new Widgets();
App::setName('Widgets');
App::setLanguages('en', 'zh-Hans', 'zh-Hant'); // the languages the example is written in: MyPHP's own strings are in them; the example's own stay English
App::whenReady(function () use ($s) {
    new Window(new WindowOptions(title: 'MyPHP native — widgets', width: 640, height: 760, content: Content::file(__DIR__ . '/view.php', $s)));
});
App::run();
