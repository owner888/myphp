<?php
// Three rolling waveforms of 15,000 samples each, 1,000 new samples a
// second, reduced to a few points per pixel column (mygo's
// examples/chart-streaming): every frame takes in the samples that came
// since the last, keeps the extremes of each column with Chart::minMax and
// strokes them with Painter::strokePolyline.
// Run: ./run.sh chart-streaming [polyline|path|raw]
//   path: minMax, then strokePath; raw: every sample, strokePath
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, Path};

require __DIR__ . '/../../src/autoload.php'; // MyPHP, and its declarative views (VStack, Text…)

const SAMPLES = 15000;
const COLORS = [0x288cf0, 0xf06446, 0x32be6e];

/** The rings of samples, the plot's fractions of the latest, and how they are drawn. */
final class Waveform
{
    /** @var list<list<float>> each series' ring */
    public array $data = [[], [], []];
    public int $next = SAMPLES;
    /** @var list<float> */
    public array $xs = [];
    /** @var list<list<float>> */
    public array $ys = [[], [], []];
    /** @var list<Path> kept, one a series, for "path" */
    public array $paths = [];
    public ?int $start = null;

    public function __construct(public readonly string $mode)
    {
        for ($k = 0; $k < 3; $k++) {
            for ($i = 0; $i < SAMPLES; $i++) { $this->data[$k][$i] = self::value($i, $k); }
            $this->paths[] = new Path;
        }
    }

    /** Sample $i of series $s: a sine, deterministic noise and isolated spikes, which minMax keeps. */
    public static function value(int $i, int $s): float
    {
        $x = $i / 1000;
        $n = (($i * 2654435761) ^ (($s + 1) * 40503)) & 0xffff; // noise from the index
        $v = 0.22 * sin($x * ($s + 1) * 7) + 0.08 * ($n / 65535 - 0.5);
        if ($i % 997 === $s * 31) { $v += 0.22; }
        return 0.2 + $s * 0.3 + $v * 0.55;
    }

    /** Fills the rings with the samples due by $now (nanoseconds), and lays the latest out as the plot's fractions. */
    public function update(int $now): void
    {
        $this->start ??= $now;
        $elapsed = ($now - $this->start) / 1e6; // milliseconds
        $target = SAMPLES + (int)$elapsed;
        for ($i = max($this->next, $target - SAMPLES); $i < $target; $i++) { // after a long frame, only the samples still shown
            for ($k = 0; $k < 3; $k++) { $this->data[$k][$i % SAMPLES] = self::value($i, $k); }
        }
        $this->next = $target;
        $phase = $elapsed - floor($elapsed);
        $from = $target - SAMPLES;
        for ($i = 0; $i < SAMPLES; $i++) {
            $this->xs[$i] = ($i - $phase) / (SAMPLES - 1);
            $j = ($from + $i) % SAMPLES;
            $this->ys[0][$i] = $this->data[0][$j]; $this->ys[1][$i] = $this->data[1][$j]; $this->ys[2][$i] = $this->data[2][$j];
        }
    }
}

$mode = $argv[1] ?? 'polyline';
if (!in_array($mode, ['polyline', 'path', 'raw'], true)) {
    fwrite(STDERR, "unknown mode $mode: polyline, path or raw\n");
    exit(2);
}
$s = new Waveform($mode);
App::setName('Streaming chart');
App::whenReady(function () use ($s): void {
    new Window(new WindowOptions(title: 'Streaming chart', width: 1000, height: 600, content: Content::file(__DIR__ . '/view.php', $s)));
});
App::run();
