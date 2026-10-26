<?php
// A piano in native UI that plays with MyPHP\Audio (mygo's examples/piano):
// click or drag across its keys, play it from the keyboard, or have it play
// a tune. Its sound is synthesized, a sound per key, decoded nowhere; the
// keys are made one at a time after the window shows, and a key pressed
// before its turn is made as it is pressed.
// Run: ./run.sh piano
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\Audio\{Audio, Player, Sound};
use MyPHP\UI\{Content, Context, DisplayList, InputEvent, Key, Node, Painter, TextPainter, Theme};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

/** The keyboard spans three octaves, C3 to C6, in MIDI note numbers. */
const LOWEST = 48;
const HIGHEST = 84;
const SYNTH_RATE = 32000;
/** The keyboard's geometry, in points. */
const WHITE_WIDTH = 36;
const BLACK_WIDTH = 22;
const KEYBOARD_HEIGHT = 180;
const BLACK_HEIGHT = 110;

/** A tune in the public domain: notes as "E5:0.5", a name with its octave and a length in beats, "C4+E4+G4:1" for a chord, and "r:1" for a rest. */
const SONGS = [
    ['Twinkle, Twinkle', 100, '
        C4+C3:1 C4:1 G4+E3:1 G4:1 A4+F3:1 A4:1 G4+E3:2
        F4+D3:1 F4:1 E4+C3:1 E4:1 D4+G3:1 D4:1 C4+C3:2
        G4+E3:1 G4:1 F4+D3:1 F4:1 E4+C3:1 E4:1 D4+G3:2
        G4+E3:1 G4:1 F4+D3:1 F4:1 E4+C3:1 E4:1 D4+G3:2
        C4+C3:1 C4:1 G4+E3:1 G4:1 A4+F3:1 A4:1 G4+E3:2
        F4+D3:1 F4:1 E4+C3:1 E4:1 D4+G3:1 D4:1 C4+C3:2'],
    ['Ode to Joy', 120, '
        E4+C3:1 E4:1 F4:1 G4:1 G4+G3:1 F4:1 E4:1 D4:1
        C4+A3:1 C4:1 D4:1 E4:1 E4+G3:1.5 D4:0.5 D4:2
        E4+C3:1 E4:1 F4:1 G4:1 G4+G3:1 F4:1 E4:1 D4:1
        C4+A3:1 C4:1 D4:1 E4:1 D4+G3:1.5 C4:0.5 C4+C3:2'],
    ['Für Elise', 66, '
        E5:0.25 D#5:0.25 E5:0.25 D#5:0.25 E5:0.25 B4:0.25 D5:0.25 C5:0.25
        A4+A3:0.5 r:0.25 C4:0.25 E4:0.25 A4:0.25
        B4+E3:0.5 r:0.25 E4:0.25 G#4:0.25 B4:0.25
        C5+A3:0.5 r:0.25 E4:0.25 E5:0.25 D#5:0.25
        E5:0.25 D#5:0.25 E5:0.25 B4:0.25 D5:0.25 C5:0.25
        A4+A3:0.5 r:0.25 C4:0.25 E4:0.25 A4:0.25
        B4+E3:0.5 r:0.25 E4:0.25 C5:0.25 B4:0.25
        A4+A3:1.5'],
    ['Happy Birthday', 100, '
        G4:0.75 G4:0.25 A4+F3:1 G4:1 C5+E3:1 B4+G3:2
        G4:0.75 G4:0.25 A4+G3:1 G4:1 D5+F3:1 C5+E3:2
        G4:0.75 G4:0.25 G5+E3:1 E5:1 C5+A3:1 B4:1 A4+F3:1
        F5:0.75 F5:0.25 E5+C3:1 C5:1 D5+G3:1 C5+C3:2'],
];

/** Keys of a computer keyboard by position, for the notes of two octaves: the lower row and the one above it, as trackers lay them out. */
const COMPUTER_KEYS = [
    [Key::Z, 'Z'], [Key::S, 'S'], [Key::X, 'X'], [Key::D, 'D'], [Key::C, 'C'], [Key::V, 'V'],
    [Key::G, 'G'], [Key::B, 'B'], [Key::H, 'H'], [Key::N, 'N'], [Key::J, 'J'], [Key::M, 'M'],
    [Key::Q, 'Q'], [Key::N2, '2'], [Key::W, 'W'], [Key::N3, '3'], [Key::E, 'E'], [Key::R, 'R'],
    [Key::N5, '5'], [Key::T, 'T'], [Key::N6, '6'], [Key::Y, 'Y'], [Key::N7, '7'], [Key::U, 'U'],
    [Key::I, 'I'],
];

/**
 * A piano-like tone: partials a little sharper than harmonics, as a stiff
 * string's are, from two strings a cent apart, each dying away fast and
 * then slowly, the higher the faster, with the thump of a hammer.
 * @return list<float>
 */
function synthSamples(int $note): array
{
    $g = synthSteps($note);
    foreach ($g as $_) {}
    return $g->getReturn();
}

/**
 * synthSamples a partial at a time: it yields after each, some 10 ms of
 * work, so that making the keys ahead keeps the window and the sound
 * going, and returns the samples.
 * @return \Generator<int, null, null, list<float>>
 */
function synthSteps(int $note): \Generator
{
    $f = 440 * 2 ** (($note - 69) / 12);
    $dur = 1.4 + 2.6 * (HIGHEST - $note) / (HIGHEST - LOWEST); // low strings ring longer
    $n = (int)($dur * SYNTH_RATE);
    $out = array_fill(0, $n, 0.0);
    $stiffness = 0.00008 * 2 ** (($note - LOWEST) / 12);
    for ($k = 1; $k <= 18; $k++) {
        $fk = $k * $f * sqrt(1 + $stiffness * $k * $k);
        if ($fk > SYNTH_RATE * 0.45) { break; }
        $amp = 1 / $k ** 1.1;
        $fast = 0.3 * (261.6 / $f) ** 0.5 / (1 + 0.3 * ($k - 1));
        $slow = $fast * 7;
        $d1 = exp(-1 / ($fast * SYNTH_RATE)); $d2 = exp(-1 / ($slow * SYNTH_RATE));
        // Both strings at once: a rotation for each sine and products for the decays, which sin and exp for each sample would make slow.
        $wa = 2 * M_PI * $fk / SYNTH_RATE; $wb = $wa * 1.0006;
        $ca = cos($wa); $sa = sin($wa); $cb = cos($wb); $sb = sin($wb);
        $s1 = 0.0; $c1 = 1.0; $s2 = 0.0; $c2 = 1.0; $e1 = 0.65; $e2 = 0.35;
        for ($i = 0; $i < $n; $i++) {
            $out[$i] += $amp * ($e1 + $e2) * ($s1 + $s2);
            [$s1, $c1] = [$s1 * $ca + $c1 * $sa, $c1 * $ca - $s1 * $sa];
            [$s2, $c2] = [$s2 * $cb + $c2 * $sb, $c2 * $cb - $s2 * $sb];
            $e1 *= $d1; $e2 *= $d2;
        }
        yield;
    }
    // The hammer: a short burst of noise, and an attack of 2 ms.
    $rng = \MyPHP\Random\Rand::pcg($note, 1); // Go's math/rand/v2, as mygo's: the same hammer
    $lp = 0.0;
    for ($i = 0, $m = min($n, intdiv(SYNTH_RATE, 20)); $i < $m; $i++) {
        $t = $i / SYNTH_RATE;
        $lp += 0.3 * ($rng->float64() * 2 - 1 - $lp);
        $out[$i] += 0.4 * $lp * exp(-$t / 0.006);
        $out[$i] *= min($t / 0.002, 1);
    }
    // The release of the last 50 ms, and every key as loud as another.
    $peak = 0.0; $tail = intdiv(SYNTH_RATE, 20);
    foreach ($out as $i => $v) {
        if (($r = $n - $i) < $tail) { $out[$i] = $v * $r / $tail; }
        $peak = max($peak, abs($v));
    }
    foreach ($out as $i => $v) { $out[$i] = 0.22 * $v / $peak; }
    return $out;
}

/** Whether $note is a black key. */
function black(int $note): bool { return '010100101010'[$note % 12] === '1'; }

function whites(): int
{
    $n = 0;
    for ($note = LOWEST; $note <= HIGHEST; $note++) { if (!black($note)) { $n++; } }
    return $n;
}

function keyboardWidth(): float { return whites() * WHITE_WIDTH; }

/** Where $note's key is on the keyboard: x, y, w, h. @return array{0: float, 1: float, 2: float, 3: float} */
function keyRect(int $note): array
{
    $w = 0;
    for ($n = LOWEST; $n < $note; $n++) { if (!black($n)) { $w++; } }
    return black($note) ? [$w * WHITE_WIDTH - BLACK_WIDTH / 2, 0.0, (float)BLACK_WIDTH, (float)BLACK_HEIGHT] : [(float)($w * WHITE_WIDTH), 0.0, (float)WHITE_WIDTH, (float)KEYBOARD_HEIGHT];
}

/** The note of the key at ($x, $y), black keys over white, or -1. */
function keyAt(float $x, float $y): int
{
    foreach ([true, false] as $blacks) {
        for ($note = LOWEST; $note <= HIGHEST; $note++) {
            if (black($note) !== $blacks) { continue; }
            [$kx, $ky, $kw, $kh] = keyRect($note);
            if ($x >= $kx && $x < $kx + $kw && $y >= $ky && $y < $ky + $kh) { return $note; }
        }
    }
    return -1;
}

/** The MIDI number of a name such as C4 or G#3. */
function noteNumber(string $name): int
{
    $semi = ['C' => 0, 'D' => 2, 'E' => 4, 'F' => 5, 'G' => 7, 'A' => 9, 'B' => 11][$name[0]] ?? throw new \InvalidArgumentException("piano: bad note $name");
    $rest = substr($name, 1);
    if (str_starts_with($rest, '#')) { $semi++; $rest = substr($rest, 1); }
    if (!ctype_digit($rest)) { throw new \InvalidArgumentException("piano: bad note $name"); }
    return ((int)$rest + 1) * 12 + $semi;
}

/** A song's events: the notes held, for a length in beats. @return list<array{0: list<int>, 1: float}> */
function events(string $notes): array
{
    $evs = [];
    foreach (preg_split('/\s+/', trim($notes)) as $tok) {
        [$name, $length] = explode(':', $tok);
        $evs[] = [$name === 'r' ? [] : array_map(noteNumber(...), explode('+', $name)), (float)$length];
    }
    return $evs;
}

final class Piano
{
    public ?Window $win = null;
    /** @var array<int, Sound> the keys' sounds, synthesized as they are first wanted */
    public array $sounds = [];
    /** @var array<int, Player> keys down, by note */
    public array $held = [];
    /** @var array<int, int> what holds each note: a count of keys, pointer and tune */
    public array $sources = [];
    /** The note the pointer holds, or -1; the sustain pedal (Space); where the keyboard's lower row starts; the tune playing, or null, and the notes it holds. */
    public int $pointer = -1;
    public bool $sustain = false;
    public int $octave = 4;
    public ?int $song = null;
    /** @var list<int> */
    public array $songHeld = [];
    /** @var array<int, int> the computer's keys held, with the note each pressed */
    public array $keysDown = [];

    /** The sound of $note's key, synthesized the first time. */
    public function sound(int $note): Sound
    {
        return $this->sounds[$note] ??= Audio::newSound(SYNTH_RATE, 1, synthSamples($note));
    }

    /** The key being made ahead, and its synthesis so far. */
    private int $ahead = -1;
    private ?\Generator $steps = null;

    /** Makes the keys not made yet, a partial each turn of the run loop, so that none waits once pressed. */
    public function synthesizeAhead(): void
    {
        if ($this->steps === null || isset($this->sounds[$this->ahead])) {
            $this->steps = null;
            for ($n = LOWEST; $n <= HIGHEST && isset($this->sounds[$n]); $n++);
            if ($n > HIGHEST) { return; }
            $this->ahead = $n;
            $this->steps = synthSteps($n);
            $this->steps->current();
        } else {
            $this->steps->next();
        }
        if (!$this->steps->valid()) {
            $this->sounds[$this->ahead] ??= Audio::newSound(SYNTH_RATE, 1, $this->steps->getReturn());
            $this->steps = null;
        }
        App::after(0.005, $this->synthesizeAhead(...));
    }

    /** Presses $note, which sounds until the last that holds it lets go. */
    public function down(int $note): void
    {
        if ($note < LOWEST || $note > HIGHEST) { return; }
        $this->sources[$note] = ($this->sources[$note] ?? 0) + 1;
        if ($this->sources[$note] > 1) { return; }
        if (isset($this->held[$note])) { self::damp($this->held[$note]); }
        $this->held[$note] = $this->sound($note)->play();
    }

    /** Lets go of $note: the damper stops it, unless the pedal is down. */
    public function up(int $note): void
    {
        if (($this->sources[$note] ?? 0) === 0) { return; }
        if (--$this->sources[$note] > 0) { return; }
        unset($this->sources[$note]);
        if (!$this->sustain && isset($this->held[$note])) {
            self::damp($this->held[$note]);
            unset($this->held[$note]);
        }
    }

    /** Presses or lifts the sustain pedal; lifting it damps the strings of the keys up. */
    public function pedal(bool $down): void
    {
        $this->sustain = $down;
        if ($down) { return; }
        foreach ($this->held as $note => $pl) {
            if (($this->sources[$note] ?? 0) === 0) { self::damp($pl); unset($this->held[$note]); }
        }
    }

    /** Fades a string out over 150 ms, as a damper does, and stops it. */
    public static function damp(Player $pl, ?float $v = null, int $i = 9): void
    {
        $v ??= $pl->volume();
        $pl->setVolume($v * $i / 10);
        if ($i === 0) { $pl->close(); return; }
        App::after(0.015, fn() => self::damp($pl, $v, $i - 1));
    }

    public function input(InputEvent $ev): bool
    {
        switch ($ev->kind) {
            case InputEvent::KEY_DOWN:
            case InputEvent::KEY_UP:
                if (($ev->mods & (Key::COMMAND | Key::CONTROL | Key::OPTION)) !== 0) { return false; }
                $down = $ev->kind === InputEvent::KEY_DOWN;
                if ($ev->key === Key::SPACE) { $this->pedal($down); return true; }
                if ($ev->key === Key::LEFT || $ev->key === Key::RIGHT) {
                    if ($down && !$ev->repeat) { $this->octave = $ev->key === Key::LEFT ? max($this->octave - 1, 3) : min($this->octave + 1, 4); }
                    return true;
                }
                $off = $ev->key === Key::COMMA ? 12 : null; // the comma plays the C after M, as Q does
                foreach (COMPUTER_KEYS as $i => [$k]) { if ($k === $ev->key) { $off = $i; } }
                if ($off === null) { return false; }
                if ($ev->repeat) { return true; }
                if ($down) {
                    $note = ($this->octave + 1) * 12 + $off;
                    $this->keysDown[$ev->key] = $note;
                    $this->down($note);
                } elseif (isset($this->keysDown[$ev->key])) {
                    $note = $this->keysDown[$ev->key];
                    unset($this->keysDown[$ev->key]);
                    $this->up($note);
                }
                return true;
            case InputEvent::POINTER_DOWN:
            case InputEvent::POINTER_MOVE:
                if ($ev->kind === InputEvent::POINTER_MOVE && $ev->button !== 0) { return false; }
                $note = keyAt($ev->x, $ev->y); // dragging across the keys plays each it enters
                if ($note !== $this->pointer) {
                    if ($this->pointer >= 0) { $this->up($this->pointer); }
                    $this->pointer = $note;
                    if ($note >= 0) { $this->down($note); }
                }
                return true;
            case InputEvent::POINTER_UP:
                if ($this->pointer >= 0) { $this->up($this->pointer); $this->pointer = -1; }
                return true;
        }
        return false;
    }

    /** Plays song $i, pressing the keys on time: each event is scheduled from the start, not after the last, so the tune keeps its tempo. */
    public function play(int $i): void
    {
        $this->stop();
        $this->song = $i;
        [, $bpm, $notes] = SONGS[$i];
        $beat = 60 / $bpm; $at = 0.0;
        $start = microtime(true);
        $at = function (float $t, \Closure $fn) use ($i, $start): void {
            App::after(max(0.0, $start + $t - microtime(true)), function () use ($i, $fn): void {
                if ($this->song === $i) { $fn(); $this->win?->invalidate(); }
            });
        };
        $t = 0.0;
        foreach (events($notes) as [$ns, $beats]) {
            $length = $beats * $beat;
            $at($t, fn() => $this->songNotes($ns, true));
            $at($t + $length * 0.9, fn() => $this->songNotes($ns, false)); // held for most of its length, as a pianist would
            $t += $length;
        }
        $at($t, function (): void { $this->song = null; });
    }

    /** Presses or lets go of the notes a song holds. @param list<int> $notes */
    public function songNotes(array $notes, bool $down): void
    {
        foreach ($notes as $n) {
            if ($down) {
                $this->down($n);
                $this->songHeld[] = $n;
            } else {
                $this->up($n);
                if (($k = array_search($n, $this->songHeld, true)) !== false) { array_splice($this->songHeld, $k, 1); }
            }
        }
    }

    /** Stops the song playing, and lets go of what it holds. */
    public function stop(): void
    {
        $this->song = null;
        foreach ($this->songHeld as $n) { $this->up($n); }
        $this->songHeld = [];
    }

    public function draw(DisplayList $list, Node $n, Theme $t, TextPainter $text): void
    {
        $pt = new Painter($list, $text, $t);
        $labels = [];
        foreach (COMPUTER_KEYS as $i => [, $label]) { $labels[($this->octave + 1) * 12 + $i] = $label; }
        $s = $n->w / keyboardWidth(); // the keyboard shrinks with the window
        $at = fn(array $k) => [$n->x + $k[0] * $s, $n->y + $k[1] * $s, $k[2] * $s, $k[3] * $s];
        foreach ([false, true] as $blacks) {
            for ($note = LOWEST; $note <= HIGHEST; $note++) {
                if (black($note) !== $blacks) { continue; }
                $k = $at(keyRect($note));
                $sounding = ($this->sources[$note] ?? 0) > 0;
                if (!$blacks) {
                    $pt->fill($k, $sounding ? Theme::mix($t->accent, 0xfbfbf8, 0.55) : 0xfbfbf8, 4 * $s);
                    $pt->stroke($k, 0xa1a1aa, 4 * $s, 1);
                    if (isset($labels[$note])) { $pt->text($k[0] + $k[2] / 2 - 4 * $s, $k[1] + $k[3] - 24 * $s, $labels[$note], 11 * $s, 0x71717a); }
                    if ($note % 12 === 0) { $pt->text($k[0] + 4 * $s, $k[1] + $k[3] - 42 * $s, 'C' . (intdiv($note, 12) - 1), 10 * $s, 0xa1a1aa); }
                } else {
                    $pt->fill($k, $sounding ? $t->accent : 0x18181b, 3 * $s);
                    if (isset($labels[$note])) { $pt->text($k[0] + $k[2] / 2 - 4 * $s, $k[1] + $k[3] - 20 * $s, $labels[$note], 10 * $s, 0xd4d4d8); }
                }
            }
        }
    }
}

function view(Context $c, Piano $p): void
{
    $t = $c->theme;
    $c->column(20, 18, 1)->align(Node::CENTER)->justify(Node::CENTER);
      $c->row(0, 8);
        foreach (SONGS as $i => [$name]) {
            if ($c->clicked($c->key($name)->button($name))) { $p->play($i); }
        }
        $stop = $c->key('stop')->button('Stop');
        $stop->disabled = $p->song === null;
        if ($c->clicked($stop) && $p->song !== null) { $p->stop(); }
      $c->end();
      $kb = $c->box(keyboardWidth(), KEYBOARD_HEIGHT, 0)->focusable()->autoFocus()->label('Piano')
          ->handleInput($p->input(...))->draw($p->draw(...));
      $kb->color = null;
      $c->text("Keys Z–M and Q–I play from C{$p->octave} · Space sustains · ← → change octaves", 12, $t->textMuted);
      if (($err = Audio::err()) !== null) { $c->text($err->getMessage(), 12, $t->textMuted); }
    $c->end();
}

$s = new Piano();
App::setName('Piano');
App::whenReady(function () use ($s) {
    $s->win = new Window(new WindowOptions(title: 'Piano', width: (int)keyboardWidth() + 48, height: 330,
        content: Content::view(fn(Context $c) => view($c, $s))));
    App::after(0.2, $s->synthesizeAhead(...)); // after the window shows
});
App::run();
