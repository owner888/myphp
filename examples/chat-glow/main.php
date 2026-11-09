<?php
// Chat-glow is an assistant's window, lit as Apple Intelligence lights its
// own (mygo's examples/chat-glow): while the assistant works, the window's
// edges glow in flowing colors, the field to write in glows in its ring,
// and "Thinking…" shimmers; the assistant's orb, a glass sphere of
// drifting light, quickens as it thinks.
//
// Each is a pattern of its own for an app. The edges' glow is views: the
// window's outline as borders painted with a conic gradient
// (borderConicGradient), a sharp one and blurred ones over it (blur), in
// boxes over the whole window that the pointer goes through (absolute,
// passThrough), as SwiftUI's recreations of the glow stack theirs. The
// rest is shaders written in PHP (UI\Shader): a glowing focus ring
// drawn around a field, text filled with a shader (Shader::text), and an
// avatar drawn by one. The replies are canned; Escape stops one.
// Run: myphp run -d macos examples/chat-glow
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, GradientStop, Painter, Span, Theme};
use MyPHP\UI\Shader\{Options, Shader, Uniform, Values};

// MyPHP, and its declarative views (VStack, Text…): loaded already in a phone's app, whose launcher loads them
class_exists(\MyPHP\App::class) || require __DIR__ . '/../../src/autoload.php';

// THINK_TIME is how long the assistant thinks before it writes, in
// seconds, and WORDS_PER_SECOND how fast it writes.
const THINK_TIME = 1.6;
const WORDS_PER_SECOND = 28;
// The glow comes up over FADE_IN seconds and goes over FADE_OUT; the orb's
// liveliness follows the work over LIVEN.
const FADE_IN = 0.35;
const FADE_OUT = 0.9;
const LIVEN = 0.5;
// The composer's ring: RING_PADDING DIPs of glow around the field, whose
// corners are FIELD_RADIUS.
const RING_PADDING = 12;
const FIELD_RADIUS = 16;
// MAX_WIDTH is the conversation's width at most.
const MAX_WIDTH = 660;
// JUMP_WORDS is how many words coming in one frame make the conversation
// jump to its end rather than glide there: a reply writes a word or so a
// frame.
const JUMP_WORDS = 12;
// REPLY_SIZE is the size of the replies' text and REPLY_LEADING its line
// height, which "Thinking…" takes too, so that the row keeps its height as
// the first words replace it.
const REPLY_SIZE = 15;
const REPLY_LEADING = 1.5;
// CORNER is the radius of the window's corners, which the edges' glow
// follows: macOS's.
const CORNER = 16;

// What the empty conversation offers to ask.
const SUGGESTIONS = ['Summarize my week', 'Draft a reply to the team', 'Plan three days in Kyoto'];

const REPLIES = [
    "Here's your week at a glance.\n\nYou shipped the new onboarding flow on Tuesday, and sign-ups are up 18% since. Thursday's design review settled the navigation question: tabs stay, the sidebar goes. Two things still need you: the Q3 budget draft is due Monday, and Priya is waiting on your feedback for the pricing page.",
    "Here's a draft:\n\nHi all, thanks for pushing through this sprint. The release is on track for Friday; the remaining risk is the migration, which Sam is testing tonight. If anything slips, we'll cut the export feature and ship it the week after. Let's keep tomorrow's stand-up to ten minutes.",
    "Day 1: Higashiyama. Start at Kiyomizu-dera early, walk down Sannenzaka to Gion, and end with dinner in Pontocho.\n\nDay 2: Arashiyama. The bamboo grove at sunrise, Tenryu-ji's garden, then the river by boat.\n\nDay 3: Fushimi Inari before the crowds, sake tasting in Fushimi, and a last evening at Nishiki Market.",
];

// The edge glow's colors, and how its stops move: every SHUFFLE_EVERY
// seconds they head for places of their own, picked at random, over
// SHUFFLE_TIME, easing in and out, as the SwiftUI recreations of Apple
// Intelligence's glow move theirs.
const GLOW_COLORS = [0xBC82F3, 0xF5B9EA, 0x8D9FFF, 0xFF6778, 0xFFBA71, 0xC686FF];
const SHUFFLE_EVERY = 0.5;
const SHUFFLE_TIME = 1.0;

// The edge glow's layers, as the SwiftUI recreation of Apple
// Intelligence's glow draws them (jacobamobin/AppleIntelligenceGlowEffect):
// its outline stroked inside the window's edge this wide and blurred this
// much, by SwiftUI's radius, whose standard deviation is 0.95 of it,
// measured.
const GLOW_LAYERS = [[6, 0], [9, 4], [11, 12], [15, 15]];

