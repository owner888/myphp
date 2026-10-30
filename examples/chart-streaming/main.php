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
use MyPHP\UI\{Content, Context, DisplayList, Node, Painter, Path, TextPainter, Theme};
use MyPHP\UI\Chart\{Axes, Chart, Linear, Side, Tick};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

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

function view(Context $c, Waveform $s): void
{
    $s->update($c->now());
    $c->animationFrame();
    $t = $c->theme;
    $x = new Linear(-15, 0);
    $y = new Linear(0, 1);
    $label = fn(Side $side, Tick $tick) => $c->text($tick->label, 11, $t->textMuted)->noWrap();
    $c->column(8, 8, 1, $t->background)->fill();
      Chart::plot($c, new Axes(left: $y->ticks(5), bottom: $x->ticks(5), label: $label), function (Node $area) use ($s, $y, $t): void {
          $area->description = '15 seconds at 1000 samples per second per series';
          $area->label('Three live waveforms')->draw(function (DisplayList $list, Node $n, Theme $th, TextPainter $text) use ($s, $y, $t): void {
              $p = new Painter($list, $text, $th);
              $r = [$n->x, $n->y, $n->w, $n->h];
              foreach ($y->ticks(5) as $tick) { $p->line($r[0], Chart::y($r, $tick->at), $r[0] + $r[2], Chart::y($r, $tick->at), 1, $t->border); }
              $p->clip($r, function () use ($p, $r, $s): void {
                  foreach (COLORS as $k => $color) {
                      if ($s->mode === 'raw') {
                          $p->strokePath(Chart::line($r, $s->xs, $s->ys[$k]), 2, $color);
                          continue;
                      }
                      // The extremes of each pixel column.
                      [$xs, $ys] = Chart::minMax($s->xs, $s->ys[$k], (int)ceil($r[2] * $p->scale()));
                      if ($s->mode === 'path') {
                          $p->strokePath(Chart::lineInto($s->paths[$k], $r, $xs, $ys), 2, $color);
                          continue;
                      }
                      $points = [];
                      foreach ($xs as $i => $f) { $points[] = [Chart::x($r, $f), Chart::y($r, $ys[$i])]; }
                      $p->strokePolyline($points, 2, $color);
                  }
              });
          });
      })->fill();
    $c->end();
}

$mode = $argv[1] ?? 'polyline';
if (!in_array($mode, ['polyline', 'path', 'raw'], true)) {
    fwrite(STDERR, "unknown mode $mode: polyline, path or raw\n");
    exit(2);
}
$s = new Waveform($mode);
App::setName('Streaming chart');
App::whenReady(function () use ($s): void {
    new Window(new WindowOptions(title: 'Streaming chart', width: 1000, height: 600, content: Content::view(fn(Context $c) => view($c, $s))));
});
App::run();