/**
 * A stop of the gradient: its color in Oklab, in which SwiftUI blends a
 * gradient's colors, and where it lies around the window, from 0 to 1.
 */
final class Stop
{
    public function __construct(public readonly float $l, public readonly float $a, public readonly float $b, public readonly float $at) {}
}

/** The gradient's stops as they move from where they were ($from) to where they head ($to), since when ($since), the next move due at $next. */
final class Stops
{
    /** @var list<Stop> */
    public array $from = [], $to = [];
    public float $since = 0, $next = 0;

    /** @param Closure(): float $random numbers from 0 to 1 */
    public function __construct(private readonly Closure $random)
    {
        $this->to = $this->shuffle();
        $this->from = $this->to;
    }

    /**
     * The colors at places picked at random, in order around the window:
     * the stop in each place takes another color as the order changes,
     * which the move blends.
     * @return list<Stop>
     */
    public function shuffle(): array
    {
        $out = [];
        foreach (GLOW_COLORS as $c) {
            [$l, $a, $b] = oklab($c);
            $out[] = new Stop($l, $a, $b, ($this->random)());
        }
        usort($out, fn(Stop $a, Stop $b) => $a->at <=> $b->at);
        return $out;
    }

    /** Starts the next move once it is due at $now, from where the stops show. */
    public function step(float $now): void
    {
        if ($now < $this->next) {
            return;
        }
        $this->from = $this->show($now);
        [$this->to, $this->since, $this->next] = [$this->shuffle(), $now, $now + SHUFFLE_EVERY];
    }

    /** The stops as they show at $now. @return list<Stop> */
    public function show(float $now): array
    {
        $k = ease(min(max(($now - $this->since) / SHUFFLE_TIME, 0), 1));
        $out = [];
        foreach ($this->to as $i => $t) {
            $f = $this->from[$i];
            $out[] = new Stop($f->l + ($t->l - $f->l) * $k, $f->a + ($t->a - $f->a) * $k, $f->b + ($t->b - $f->b) * $k, $f->at + ($t->at - $f->at) * $k);
        }
        return $out;
    }

    /**
     * The stops at $now as a gradient's: their colors at their places,
     * and at 0 and at 1 the color between the last and the first, blended
     * in Oklab, so that the colors go round without a seam where the
     * gradient starts.
     * @return list<GradientStop>
     */
    public function gradient(float $now): array
    {
        $st = $this->show($now);
        [$first, $last] = [$st[0], $st[count($st) - 1]];
        $k = (1 - $last->at) / max($first->at + 1 - $last->at, 1e-6);
        $seam = srgb($last->l + ($first->l - $last->l) * $k, $last->a + ($first->a - $last->a) * $k, $last->b + ($first->b - $last->b) * $k);
        $out = [new GradientStop($seam, 0)];
        foreach ($st as $x) {
            $out[] = new GradientStop(srgb($x->l, $x->a, $x->b), $x->at);
        }
        $out[] = new GradientStop($seam, 1);
        return $out;
    }
}

/** The color of Oklab's ($l, $a, $b) in sRGB. */
function srgb(float $l, float $a, float $b): int
{
    $lc = $l + 0.3963377774 * $a + 0.2158037573 * $b;
    $mc = $l - 0.1055613458 * $a - 0.0638541728 * $b;
    $sc = $l - 0.0894841775 * $a - 1.2914855480 * $b;
    [$lc, $mc, $sc] = [$lc * $lc * $lc, $mc * $mc * $mc, $sc * $sc * $sc];
    $enc = static function (float $v): int {
        $v = min(max($v, 0.0), 1.0);
        $v = $v <= 0.0031308 ? $v * 12.92 : 1.055 * $v ** (1 / 2.4) - 0.055;
        return (int)round($v * 255);
    };
    return $enc(4.0767416621 * $lc - 3.3077115913 * $mc + 0.2309699292 * $sc) << 16
        | $enc(-1.2684380046 * $lc + 2.6097574011 * $mc - 0.3413193965 * $sc) << 8
        | $enc(-0.0041960863 * $lc - 0.7034186147 * $mc + 1.7076147010 * $sc);
}

/** $rgb, in sRGB, in Oklab. @return array{0: float, 1: float, 2: float} */
function oklab(int $rgb): array
{
    $lin = static function (int $v): float {
        $x = $v / 255;
        return $x <= 0.04045 ? $x / 12.92 : (($x + 0.055) / 1.055) ** 2.4;
    };
    [$r, $g, $bl] = [$lin($rgb >> 16 & 0xff), $lin($rgb >> 8 & 0xff), $lin($rgb & 0xff)];
    // The cube roots of sums of linear channels, none below zero.
    $lc = (0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $bl) ** (1 / 3);
    $mc = (0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $bl) ** (1 / 3);
    $sc = (0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $bl) ** (1 / 3);
    return [
        0.2104542553 * $lc + 0.7936177850 * $mc - 0.0040720468 * $sc,
        1.9779984951 * $lc - 2.4285922050 * $mc + 0.4505937099 * $sc,
        0.0259040371 * $lc + 0.7827717662 * $mc - 0.8086757660 * $sc,
    ];
}

/**
 * The shaders, those that glow with palette.kage's colors after it, and
 * their uniforms, looked up once.
 */
final class Shaders
{
    public readonly Shader $orb, $ring, $shimmer;
    public readonly Uniform $orbPhase, $orbEnergy, $ringTime, $ringStrength, $ringPad, $ringRadius, $shimmerTime, $shimmerBase, $shimmerShine;

    public function __construct()
    {
        $palette = file_get_contents(__DIR__ . '/palette.kage');
        $this->orb = Shader::compile("package main\n" . $palette . file_get_contents(__DIR__ . '/orb.kage'));
        $this->ring = Shader::compile("package main\n" . $palette . file_get_contents(__DIR__ . '/ring.kage'));
        $this->shimmer = Shader::compile("package main\n" . file_get_contents(__DIR__ . '/shimmer.kage'));
        [$this->orbPhase, $this->orbEnergy] = [$this->orb->uniform('Phase'), $this->orb->uniform('Energy')];
        [$this->ringTime, $this->ringStrength, $this->ringPad, $this->ringRadius] = [$this->ring->uniform('Time'), $this->ring->uniform('Strength'), $this->ring->uniform('Pad'), $this->ring->uniform('Radius')];
        [$this->shimmerTime, $this->shimmerBase, $this->shimmerShine] = [$this->shimmer->uniform('Time'), $this->shimmer->uniform('Base'), $this->shimmer->uniform('Shine')];
    }
}

/** One of the conversation's messages: the user's, or the assistant's, of which $shown words show so far. */
final class Message
{
    /** @param list<string> $words */
    public function __construct(public int $id, public bool $mine, public string $text, public array $words = [], public int $shown = 0) {}
}

/** The window's state. */
final class ChatGlow
{
    /** @var list<Message> */
    public array $messages = [];
    /** What is written in the composer. */
    public string $input = '';
    /** How many words of the replies the last frame showed. */
    public int $seen = 0;
    public int $next = 0;
    public int $replies = 0;
    /**
     * The assistant's work, by the conversation's clock ($now, in
     * seconds): when the last message was sent ($asked), when the
     * assistant began to write ($writing, 0 before), and whether it works.
     */
    public float $now = 0, $asked = 0, $writing = 0;
    public bool $busy = false;
    public ?int $last = null;
    /** How much the window's edges glow, from 0 to 1, how lively the orb is, and how far its colors have moved. */
    public float $glow = 0, $energy = 0, $orbPhase = 0;
    /** Stops what moves on its own, while the desktop asks for less motion. */
    public bool $still = false;
    /** The edge glow's colors around the window, moving about. */
    public Stops $stops;
    /** The values of the shaders' uniforms: the orb's, an earlier reply's orb's, the ring's and the shimmer's. */
    public Values $orb, $stillOrb, $ring, $shimmer;

    public function __construct(public readonly Shaders $sh)
    {
        $this->stops = new Stops(fn(): float => mt_rand() / (mt_getrandmax() + 1));
        [$this->orb, $this->stillOrb, $this->ring, $this->shimmer] = [$sh->orb->newValues(), $sh->orb->newValues(), $sh->ring->newValues(), $sh->shimmer->newValues()];
    }

    /** Asks the assistant $text, which it answers after thinking. */
    public function send(string $text): void
    {
        $text = trim($text);
        $this->messages[] = new Message(++$this->next, true, $text);
        $reply = REPLIES[$this->replies++ % count(REPLIES)];
        $this->messages[] = new Message(++$this->next, false, $reply, splitWords($reply));
        $this->input = '';
        [$this->asked, $this->writing, $this->busy] = [$this->now, 0.0, true];
    }

    /** Has the assistant stop where it is. */
    public function stop(): void
    {
        $this->busy = false;
        $m = $this->messages[count($this->messages) - 1] ?? null;
        if ($m !== null && !$m->mine && $m->shown === 0) {
            [$m->words, $m->shown] = [['Stopped.'], 1];
        }
    }

    /**
     * Moves the conversation's clock on to $now, in nanoseconds. The
     * assistant works in real time, as a model streams whether the window
     * shows or not: once the window shows again after frames paused while
     * it was hidden, the clock catches up and the reply shows as far as it
     * came.
     */
    public function tick(int $now): void
    {
        $dt = $this->last === null ? 0.0 : max(($now - $this->last) / 1e9, 0.0);
        $this->last = $now;
        $this->step($dt);
    }

    /** Moves the conversation on by $dt seconds: the assistant thinks, then writes word by word; the glow and the orb follow its work. */
    public function step(float $dt): void
    {
        $this->now += $dt;
        if ($this->busy) {
            $m = $this->messages[count($this->messages) - 1];
            if ($this->writing == 0 && $this->now - $this->asked >= THINK_TIME) {
                // It began to write as it stopped thinking, however late the clock tells.
                $this->writing = $this->asked + THINK_TIME;
            }
            if ($this->writing > 0) {
                $m->shown = min(count($m->words), (int)(($this->now - $this->writing) * WORDS_PER_SECOND) + 1);
                if ($m->shown === count($m->words)) {
                    $this->busy = false;
                }
            }
        }
        $this->glow = $this->busy ? min(1, $this->glow + $dt / FADE_IN) : max(0, $this->glow - $dt / FADE_OUT);
        $this->energy += (($this->busy ? 1 : 0) - $this->energy) * (1 - exp(-$dt / LIVEN));
        if ($this->glow > 0 && !$this->still) {
            $this->stops->step($this->now);
        }
        if (!$this->still) {
            $this->orbPhase += $dt * (0.5 + 1.8 * $this->energy);
        }
    }

    /**
     * Whether something moves on its own: the assistant's work, the glow
     * fading, the orb calming down, or the welcome's orb, which drifts
     * unless the desktop asks for less motion.
     */
    public function moving(): bool
    {
        return $this->busy || $this->glow > 0 || $this->energy > 0.01 || ($this->messages === [] && !$this->still);
    }

    /** Draws the orb in $r. @param array{0: float, 1: float, 2: float, 3: float} $r */
    public function drawOrb(Painter $p, array $r): void
    {
        $this->sh->orb->draw($p, $r, new Options(values: $this->orb->float($this->sh->orbPhase, $this->orbPhase)->float($this->sh->orbEnergy, $this->energy)));
    }

    /** Draws an earlier reply's orb, at rest, its colors where the reply's id leaves them. @param array{0: float, 1: float, 2: float, 3: float} $r */
    public function drawStillOrb(Painter $p, array $r, int $id): void
    {
        $this->sh->orb->draw($p, $r, new Options(values: $this->stillOrb->float($this->sh->orbPhase, $id * 1.7)->float($this->sh->orbEnergy, 0)));
    }

    /** Draws the composer's ring, faint at rest and bright while the assistant works. @param array{0: float, 1: float, 2: float, 3: float} $r */
    public function drawRing(Painter $p, array $r): void
    {
        $this->sh->ring->draw($p, $r, new Options(values: $this->ring->float($this->sh->ringTime, $this->now)
            ->float($this->sh->ringStrength, 0.25 + 0.75 * ease($this->glow))->float($this->sh->ringPad, RING_PADDING)->float($this->sh->ringRadius, FIELD_RADIUS)));
    }

    /** Draws "Thinking…" with a shine sweeping across it. @param array{0: float, 1: float, 2: float, 3: float} $r */
    public function drawThinking(Painter $p, array $r, Theme $t): void
    {
        $text = 'Thinking…';
        [, $h] = $p->measureText(0, new Span($text, size: REPLY_SIZE));
        $this->sh->shimmer->text($p, $r[0], $r[1] + ($r[3] - $h) / 2, $text, REPLY_SIZE, new Options(values: $this->shimmer->float($this->sh->shimmerTime, $this->now)
            ->color($this->sh->shimmerBase, $t->textMuted)->color($this->sh->shimmerShine, $t->text)));
    }
}

/** CSS's ease-in-out, for the glow's fades. */
function ease(float $x): float
{
    return $x * $x * (3 - 2 * $x);
}

/** $text split into words with the spaces after them, which joined give it back. @return list<string> */
function splitWords(string $text): array
{
    $words = [];
    $start = 0;
    for ($i = 1; $i < strlen($text); $i++) {
        $prev = $text[$i - 1] === ' ' || $text[$i - 1] === "\n";
        if ($prev && $text[$i] !== ' ' && $text[$i] !== "\n") {
            $words[] = substr($text, $start, $i - $start);
            $start = $i;
        }
    }
    $words[] = substr($text, $start);
    return $words;
}

$s = new ChatGlow(new Shaders());
App::setName('Chat Glow');
App::whenReady(function () use ($s): void {
    new Window(new WindowOptions(title: 'Chat Glow', width: 860, height: 700, titleBarStyle: 'hiddenInset', content: Content::file(__DIR__ . '/view.php', $s)));
});
App::run();
