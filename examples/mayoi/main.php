<?php
// Mayoi is a little survivor game in native UI (mygo's examples/mayoi), a
// fan game after the anime BanG Dream! It's MyGO!!!!!. Tomori, lost, holds
// out on a street at night against stage fright (static, crows and
// shadows) for as long as she can. She sings on her own, at the nearest of
// it; the player walks her and picks up the stones it leaves, which she
// collects, and at each level chooses one of three upgrades. Four bring a
// bandmate, who joins, follows her and plays their part of the music: Anon
// on guitar, Soyo on bass, Rāna on lead and Taki on drums.
//
// The game plugin runs it, in fixed steps (Game::view), and its camera
// follows Tomori (Camera), shaking when she is hit. Everything it draws and
// plays is made by its code: pixel art in a pixelated sheet, drawn with
// Painter::imageRegion, and sounds and an original lo-fi tune synthesized
// for MyPHP\Audio.
//
// Arrows or WASD walk, Esc pauses and M plays or stops the music; at a
// level, 1 2 3, a click, or the arrows and Space choose. With a gamepad:
// the left stick or the d-pad walks, A chooses, Start pauses.
// Run: ./run.sh mayoi
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\Audio\{Audio, Decoder, Player, Sound};
use MyPHP\Plugins\Game\{Camera, Game, Input};
use MyPHP\Plugins\Game\Gamepad\{Button, Gamepad, State};
use MyPHP\UI\{Content, Context, Image, ImageOptions, Key, Painter, Span, Transform};
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

// The world's fixed step, as Game::view runs it.
const STEP_TIME = 1.0 / Game::RATE;

// The kinds of stage fright, which come as time goes on: static, the noise in her head; crows, the whispers; shadows, the doubts.
const KIND_STATIC = 0;
const KIND_CROW = 1;
const KIND_SHADOW = 2;
const KINDS = [
    KIND_STATIC => ['hp' => 2, 'speed' => 22, 'radius' => 6, 'stones' => 1],
    KIND_CROW => ['hp' => 1, 'speed' => 46, 'radius' => 5, 'stones' => 2],
    KIND_SHADOW => ['hp' => 7, 'speed' => 30, 'radius' => 6, 'stones' => 5],
];

// The band, after MyGO!!!!!'s: Tomori sings, Anon plays guitar, Soyo the bass, Rāna lead guitar and Taki the drums.
const VOCALIST = 0;
const GUITARIST = 1;
const BASSIST = 2;
const LEAD = 3;
const DRUMMER = 4;
const MEMBER_COUNT = 5;
const MEMBER_NAMES = ['Tomori', 'Anon', 'Soyo', 'Rāna', 'Taki'];

// The upgrades a level offers, three at a time. Four bring a bandmate: the first time, they join, and follow Tomori from then on.
const UP_VOICE = 0;
const UP_TEMPO = 1;
const UP_HARMONY = 2; // the guitarist joins
const UP_BASS = 3;    // the bassist joins
const UP_PICKS = 4;   // the lead guitarist joins
const UP_DRUMS = 5;   // the drummer joins
const UP_SHOES = 6;
const UP_EYE = 7;
const UP_BANDAID = 8;
const UPGRADE_COUNT = 9;

// The state of a game: before it, playing, choosing an upgrade, or over.
const TITLE = 0;
const PLAYING = 1;
const CHOOSING = 2;
const OVER = 3;

// What happened in a step, for its sounds.
const EV_SING = 0;
const EV_HIT = 1;
const EV_KILL = 2;
const EV_STONE = 3;
const EV_LEVEL = 4;
const EV_HURT = 5;
const EV_BEAT = 6;
const EV_CHOOSE = 7;
const EV_JOIN = 8;
const EV_OVER = 9;

const MAX_ENEMIES = 400;
const CELL_SIZE = 16; // of the grid that finds enemies near a point
const TRAIL_LEN = 128; // steps of the vocalist's trail the band follows
const TRAIL_GAP = 12;  // steps between bandmates on it

// The sheet is 8×8 cells of 16 pixels, drawn here pixel by pixel so that the example needs no files. Its cells:
const CELL = 16;
const C_PAVE = 0;
const C_PAVE2 = 1;
const C_PETALS = 2;
const C_PETALS2 = 3;
const C_CRACK = 4;
const C_GRATE = 5;
const C_MEMBER = 8; // 2 frames for each bandmate, in the band's order
const C_STATIC = C_MEMBER + 2 * MEMBER_COUNT; // 2 frames each
const C_CROW = C_STATIC + 2;
const C_SHADOW = C_STATIC + 4;
const C_NOTE = C_SHADOW + 2;
const C_PICK = C_NOTE + 1;
const C_STONE = C_NOTE + 2; // 3 kinds, by value
const C_BANDAID = C_NOTE + 5;
const C_BANDAID_EMPTY = C_NOTE + 6;
const C_PUFF = C_NOTE + 7; // 3 frames
const C_MIC = C_NOTE + 10;
const C_METRONOME = C_NOTE + 11;
const C_GUITAR = C_NOTE + 12;
const C_BASS_ICON = C_NOTE + 13;
const C_DRUM = C_NOTE + 14;
const C_SHOE = C_NOTE + 15;
const C_STONE_ICON = C_NOTE + 16;

const UPGRADES = [
    UP_VOICE => ['name' => 'Louder', 'desc' => 'Her notes hit harder', 'max' => 5, 'icon' => C_MIC, 'member' => -1],
    UP_TEMPO => ['name' => 'Tempo up', 'desc' => 'She sings faster', 'max' => 5, 'icon' => C_METRONOME, 'member' => -1],
    UP_HARMONY => ['name' => 'Harmony', 'desc' => "Anon joins on guitar:\none more note a volley", 'max' => 3, 'icon' => C_GUITAR, 'member' => GUITARIST],
    UP_BASS => ['name' => 'Bassline', 'desc' => "Soyo joins on bass:\nnotes go through one more", 'max' => 3, 'icon' => C_BASS_ICON, 'member' => BASSIST],
    UP_PICKS => ['name' => 'Guitar picks', 'desc' => "Rāna joins on lead:\na pick circles you", 'max' => 5, 'icon' => C_PICK, 'member' => LEAD],
    UP_DRUMS => ['name' => 'Drum beat', 'desc' => "Taki joins on drums:\na beat blasts around you", 'max' => 4, 'icon' => C_DRUM, 'member' => DRUMMER],
    UP_SHOES => ['name' => 'Running shoes', 'desc' => 'Walk faster', 'max' => 4, 'icon' => C_SHOE, 'member' => -1],
    UP_EYE => ['name' => 'An eye for stones', 'desc' => "Stones come from\nfarther away", 'max' => 3, 'icon' => C_STONE_ICON, 'member' => -1],
    UP_BANDAID => ['name' => 'Band-aid', 'desc' => "One more band-aid,\nand heal two", 'max' => 5, 'icon' => C_BANDAID, 'member' => -1],
];

/** A point or a direction in the world, in pixels of the art. */
final class Vec
{
    public function __construct(public readonly float $x = 0, public readonly float $y = 0) {}

    public function add(Vec $b): Vec { return new Vec($this->x + $b->x, $this->y + $b->y); }
    public function sub(Vec $b): Vec { return new Vec($this->x - $b->x, $this->y - $b->y); }
    public function mul(float $k): Vec { return new Vec($this->x * $k, $this->y * $k); }
    public function len(): float { return hypot($this->x, $this->y); }
    public function lerp(Vec $b, float $t): Vec { return new Vec($this->x + ($b->x - $this->x) * $t, $this->y + ($b->y - $this->y) * $t); }

    /** With a length of 1, or nothing for nothing. */
    public function norm(): Vec
    {
        $l = $this->len();
        return $l > 0 ? $this->mul(1 / $l) : new Vec();
    }
}

final class Enemy
{
    public Vec $prev;
    /** Knockback, which fades. */
    public Vec $knock;
    /** Seconds left of the flash of a hit; before the picks hit it again. */
    public float $flash = 0, $pickHit = 0;
    public bool $dead = false;

    /** $phase is its animation's, and a crow's weaving. */
    public function __construct(public readonly int $id, public readonly int $kind, public Vec $pos, public float $hp, public float $phase)
    {
        $this->prev = $pos;
        $this->knock = new Vec();
    }
}

/** What the vocalist sings at the noise. */
final class Note
{
    public Vec $prev;
    /** @var list<int> the enemies it hit */
    public array $hit = [];

    /** $pierce is how many enemies it goes through. */
    public function __construct(public Vec $pos, public readonly Vec $vel, public readonly float $damage, public int $pierce, public float $life)
    {
        $this->prev = $pos;
    }
}

/** What the fallen leave: the vocalist collects them. */
final class Stone
{
    public Vec $prev;
    public bool $pulled = false;

    public function __construct(public Vec $pos, public int $value)
    {
        $this->prev = $pos;
    }
}

/** The cloud an enemy leaves, or a number of damage. */
final class Effect
{
    public float $t = 0;

    public function __construct(public readonly Vec $pos, public readonly string $text = '') {}
}

/** The game's state, advanced in fixed steps of simulated time. */
final class World
{
    public int $state = TITLE;
    /** Held out, in seconds. */
    public float $time = 0;
    /** The vocalist, by the middle of their feet; where the player steers, at most 1 long. */
    public Vec $pos, $prev, $move;
    public bool $facingLeft = false;
    public float $walked = 0;
    public int $hp = 5, $maxHP = 5;
    /** Seconds left unhurtable. */
    public float $hurt = 0;
    public float $speed = 62;
    /** How far stones come from. */
    public float $reach = 30;
    public float $damage = 1, $cooldown = 0.75;
    public int $notes = 1, $pierce = 0;
    /** @var list<int> by upgrade */
    public array $levels;
    /** @var list<int> the band in the order they joined, after the vocalist */
    public array $band = [];
    /** @var list<Vec> the trail they walk on */
    public array $trail;
    public int $head = 0;
    /** @var list<Enemy> */
    public array $enemies = [];
    /** @var list<Note> */
    public array $sung = [];
    /** @var list<Stone> */
    public array $stones = [];
    /** @var list<Effect> */
    public array $puffs = [], $popups = [];
    public int $nextID = 0;
    public float $spawnTimer = 0.5, $singTimer = 0, $beatTimer = 1, $waveTimer = 45;
    public float $pickAngle = 0, $beatFlash = 0, $shake = 0, $overTime = 0;
    public int $xp = 0, $level = 1, $xpNext = 5;
    /** Stones collected, enemies calmed. */
    public int $collected = 0, $calmed = 0;
    /** @var list<int> */
    public array $choices = [];
    /** Who joined, shown for a while. */
    public string $banner = '';
    public float $bannerTime = 0;
    /** Half the view's size in the world: enemies come from beyond it. */
    public Vec $view;
    /** @var list<int> */
    public array $events = [];
    /** @var array<int, list<int>> enemies by cell, hashed */
    private array $grid = [];

    public function __construct(public readonly Randomizer $rng)
    {
        $this->view = new Vec(180, 110);
        $this->reset();
        $this->state = TITLE;
    }

    public static function seeded(int $seed): self
    {
        return new self(new Randomizer(new Xoshiro256StarStar($seed)));
    }

    /** Starts a game anew. */
    public function reset(): void
    {
        $this->state = TITLE; $this->time = 0;
        $this->pos = $this->prev = $this->move = new Vec();
        $this->facingLeft = false; $this->walked = 0;
        $this->hp = $this->maxHP = 5; $this->hurt = 0;
        $this->speed = 62; $this->reach = 30;
        $this->damage = 1; $this->cooldown = 0.75; $this->notes = 1; $this->pierce = 0;
        $this->levels = array_fill(0, UPGRADE_COUNT, 0);
        $this->band = []; $this->trail = array_fill(0, TRAIL_LEN, new Vec()); $this->head = 0;
        $this->enemies = $this->sung = $this->stones = $this->puffs = $this->popups = $this->events = $this->choices = $this->grid = [];
        $this->nextID = 0;
        $this->spawnTimer = 0.5; $this->singTimer = 0; $this->beatTimer = 1; $this->waveTimer = 45;
        $this->pickAngle = $this->beatFlash = $this->shake = $this->overTime = 0;
        $this->xp = 0; $this->level = 1; $this->xpNext = 5;
        $this->collected = $this->calmed = 0;
        $this->banner = ''; $this->bannerTime = 0;
        $this->state = PLAYING;
    }

    /** Advances the world by $dt seconds. */
    public function step(float $dt): void
    {
        if ($this->state === OVER) { $this->overTime += $dt; }
        if ($this->state !== PLAYING) { return; }
        $this->time += $dt;
        $this->walk($dt);
        $this->spawn($dt);
        $this->chase($dt);
        $this->sing($dt);
        $this->fly($dt);
        $this->spinPicks($dt);
        $this->drum($dt);
        $this->collect($dt);
        $this->touch($dt);
        $this->age($dt);
    }

    private function walk(float $dt): void
    {
        $this->prev = $this->pos;
        if ($this->move->len() > 0.05) {
            $this->pos = $this->pos->add($this->move->mul($this->speed * $dt));
            $this->walked += $this->move->len() * $this->speed * $dt;
            if ($this->move->x < -0.1) { $this->facingLeft = true; } elseif ($this->move->x > 0.1) { $this->facingLeft = false; }
            $this->head = ($this->head + 1) % TRAIL_LEN;
            $this->trail[$this->head] = $this->pos;
        }
    }

    /** Where bandmate $i of the band (0 the first to join) walks: on the vocalist's trail, a little behind the one before. */
    public function follower(int $i): Vec
    {
        return $this->trail[($this->head - ($i + 1) * TRAIL_GAP + TRAIL_LEN * 4) % TRAIL_LEN];
    }

    /** Brings enemies from just beyond the view's edges, ever more and stronger, static in groups, and every 45 seconds a ring of it all around. */
    public function spawn(float $dt): void
    {
        $this->spawnTimer -= $dt;
        while ($this->spawnTimer <= 0) {
            $this->spawnTimer += max(0.08, 0.7 * 0.982 ** $this->time);
            $k = KIND_STATIC; $n = 1 + $this->rng->getInt(0, (int)($this->time / 40));
            $r = $this->rng->nextFloat();
            if ($this->time > 60 && $r < 0.15) {
                $k = KIND_SHADOW; $n = 1;
            } elseif ($this->time > 25 && $r < 0.4) {
                $k = KIND_CROW; $n = 1 + $this->rng->getInt(0, 1);
            }
            $at = $this->edge(14);
            for ($i = 0; $i < $n; $i++) { $this->add($k, $at->add(new Vec($this->rng->nextFloat() * 16 - 8, $this->rng->nextFloat() * 16 - 8))); }
        }
        $this->waveTimer -= $dt;
        if ($this->waveTimer <= 0) {
            $this->waveTimer = 45;
            $n = 24 + (int)($this->time / 15);
            for ($i = 0; $i < $n; $i++) {
                $a = $i / $n * 2 * M_PI;
                $this->add(KIND_STATIC, $this->pos->add((new Vec(cos($a), sin($a)))->mul($this->view->len() + 4)));
            }
        }
    }

    /** A point $margin beyond the view's edges, anywhere around. */
    private function edge(float $margin): Vec
    {
        $hw = $this->view->x + $margin; $hh = $this->view->y + $margin;
        $d = $this->rng->nextFloat() * 4 * ($hw + $hh);
        $p = match (true) {
            $d < 2 * $hw => new Vec($d - $hw, -$hh),
            $d < 4 * $hw => new Vec($d - 3 * $hw, $hh),
            $d < 4 * $hw + 2 * $hh => new Vec(-$hw, $d - 4 * $hw - $hh),
            default => new Vec($hw, $d - 4 * $hw - 3 * $hh),
        };
        return $this->pos->add($p);
    }

    public function add(int $k, Vec $at): void
    {
        if (count($this->enemies) >= MAX_ENEMIES) { return; }
        $this->enemies[] = new Enemy(++$this->nextID, $k, $at, KINDS[$k]['hp'] * (1 + $this->time / 100), $this->rng->nextFloat() * 10);
    }

    /** The hashed cell of the grid a point is in. */
    private static function cellOf(Vec $p): array
    {
        return [(int)floor($p->x / CELL_SIZE), (int)floor($p->y / CELL_SIZE)];
    }

    private static function hashCell(int $x, int $y): int { return (($x * 73856093) ^ ($y * 19349663)) & 0xff; }

    /** Files the enemies in the grid. */
    private function index(): void
    {
        $this->grid = [];
        foreach ($this->enemies as $i => $e) {
            [$cx, $cy] = self::cellOf($e->pos);
            $this->grid[self::hashCell($cx, $cy)][] = $i;
        }
    }

    /** Calls $fn with the enemies that may be within a cell of $p. */
    private function near(Vec $p, Closure $fn): void
    {
        [$cx, $cy] = self::cellOf($p);
        $seen = [];
        for ($dy = -1; $dy <= 1; $dy++) {
            for ($dx = -1; $dx <= 1; $dx++) {
                $c = self::hashCell($cx + $dx, $cy + $dy);
                if (isset($seen[$c])) { continue; } // two cells hashed alike
                $seen[$c] = true;
                foreach ($this->grid[$c] ?? [] as $i) { $fn($this->enemies[$i]); }
            }
        }
    }

    /** Moves the enemies toward the vocalist, apart from each other. */
    private function chase(float $dt): void
    {
        $far = $this->view->len() * 3;
        foreach ($this->enemies as $e) {
            $e->prev = $e->pos;
            $e->phase += $dt;
            $to = $this->pos->sub($e->pos);
            if ($to->len() > $far) { $e->dead = true; continue; } // left behind: others come
            $dir = $to->norm();
            if ($e->kind === KIND_CROW) { $dir = $dir->add((new Vec(-$dir->y, $dir->x))->mul(0.7 * sin($e->phase * 5))); }
            $e->pos = $e->pos->add($dir->mul(KINDS[$e->kind]['speed'] * $dt))->add($e->knock->mul($dt));
            $e->knock = $e->knock->mul(exp(-9 * $dt));
        }
        $this->index();
        foreach ($this->enemies as $e) {
            $this->near($e->pos, function (Enemy $o) use ($e): void {
                if ($o === $e) { return; }
                $dx = $e->pos->x - $o->pos->x; $dy = $e->pos->y - $o->pos->y;
                $r = KINDS[$e->kind]['radius'] + KINDS[$o->kind]['radius'];
                $l = hypot($dx, $dy);
                if ($l < $r) {
                    if ($l == 0) { $dx = 1.0; $dy = 0.0; $l = 1.0; }
                    $k = ($r - $l) / $l * 0.5;
                    $e->pos = new Vec($e->pos->x + $dx * $k, $e->pos->y + $dy * $k);
                }
            });
        }
    }

    /** Sends notes at the nearest enemy in reach. */
    private function sing(float $dt): void
    {
        $this->singTimer -= $dt;
        if ($this->singTimer > 0) { return; }
        $target = null; $best = 200.0;
        foreach ($this->enemies as $e) {
            $d = $e->pos->sub($this->pos)->len();
            if ($d < $best && !$e->dead) { $target = $e; $best = $d; }
        }
        if ($target === null) { $this->singTimer = 0; return; }
        $this->singTimer = $this->cooldown;
        $from = $this->pos->add(new Vec(0, -8));
        $aim = atan2($target->pos->y - $from->y, $target->pos->x - $from->x);
        for ($i = 0; $i < $this->notes; $i++) {
            $a = $aim + ($i - ($this->notes - 1) / 2) * 0.22;
            $this->sung[] = new Note($from, (new Vec(cos($a), sin($a)))->mul(200), $this->damage, $this->pierce, 1.2);
        }
        $this->events[] = EV_SING;
    }

    private function fly(float $dt): void
    {
        foreach ($this->sung as $n) {
            $n->prev = $n->pos;
            $n->pos = $n->pos->add($n->vel->mul($dt));
            $n->life -= $dt;
            $this->near($n->pos, function (Enemy $e) use ($n): void {
                if ($n->life <= 0 || $e->dead || in_array($e->id, $n->hit, true) || $e->pos->sub($n->pos)->len() > KINDS[$e->kind]['radius'] + 3) { return; }
                $n->hit[] = $e->id;
                $this->hurtEnemy($e, $n->damage, $n->pos->sub($n->vel));
                if (--$n->pierce < 0) { $n->life = 0; }
            });
        }
        $this->sung = array_values(array_filter($this->sung, fn(Note $n) => $n->life > 0));
    }

    /** Where pick $i of $n is. */
    public function pickPos(int $i, int $n, Vec $around, float $angle): Vec
    {
        $a = $angle + $i / $n * 2 * M_PI;
        return $around->add((new Vec(cos($a), sin($a) * 0.8))->mul(30))->add(new Vec(0, -6));
    }

    private function spinPicks(float $dt): void
    {
        $n = $this->levels[UP_PICKS];
        if ($n === 0) { return; }
        $this->pickAngle += 3.4 * $dt;
        for ($i = 0; $i < $n; $i++) {
            $at = $this->pickPos($i, $n, $this->pos, $this->pickAngle);
            $this->near($at, function (Enemy $e) use ($at, $n): void {
                if (!$e->dead && $e->pickHit <= 0 && $e->pos->sub($at)->len() < KINDS[$e->kind]['radius'] + 6) {
                    $e->pickHit = 0.4;
                    $this->hurtEnemy($e, 1 + $n / 3, $this->pos);
                }
            });
        }
    }

    /** How far the drummer's beat reaches at its level. */
    public function beatRadius(): float { return 44 + 12 * $this->levels[UP_DRUMS]; }

    private function drum(float $dt): void
    {
        $lv = $this->levels[UP_DRUMS];
        if ($lv === 0) { return; }
        $this->beatTimer -= $dt;
        if ($this->beatTimer > 0) { return; }
        $this->beatTimer = 4.6 - 0.6 * $lv;
        $this->beatFlash = 0.4; $this->shake = max($this->shake, 0.12);
        $r = $this->beatRadius();
        foreach ($this->enemies as $e) {
            if (!$e->dead && $e->pos->sub($this->pos)->len() < $r) {
                $this->hurtEnemy($e, 1 + $lv, $this->pos);
                $e->knock = $e->knock->mul(2);
            }
        }
        $this->events[] = EV_BEAT;
    }

    /** Hurts $e by $d, knocking it away from where the hit came from. */
    private function hurtEnemy(Enemy $e, float $d, Vec $from): void
    {
        $e->hp -= $d;
        $e->flash = 0.12;
        $e->knock = $e->pos->sub($from)->norm()->mul($e->kind === KIND_SHADOW ? 40 : 90);
        $v = round($d * 10) / 10;
        $this->popups[] = new Effect($e->pos->add(new Vec(0, -10)), $v == (int)$v ? (string)(int)$v : (string)$v);
        if ($e->hp > 0) { $this->events[] = EV_HIT; return; }
        $e->dead = true;
        $this->calmed++;
        $this->stones[] = new Stone($e->pos, KINDS[$e->kind]['stones']);
        $this->puffs[] = new Effect($e->pos);
        $this->events[] = EV_KILL;
    }

    /** Draws in the stones within reach, and levels up. */
    private function collect(float $dt): void
    {
        foreach ($this->stones as $s) {
            $s->prev = $s->pos;
            $to = $this->pos->add(new Vec(0, -4))->sub($s->pos);
            $d = $to->len();
            if ($d < $this->reach) { $s->pulled = true; }
            if (!$s->pulled) { continue; }
            if ($d < 5) {
                $this->xp += $s->value;
                $this->collected++;
                $s->value = 0;
                $this->events[] = EV_STONE;
                continue;
            }
            $s->pos = $s->pos->add($to->norm()->mul(min($d, (90 + $this->speed * 1.5) * $dt)));
        }
        $this->stones = array_values(array_filter($this->stones, fn(Stone $s) => $s->value !== 0));
        $this->levelUp();
    }

    private function levelUp(): void
    {
        if ($this->state !== PLAYING || $this->xp < $this->xpNext) { return; }
        $this->xp -= $this->xpNext;
        $this->level++;
        $this->xpNext = 3 + $this->level * 2;
        $open = $joining = [];
        for ($u = 0; $u < UPGRADE_COUNT; $u++) {
            if ($this->levels[$u] >= UPGRADES[$u]['max']) { continue; }
            if (UPGRADES[$u]['member'] >= 0 && $this->levels[$u] === 0) { $joining[] = $u; } else { $open[] = $u; }
        }
        $open = $this->rng->shuffleArray($open);
        $joining = $this->rng->shuffleArray($joining);
        $this->choices = [];
        // A bandmate is always among the choices until all have joined.
        if ($joining !== []) {
            $this->choices[] = $joining[0];
            $open = $this->rng->shuffleArray([...$open, ...array_slice($joining, 1)]);
        }
        $this->choices = $this->rng->shuffleArray([...$this->choices, ...array_slice($open, 0, 3 - count($this->choices))]);
        if ($this->choices !== []) { $this->state = CHOOSING; }
        $this->events[] = EV_LEVEL;
    }

    /** Takes the upgrade offered at $i. */
    public function choose(int $i): void
    {
        if ($this->state !== CHOOSING || $i < 0 || $i >= count($this->choices)) { return; }
        $u = $this->choices[$i];
        $this->levels[$u]++;
        match ($u) {
            UP_VOICE => $this->damage++,
            UP_TEMPO => $this->cooldown *= 0.8,
            UP_HARMONY => $this->notes++,
            UP_BASS => $this->pierce++,
            UP_SHOES => $this->speed *= 1.12,
            UP_EYE => $this->reach *= 1.45,
            UP_BANDAID => $this->hp = min($this->hp + 2, ++$this->maxHP),
            default => null,
        };
        $this->state = PLAYING;
        $m = UPGRADES[$u]['member'];
        if ($m >= 0 && $this->levels[$u] === 1) {
            // They come from where the vocalist walked a moment ago.
            $this->band[] = $m;
            $this->banner = MEMBER_NAMES[$m] . ' joins the band!';
            $this->bannerTime = 2.5;
            $this->events[] = EV_JOIN;
        } else {
            $this->events[] = EV_CHOOSE;
        }
        $this->levelUp();
    }

    /** Whether bandmate $m is in the band. */
    public function joined(int $m): bool { return in_array($m, $this->band, true); }

    /** Hurts the vocalist when an enemy reaches them. */
    private function touch(float $dt): void
    {
        if ($this->hurt > 0) { $this->hurt -= $dt; return; }
        $this->near($this->pos, function (Enemy $e): void {
            if ($this->hurt > 0 || $e->dead || $e->pos->sub($this->pos)->len() > KINDS[$e->kind]['radius'] + 4) { return; }
            $this->hp--;
            $this->hurt = 1; $this->shake = 0.3;
            $this->events[] = EV_HURT;
            foreach ($this->enemies as $o) {
                if ($o->pos->sub($this->pos)->len() < 30) { $o->knock = $o->pos->sub($this->pos)->norm()->mul(160); }
            }
            if ($this->hp <= 0) {
                $this->state = OVER;
                $this->events[] = EV_OVER;
            }
        });
    }

    /** Moves on the effects, and forgets the dead. */
    private function age(float $dt): void
    {
        foreach ($this->enemies as $e) { $e->flash -= $dt; $e->pickHit -= $dt; }
        $this->enemies = array_values(array_filter($this->enemies, fn(Enemy $e) => !$e->dead));
        foreach ($this->puffs as $p) { $p->t += $dt; }
        $this->puffs = array_values(array_filter($this->puffs, fn(Effect $p) => $p->t <= 0.32));
        foreach ($this->popups as $p) { $p->t += $dt; }
        $this->popups = array_values(array_filter($this->popups, fn(Effect $p) => $p->t <= 0.6));
        $this->shake = max($this->shake - $dt, 0.0);
        $this->beatFlash = max($this->beatFlash - $dt, 0.0);
        $this->bannerTime = max($this->bannerTime - $dt, 0.0);
    }
}

// The art.

/** The sheet's pixels, premultiplied RGBA, 8 cells a row. */
final class Pixels
{
    public string $rgba;

    public function __construct() { $this->rgba = str_repeat("\0", 8 * CELL * 8 * CELL * 4); }
}

/** Draws into one cell of the sheet. */
final class Cell
{
    public function __construct(private readonly Pixels $img, private readonly int $ox, private readonly int $oy) {}

    public static function at(Pixels $img, int $i): self { return new self($img, $i % 8 * CELL, intdiv($i, 8) * CELL); }

    /** @param array{0: int, 1: int, 2: int, 3?: int} $c */
    public function px(int $x, int $y, array $c): void
    {
        if ($x < 0 || $y < 0 || $x >= CELL || $y >= CELL) { return; }
        $a = $c[3] ?? 255;
        $i = (($this->oy + $y) * 8 * CELL + $this->ox + $x) * 4;
        $px = &$this->img->rgba; // written in place
        $px[$i] = chr(intdiv($c[0] * $a, 255)); $px[$i + 1] = chr(intdiv($c[1] * $a, 255)); $px[$i + 2] = chr(intdiv($c[2] * $a, 255)); $px[$i + 3] = chr($a);
    }

    /** Pixel art given as rows of characters, each a color of $pal; spaces are transparent. @param array<string, array> $pal */
    public function rows(array $pal, string ...$art): self
    {
        foreach ($art as $y => $row) {
            for ($x = 0; $x < strlen($row); $x++) {
                if (isset($pal[$row[$x]])) { $this->px($x, $y, $pal[$row[$x]]); }
            }
        }
        return $this;
    }
}

function rgb(int $hex): array { return [$hex >> 16 & 0xff, $hex >> 8 & 0xff, $hex & 0xff]; }

const SHADOW_COLOR = [0, 0, 0, 80];

/** The sheet, as a bitmap of pixel art. */
function sheet(): Image
{
    $img = new Pixels();
    ground($img);
    band($img);
    fright($img);
    things($img);
    return Image::fromPixels(8 * CELL, 8 * CELL, $img->rgba)->pixelated();
}

/** The street at night: paving stones, sakura petals, a crack and a drain. */
function ground(Pixels $img): void
{
    $rng = new Randomizer(new Xoshiro256StarStar(42));
    [$base, $mortar, $light] = [rgb(0x353b52), rgb(0x272c3f), rgb(0x3f465f)];
    $pave = function (int $i, int $n) use ($img, $rng, $base, $mortar, $light): Cell {
        $c = Cell::at($img, $i);
        for ($y = 0; $y < CELL; $y++) {
            for ($x = 0; $x < CELL; $x++) {
                // Bricks of 8×8, every other row offset by half.
                $off = $y >= 8 ? 4 : 0;
                $col = $y % 8 === 0 || ($x + $off) % 8 === 0 ? $mortar : ($y % 8 === 1 || ($x + $off) % 8 === 1 ? $light : $base);
                $c->px($x, $y, $col);
            }
        }
        for ($k = 0; $k < $n; $k++) { $c->px($rng->getInt(0, CELL - 1), $rng->getInt(0, CELL - 1), $mortar); }
        return $c;
    };
    $pave(C_PAVE, 4);
    $pave(C_PAVE2, 12);
    $pal = ['p' => rgb(0xf6b8cf), 'P' => rgb(0xe48aae), 'w' => rgb(0xffe4ee), 'm' => $mortar, 'g' => rgb(0x1b1f2d), 'G' => rgb(0x4a5170)];
    $pave(C_PETALS, 4)->rows($pal,
        '',
        '   pP           ',
        '   Pp       wp  ',
        '            pP  ',
        '',
        '       pw       ',
        '       Pp       ',
        '',
        '',
        '  wp            ',
        '  pP      pP    ',
        '          Pw    ',
        '',
        '      pP        ',
        '      PP        ');
    $pave(C_PETALS2, 6)->rows($pal,
        '', '',
        '         pw     ',
        '         Pp     ',
        '',
        '   pP           ',
        '   pp           ',
        '',
        '            pP  ',
        '            wP  ');
    $pave(C_CRACK, 4)->rows($pal,
        '', '',
        '     m          ',
        '      m         ',
        '      mm        ',
        '        m       ',
        '        m m     ',
        '         m      ',
        '         m      ',
        '          m     ');
    $pave(C_GRATE, 2)->rows($pal,
        '', '', '',
        '   GGGGGGGGGG   ',
        '   GgGgGgGgGG   ',
        '   GgGgGgGgGG   ',
        '   GgGgGgGgGG   ',
        '   GgGgGgGgGG   ',
        '   GgGgGgGgGG   ',
        '   GGGGGGGGGG   ');
}

/**
 * How each bandmate looks: their hair and eyes, the hair's shape above
 * (rows 0 to 8) and beside the body (rows 9 on), and what they hold (rows
 * 8 on).
 */
const LOOKS = [
    VOCALIST => [
        'hair' => 0x93a9cc, 'hairDark' => 0x6d83aa, 'eye' => 0xd9a441, 'eye2' => 0,
        'head' => ['', '     HHHHHH     ', '    HHHHHHHH    ', '   HHHHHHHHHH   ', '   HHHHHHHHHH   ', '   HhkkkkkkhH   ', '   HkekkkkEkH   ', '   Hkkkkkkkkh   ', '   hH kkkk Hh   '],
        'sides' => ['   h        h   '],
        'item' => ['             M  ', '             m  ', '            km  '],
        'itemPal' => ['M' => 0xd9dde8, 'm' => 0x3a3f55],
    ],
    GUITARIST => [
        'hair' => 0xf3a5c4, 'hairDark' => 0xd47ea2, 'eye' => 0x6aa86e, 'eye2' => 0,
        'head' => ['', '     HHHHHH     ', '    HHHHHHHH    ', '   HHHHHHHHHH   ', '  HHHHHHHHHHHH  ', '  HHhkkkkkkhHH  ', '  HHkekkkkEkHH  ', '  HHkkkkkkkkHH  ', '  HH  kkkk  HH  '],
        'sides' => ['  HH        HH  ', '  HH        HH  ', '  hH        Hh  ', '   h        h   '],
        'item' => ['              l ', '             l  ', '            l   ', '        GGGl    ', '       GGGGGG   ', '        GGGG    '],
        'itemPal' => ['G' => 0xe0465e, 'l' => 0x8f563b],
    ],
    BASSIST => [
        'hair' => 0xd2a679, 'hairDark' => 0xa97f54, 'eye' => 0x4a8fd6, 'eye2' => 0,
        'head' => ['', '     HHHHHH     ', '    HHHHHHHH    ', '   HHHHHHHHHH   ', '  HHHHHHHHHHHH  ', '  HHHHkkkkkkHH  ', '  HHkekkkkEkHH  ', '  HHkkkkkkkkHH  ', '  HHH kkkk HHH  '],
        'sides' => ['  HHH      HHH  ', '  HHH      HHH  ', '  hHH      HHh  ', '  hH        Hh  '],
        'item' => ['               l', '              l ', '             l  ', '            l   ', '       BBBBl    ', '      BBBBBBB   ', '       BBBBB    '],
        'itemPal' => ['B' => 0x7b4a8f, 'l' => 0x5c3a24],
    ],
    LEAD => [
        'hair' => 0xe6e9f0, 'hairDark' => 0xa9b0c3, 'eye' => 0xf2c94c, 'eye2' => 0x56a0d3,
        'head' => ['   h        h   ', '   Hh HHHH hH   ', '   HHHHHHHHHH   ', '  HHHHHHHHHHHH  ', '  HHhHHHHHHhHH  ', '  HhkkkkkkkkhH  ', '  HHkekkkkEkHH  ', '  hHkkkkkkkkHh  ', '  hh  kkkk  hh  '],
        'sides' => ['  hh        hh  '],
        'item' => ['              l ', '             l  ', '            l   ', '        TTTl    ', '       TTTTTT   ', '        TTTT    '],
        'itemPal' => ['T' => 0x3fc2b5, 'l' => 0xe8e2d0],
    ],
    DRUMMER => [
        'hair' => 0x3c4170, 'hairDark' => 0x272a4a, 'eye' => 0xa05ad6, 'eye2' => 0,
        'head' => ['', '    HHHHHHHH    ', '   HHHHHHHHHH   ', '   HHHHHHHHHH   ', '   HHHHHHHHHH   ', '   HHkkkkkkHH   ', '   HkeekkEEkH   ', '   HkkkkkkkkH   ', '   HH kkkk HH   '],
        'sides' => ['   HH      HH   ', '   HH      HH   ', '   hH      Hh   ', '   hh      hh   '],
        'item' => ['  d          d  ', '   d        d   ', '    d      d    '],
        'itemPal' => ['d' => 0xe6c48f],
    ],
];

/** The bandmates in their school uniforms, standing and stepping, facing right. */
function band(Pixels $img): void
{
    $body = ['   kNWWrrWWNk   ', '   kNWWWWWWNk   ', '    NNNNNNNN    ', '    NnNNNNnN    '];
    $legs = [
        ['     ss  ss     ', '     bb  bb     ', '    SSSSSSSS    '],
        ['    ss    ss    ', '    bb    bb    ', '    SSSSSSSS    '],
    ];
    foreach (LOOKS as $m => $l) {
        $pal = [
            'H' => rgb($l['hair']), 'h' => rgb($l['hairDark']), 'e' => rgb($l['eye']), 'E' => rgb($l['eye2'] ?: $l['eye']),
            'k' => rgb(0xf6d5bd), 'W' => rgb(0xf4f4f8), 'N' => rgb(0x2b3557), 'n' => rgb(0x232b47),
            'r' => rgb(0xd94a5a), 's' => rgb(0x232b47), 'b' => rgb(0x3a2a30), 'S' => SHADOW_COLOR,
        ];
        foreach ($l['itemPal'] as $name => $hex) { $pal[$name] = rgb($hex); }
        for ($f = 0; $f < 2; $f++) {
            $c = Cell::at($img, C_MEMBER + $m * 2 + $f);
            $c->rows($pal, ...$l['head'], ...$body, ...$legs[$f]);
            $c->rows($pal, ...array_fill(0, 9, ''), ...$l['sides']);
            $c->rows($pal, ...array_fill(0, 8, ''), ...$l['item']);
        }
    }
}

/** Stage fright, two frames each: static, a crow and a shadow. */
function fright(Pixels $img): void
{
    $rng = new Randomizer(new Xoshiro256StarStar(88));
    $grays = [rgb(0x8a86a8), rgb(0x6b6788), rgb(0xb2aed0), rgb(0x4d4a66)];
    for ($f = 0; $f < 2; $f++) {
        $c = Cell::at($img, C_STATIC + $f);
        $r = 5.6 + $f * 0.6;
        for ($y = 0; $y < CELL; $y++) {
            for ($x = 0; $x < CELL; $x++) {
                $dx = $x - 7.5; $dy = ($y - 9.5) * (1.1 - $f * 0.15);
                $jag = 0.8 * sin(atan2($dy, $dx) * 7 + $f * 2);
                if (hypot($dx, $dy) < $r + $jag) { $c->px($x, $y, $grays[$rng->getInt(0, count($grays) - 1)]); }
            }
        }
        $c->rows(['w' => rgb(0xffffff), 'e' => rgb(0x16141f), 'S' => SHADOW_COLOR],
            '', '', '', '', '', '', '', '',
            '     we  we     ',
            '     ee  ee     ',
            '', '', '', '', '',
            '   SSSSSSSSSS   ');
    }
    $pal = [
        'b' => rgb(0x1e1c2b), 'B' => rgb(0x34304a), 'y' => rgb(0xffd95a), 'o' => rgb(0xf2a03d),
        'd' => rgb(0x120f1c), 'D' => rgb(0x2a2340), 'r' => rgb(0xff4d6a), 'S' => SHADOW_COLOR,
    ];
    Cell::at($img, C_CROW)->rows($pal,
        '', '',
        ' B            B ',
        ' BB          BB ',
        ' bBB  bbb   BBb ',
        '  bbBbbbybbBbb  ',
        '   bbbbbbbbbboo ',
        '     bbbbbb     ',
        '      b  b      ',
        '', '', '', '', '',
        '     SSSSSS     ');
    Cell::at($img, C_CROW + 1)->rows($pal,
        '', '', '', '',
        '      bbb       ',
        '     bbbybb     ',
        '  BBbbbbbbbboo  ',
        ' BbbbbbbbbbbbB  ',
        ' Bbb   b  b  bB ',
        ' B            B ',
        '', '', '', '',
        '     SSSSSS     ');
    $hood = [
        '',
        '      DDDD      ',
        '     DddddD     ',
        '    DddddddD    ',
        '    DdrddrdD    ',
        '    DddddddD    ',
        '    DddddddD    ',
        '   DdddddddD    ',
        '   DddddddddD   ',
        '   DddddddddD   ',
        '  DdddddddddD   ',
        '  DddddddddddD  ',
    ];
    Cell::at($img, C_SHADOW)->rows($pal, ...$hood, ...['  Dd dd dd dD   ', '   d  d  d  d   ', '', '    SSSSSSSS    ']);
    Cell::at($img, C_SHADOW + 1)->rows($pal, ...$hood, ...['  dD dd dd Dd   ', '  d  d  d  d    ', '', '    SSSSSSSS    ']);
}

/** The note, the pick, stones, band-aids, the puff and the upgrades' icons. */
function things(Pixels $img): void
{
    $pal = [
        'o' => rgb(0x9ff0ff), 'O' => rgb(0xffffff), 'c' => rgb(0x3aa7e0),
        't' => rgb(0x3fc2b5), 'T' => rgb(0x2a8a80),
        'g' => rgb(0x9a9cab), 'G' => rgb(0x6d6f80), 'b' => rgb(0x8fb4e8), 'B' => rgb(0x5b7fb8),
        'p' => rgb(0xf6b8cf), 'P' => rgb(0xd47ea2),
        'a' => rgb(0xe8b98f), 'A' => rgb(0xc9926a), 'w' => rgb(0xfff6e0), 'k' => rgb(0x3b3550), 'K' => rgb(0x2a2540),
        'm' => rgb(0xd9dde8), 'M' => rgb(0x3a3f55), 'y' => rgb(0xffd95a), 'r' => rgb(0xe0465e), 'l' => rgb(0x8f563b),
        'v' => rgb(0x7b4a8f), 'd' => rgb(0xe6c48f), 'n' => rgb(0x2b3557), 'W' => rgb(0xd8dde8),
    ];
    Cell::at($img, C_NOTE)->rows($pal,
        '', '',
        '        ooo     ',
        '        oOoo    ',
        '        o  oo   ',
        '        o   o   ',
        '        o       ',
        '        o       ',
        '        o       ',
        '     oooo       ',
        '    oOOOo       ',
        '    ooooo       ',
        '     ooo        ');
    Cell::at($img, C_PICK)->rows($pal,
        '', '', '',
        '   TTTTTTTTTT   ',
        '   TttttttttT   ',
        '   TtOttttttT   ',
        '    TttttttT    ',
        '    TttttttT    ',
        '     TttttT     ',
        '     TttttT     ',
        '      TttT      ',
        '       TT       ');
    foreach ([[C_STONE, 'g', 'G'], [C_STONE + 1, 'b', 'B'], [C_STONE + 2, 'p', 'P']] as [$i, $light, $dark]) {
        Cell::at($img, $i)->rows(['l' => $pal[$light], 'L' => $pal[$dark], 'O' => $pal['O']],
            '', '', '', '', '', '',
            '      llll      ',
            '     lOllll     ',
            '    llllllLL    ',
            '    lllllLLL    ',
            '     LLLLLL     ');
    }
    $bandaid = [
        '', '', '', '',
        '   aaaaaaaaaa   ',
        '  aaaawwwwaaaa  ',
        '  aAaawAAwaaAa  ',
        '  aaaawAAwaaaa  ',
        '  aAaawwwwaaAa  ',
        '   aaaaaaaaaa   ',
    ];
    Cell::at($img, C_BANDAID)->rows($pal, ...$bandaid);
    Cell::at($img, C_BANDAID_EMPTY)->rows(['a' => $pal['k'], 'A' => $pal['K'], 'w' => $pal['K']], ...$bandaid);
    for ($f = 0; $f < 3; $f++) {
        $c = Cell::at($img, C_PUFF + $f);
        $r = 3 + $f * 2;
        for ($y = -$r; $y <= $r; $y++) {
            for ($x = -$r; $x <= $r; $x++) {
                $d = $x * $x + $y * $y;
                if ($d <= $r * $r && ($f === 0 || $d >= ($r - 2) * ($r - 2))) { $c->px(8 + $x, 8 + $y, $pal['w']); }
            }
        }
    }
    Cell::at($img, C_MIC)->rows($pal,
        '',
        '      mmmm      ',
        '     mWmWmm     ',
        '     mmWmWm     ',
        '     mWmWmm     ',
        '      mmmm      ',
        '       MM       ',
        '       MM       ',
        '       MM       ',
        '       MM       ',
        '       MM       ',
        '       MM       ',
        '      MMMM      ');
    Cell::at($img, C_METRONOME)->rows($pal,
        '',
        '       ll       ',
        '       ll   y   ',
        '      llll y    ',
        '      llllyl    ',
        '     lllllyl    ',
        '     llllylll   ',
        '    lllllllll   ',
        '    llllyyllll  ',
        '   llllllllllll ',
        '   AAAAAAAAAAAA ');
    Cell::at($img, C_GUITAR)->rows($pal,
        '             ll ',
        '            ll  ',
        '           l    ',
        '          l     ',
        '         l      ',
        '    rrr l       ',
        '   rrrrrl       ',
        '  rrrrkrr       ',
        '  rrrkkrrr      ',
        '   rrrrrrrr     ',
        '  rrrrrrrr      ',
        '  rrrrrrr       ',
        '   rrrrr        ');
    Cell::at($img, C_BASS_ICON)->rows($pal,
        '              ll',
        '             ll ',
        '            l   ',
        '           l    ',
        '          l     ',
        '         l      ',
        '    vvv l       ',
        '   vvvvvl       ',
        '  vvvvkvv       ',
        '  vvvkkvvv      ',
        '   vvvvvvvv     ',
        '  vvvvvvvvv     ',
        '  vvvvvvvv      ',
        '   vvvvvv       ');
    Cell::at($img, C_DRUM)->rows($pal,
        ' d            d ',
        '  d          d  ',
        '   d        d   ',
        '    WWWWWWWW    ',
        '  WWWWWWWWWWWW  ',
        '  rWWWWWWWWWWr  ',
        '  rrrrrrrrrrrr  ',
        '  ryrrryrrryrr  ',
        '  rrryrrryrrrr  ',
        '  rrrrrrrrrrrr  ',
        '  rrrrrrrrrrrr  ',
        '   yyyyyyyyyy   ');
    Cell::at($img, C_SHOE)->rows($pal,
        '', '', '', '',
        '     nnnnn      ',
        '     nWnWn      ',
        '     nnnnn      ',
        '     nnnnnnn    ',
        '    nnnnnnnnnn  ',
        '    WWWWWWWWWW  ');
    Cell::at($img, C_STONE_ICON)->rows($pal,
        '',
        '            y   ',
        '           yyy  ',
        '            y   ',
        '',
        '     bbbbb      ',
        '    bObbbbb     ',
        '   bbbbbbbBB    ',
        '   bbbbbbBBB  y ',
        '    BBBBBBB     ',
        '',
        '  gggg    pppp  ',
        ' gOgggG  pOpppP ',
        '  GGGG    PPPP  ');
}

/** Draws cell $i of $sheet into $dst, flipped and tinted as asked. */
function drawCell(Painter $p, Image $sheet, int $i, array $dst, ImageOptions $o = new ImageOptions()): void
{
    $p->imageRegion($sheet, [$i % 8 * CELL, intdiv($i, 8) * CELL, CELL, CELL], $dst, $o);
}

// The sound.

function midi(int $m): float { return 440 * 2 ** (($m - 69) / 12); }

/** An electric piano's tone: a sine with a soft second harmonic and a bell-like partial that fades fast. */
function ep(float $f, float $t): float
{
    if ($t < 0) { return 0.0; }
    return (sin(2 * M_PI * $f * $t) + 0.25 * sin(4 * M_PI * $f * $t) * exp(-$t * 3) + 0.2 * sin(2 * M_PI * $f * 14 * $t) * exp(-$t * 25)) * min($t / 0.004, 1);
}

/** A soft plucked string. */
function pluck(float $f, float $t): float
{
    if ($t < 0) { return 0.0; }
    return (sin(2 * M_PI * $f * $t) + 0.5 * sin(4 * M_PI * $f * $t) * exp(-$t * 6) + 0.25 * sin(6 * M_PI * $f * $t) * exp(-$t * 10)) * exp(-$t * 2.2) * min($t / 0.003, 1);
}

/**
 * The music: a lo-fi arrangement in E major and 6/8, with vinyl crackle
 * and a wavering tape, made as it plays. Tomori hums the melody over a
 * Rhodes; Anon brings guitar arpeggios, Soyo the bass, Taki the drums and
 * Rāna an echo of the melody an octave up. Its first bar, the Rhodes
 * alone, is an intro.
 */
final class Tune implements Decoder
{
    public const RATE = 22050;
    public const EIGHTH = 0.33; // seconds
    public const BAR = 6 * self::EIGHTH;
    public const BARS = 9;
    // The parts' voices, and the drums.
    private const RHODES = 0, HUM = 1, GUITAR = 2, BASS = 3, DRUMS = 4, ECHO = 5;
    private const KICK = 1, SNARE = 2, HAT = 3;
    private const PAN = [self::RHODES => 0, self::HUM => 0, self::GUITAR => -0.35, self::BASS => 0, self::DRUMS => 0.05, self::ECHO => 0.4];

    private int $pos = 0;
    /** Who plays, by bit. */
    public int $band = 0;
    /** @var list<float> that fade the bandmates in and out, per sample */
    private array $gains;
    /** @var list<array{member: int, voice: int, hits: list<array{t: float, freqs: list<float>, dur: float, drum: int, vel: float}>, times: list<float>}> */
    private array $parts = [];
    private Randomizer $rng;

    public function __construct()
    {
        $this->rng = new Randomizer(new Xoshiro256StarStar(55));
        $this->gains = array_fill(0, MEMBER_COUNT, 0.0);
        // Chords, as the Rhodes voices them: from the bass up.
        $emaj7 = [52, 56, 59, 63]; $fsm7 = [54, 57, 61, 64]; $dmaj7 = [54, 57, 61, 62]; $gsm7 = [56, 59, 63, 66];
        $b7sus4 = [59, 64, 66, 69]; $badd9 = [59, 61, 63, 66]; $fs7 = [54, 58, 61, 64]; $csm7 = [56, 59, 61, 64];
        $harmony = [[$emaj7, $emaj7], [$emaj7, $fsm7], [$dmaj7, $dmaj7], [$gsm7, $b7sus4], [$badd9, $badd9], [$badd9, $fs7], [$csm7, $fs7], [$badd9, $fs7]];
        // The bass's roots, a half bar each, and the intro's.
        $roots = [[40, 40], [40, 42], [42, 42], [44, 47], [47, 47], [47, 40], [49, 42], [47, 40]];
        $introRoot = 40;
        // The melody, a bar each: its notes as eighth, MIDI note and eighths long.
        $melody = [
            [[0, 71, 3], [3, 71, 1], [4, 73, 1], [5, 71, 1]],
            [[0, 73, 1], [1, 74, 1], [2, 71, 1], [3, 73, 3]],
            [[0, 73, 2], [2, 74, 1], [3, 73, 3]],
            [[0, 68, 3], [3, 76, 1], [4, 75, 2]],
            [[0, 75, 6]],
            [[0, 75, 3], [3, 66, 3]],
            [[0, 66, 1], [1, 75, 1], [2, 73, 1], [3, 71, 1], [4, 66, 1], [5, 70, 1]],
            [[0, 71, 6]],
        ];
        $parts = [];
        $add = function (int $member, int $voice, float $t, array $freqs, float $vel, int $drum = 0, float $dur = 0) use (&$parts): void {
            $parts[$voice] ??= ['member' => $member, 'voice' => $voice, 'hits' => []];
            $parts[$voice]['hits'][] = ['t' => $t, 'freqs' => $freqs, 'dur' => $dur, 'drum' => $drum, 'vel' => $vel];
        };
        $notes = fn(array $ms, int $shift = 0): array => array_map(fn(int $m) => midi($m + $shift), $ms);
        $at = fn(int $bar, int $eighth): float => ($bar * 6 + $eighth) * self::EIGHTH;
        $add(VOCALIST, self::RHODES, 0, $notes($fs7), 1);
        $add(BASSIST, self::BASS, 0, [midi($introRoot)], 1);
        for ($b = 1; $b < self::BARS; $b++) {
            for ($half = 0; $half < 2; $half++) {
                $chord = $harmony[$b - 1][$half]; $root = $roots[$b - 1][$half];
                $e = $half * 3;
                // The Rhodes, on each half bar, softer on the second.
                $add(VOCALIST, self::RHODES, $at($b, $e), $notes($chord), 1 - 0.3 * $half);
                // Anon's arpeggio, up the chord an octave higher.
                foreach ([1, 2, 3] as $i => $k) { $add(GUITARIST, self::GUITAR, $at($b, $e + $i), [midi($chord[$k] + 12)], 1 - 0.2 * $i); }
                // Soyo: the root, then its fifth.
                $add(BASSIST, self::BASS, $at($b, $e), [midi($root)], 1);
                $add(BASSIST, self::BASS, $at($b, $e + 2), [midi($root + 7)], 0.6);
            }
            // Taki: half time, a kick on the bar, the snare on its middle.
            $add(DRUMMER, self::DRUMS, $at($b, 0), [], 1, self::KICK);
            $add(DRUMMER, self::DRUMS, $at($b, 3), [], 1, self::SNARE);
            $add(DRUMMER, self::DRUMS, $at($b, 5), [], 0.5, self::KICK);
            foreach ([0.9, 0.4, 0.6, 0.9, 0.4, 0.6] as $i => $vel) { $add(DRUMMER, self::DRUMS, $at($b, $i) + 0.002, [], $vel, self::HAT); }
            foreach ($melody[$b - 1] as [$eighth, $m, $len]) {
                $add(VOCALIST, self::HUM, $at($b, $eighth), [midi($m)], 1, 0, $len * self::EIGHTH - 0.04);
                // Rāna's echo: an eighth later, an octave up.
                $add(LEAD, self::ECHO, $at($b, $eighth) + self::EIGHTH, [midi($m + 12)], 1, 0, $len * self::EIGHTH - 0.04);
            }
        }
        for ($v = 0; $v <= self::ECHO; $v++) {
            $p = $parts[$v];
            usort($p['hits'], fn(array $a, array $b) => $a['t'] <=> $b['t']); // stable, as PHP's sorts are
            $p['times'] = array_column($p['hits'], 't');
            $this->parts[] = $p;
        }
    }

    public function sampleRate(): int { return self::RATE; }
    public function channels(): int { return 2; }
    public function len(): int { return (int)round(self::BARS * self::BAR * self::RATE); }
    public function setPosition(int $frame): void { $this->pos = $frame; }

    /** Noise that is the same at the same time. */
    private static function noiseAt(float $x): float
    {
        $v = sin($x * 12.9898) * 43758.5453;
        return ($v - floor($v)) * 2 - 1;
    }

    /** A hummed note: a soft "oo" with a vibrato that grows, held for $dur, then let go. */
    private static function voiced(float $f, float $t, float $dur): float
    {
        if ($t < 0) { return 0.0; }
        $env = min($t / 0.05, 1);
        $env *= $t > $dur ? exp(-($t - $dur) * 12) : 1 - 0.2 * $t / max($dur, 0.1);
        $depth = $f * 0.007 * min($t / 0.35, 1);
        $ph = 2 * M_PI * $f * $t + $depth / 5.5 * sin(2 * M_PI * 5.5 * $t);
        return (sin($ph) + 0.3 * sin(2 * $ph) + 0.08 * sin(3 * $ph)) * $env;
    }

    /** What hit $h of a voice sounds like, $dt seconds after it. */
    private static function sound(int $voice, array $h, float $dt): float
    {
        switch ($h['drum']) {
            case self::KICK: return sin(2 * M_PI * (48 + 70 * exp(-$dt * 35)) * $dt) * exp(-$dt * 8) * min($dt / 0.002, 1) * 0.8 * $h['vel'];
            case self::SNARE: return (self::noiseAt($dt * 977) * 0.45 * exp(-$dt * 16) + sin(2 * M_PI * 185 * $dt) * 0.25 * exp(-$dt * 25)) * 0.45 * $h['vel'];
            case self::HAT: return self::noiseAt($dt * 1931) * exp(-$dt * 70) * 0.14 * $h['vel'];
        }
        $s = 0.0;
        foreach ($h['freqs'] as $i => $f) {
            switch ($voice) {
                case self::RHODES: // the chord strummed a little
                    $at = $dt - $i * 0.014;
                    $s += ep($f, $at) * exp(-max($at, 0) * 0.9) * 0.16;
                    break;
                case self::HUM: $s += self::voiced($f, $dt, $h['dur']) * 0.22; break;
                case self::GUITAR: $s += pluck($f, $dt) * 0.16; break;
                case self::BASS:
                    if ($dt > 0) { $s += (sin(2 * M_PI * $f * $dt) + 0.3 * sin(4 * M_PI * $f * $dt)) * exp(-$dt * 2) * min($dt / 0.01, 1) * 0.5; }
                    break;
                case self::ECHO: // a clean guitar, far away
                    if ($dt > 0) { $s += (sin(2 * M_PI * $f * $dt) + 0.2 * sin(4 * M_PI * $f * $dt)) * exp(-$dt * 3.5) * min($dt / 0.004, 1) * 0.06; }
                    break;
            }
        }
        return $s * $h['vel'];
    }

    /** The index of the last of the sorted $times at or before $t, -1 for none. @param list<float> $times */
    private static function before(array $times, float $t): int
    {
        $lo = 0; $hi = count($times);
        while ($lo < $hi) {
            $m = intdiv($lo + $hi, 2);
            if ($times[$m] > $t) { $hi = $m; } else { $lo = $m + 1; }
        }
        return $lo - 1;
    }

    public function read(int $frames): array
    {
        $band = $this->band | 1; // Tomori always plays
        $out = [];
        $end = min($this->len(), $this->pos + $frames);
        for (; $this->pos < $end; $this->pos++) {
            $sec = $this->pos / self::RATE;
            // The tape's wow: the pitch wavers a little.
            $wsec = $sec + 0.0018 * sin(2 * M_PI * 0.55 * $sec);
            for ($m = 0; $m < MEMBER_COUNT; $m++) {
                $want = ($band & (1 << $m)) !== 0 ? 1.0 : 0.0;
                $this->gains[$m] += ($want - $this->gains[$m]) * 0.0002;
            }
            $l = $r = 0.0;
            foreach ($this->parts as $p) {
                $g = $this->gains[$p['member']];
                if ($g < 0.001) { continue; }
                $i = self::before($p['times'], $wsec);
                $v = 0.0;
                // The hit sounding and those before it, still ringing.
                for ($j = max($i - 3, 0); $j <= $i; $j++) { $v += self::sound($p['voice'], $p['hits'][$j], $wsec - $p['hits'][$j]['t']); }
                $v *= $g;
                $pan = self::PAN[$p['voice']];
                $l += $v * (1 - $pan);
                $r += $v * (1 + $pan);
            }
            // Vinyl: a soft hiss, and now and then a crackle.
            $crackle = 0.004 * $this->rng->getFloat(-1, 1);
            if ($this->rng->getInt(0, 2499) === 0) { $crackle += $this->rng->getFloat(-1, 1) * 0.1; }
            // Warm: soft saturation.
            $out[] = tanh($l * 0.9) + $crackle;
            $out[] = tanh($r * 0.9) + $crackle;
        }
        return $out;
    }
}

/** The game's effects, synthesized once, and its music, made as it plays. */
final class Sounds
{
    private const SFX_RATE = 32000;
    /** @var array<int, Sound> by event */
    private array $sfx;
    public Player $music;
    public Tune $tune;
    private Randomizer $rng;
    /** Stones picked up in a row, and when the last one was, in steps. */
    private int $stones = 0;
    private float $lastStone = 0;

    public function __construct()
    {
        $rng = $this->rng = new Randomizer(new Xoshiro256StarStar(97));
        $noise = fn(): float => $rng->getFloat(-1, 1);
        $this->sfx = [
            // A sung "ah": a fifth up, with a little vibrato.
            EV_SING => self::synth(0.16, function (float $t): float {
                $f = 660 * (1 + 0.012 * sin(2 * M_PI * 7 * $t));
                return (sin(2 * M_PI * $f * $t) + 0.3 * sin(4 * M_PI * $f * $t) + 0.15 * sin(6 * M_PI * $f * $t)) * exp(-$t * 14) * min($t * 60, 1) * 0.16;
            }),
            EV_HIT => self::synth(0.05, fn(float $t) => $noise() * exp(-$t * 80) * 0.25),
            EV_KILL => self::synth(0.22, fn(float $t) => (sin(2 * M_PI * (300 + 1500 * $t) * $t) * 0.4 + $noise() * 0.15 * exp(-$t * 40)) * exp(-$t * 16)),
            // A pebble's click.
            EV_STONE => self::synth(0.06, fn(float $t) => (sin(2 * M_PI * 2300 * $t) + 0.5 * sin(2 * M_PI * 3400 * $t)) * exp(-$t * 70) * 0.22),
            EV_LEVEL => self::synth(0.8, function (float $t): float {
                $s = 0.0;
                foreach ([587.33, 739.99, 880, 1174.66] as $i => $f) {
                    $nt = $t - $i * 0.08;
                    if ($nt > 0) { $s += ep($f, $nt) * exp(-$nt * 3); }
                }
                return $s * 0.14;
            }),
            EV_HURT => self::synth(0.3, fn(float $t) => (sin(2 * M_PI * (160 - 220 * $t) * $t) * 0.7 + $noise() * 0.3) * exp(-$t * 12) * 0.45),
            // Taki's beat: a kick and a crash.
            EV_BEAT => self::synth(0.6, fn(float $t) => (sin(2 * M_PI * (55 + 90 * exp(-$t * 30)) * $t) * exp(-$t * 9) * 0.8 + $noise() * 0.3 * exp(-$t * 5)) * 0.45),
            EV_CHOOSE => self::synth(0.35, fn(float $t) => (ep(880, $t) + 0.6 * ep(1318.5, $t - 0.05)) * exp(-$t * 7) * 0.15),
            // A bandmate joins: a strummed D major chord.
            EV_JOIN => self::synth(1.4, function (float $t): float {
                $s = 0.0;
                foreach ([50, 57, 62, 66, 69, 74] as $i => $m) {
                    $nt = $t - $i * 0.025;
                    if ($nt > 0) { $s += pluck(midi($m), $nt); }
                }
                return $s * 0.12;
            }),
            EV_OVER => self::synth(1.6, function (float $t): float {
                $s = 0.0;
                foreach ([74, 71, 69, 66, 62] as $i => $m) {
                    $nt = $t - $i * 0.25;
                    if ($nt > 0) { $s += ep(midi($m), $nt) * exp(-$nt * 2.5); }
                }
                return $s * 0.15;
            }),
        ];
        // The first bar is an intro: then the rest loops.
        $this->tune = new Tune();
        $this->music = Audio::newPlayer($this->tune)->setLoopRange(Tune::BAR, 0)->setVolume(0.5);
    }

    /** A sound of $d seconds from $f, a function of the time. */
    private static function synth(float $d, Closure $f): Sound
    {
        $s = [];
        $n = (int)($d * self::SFX_RATE);
        for ($i = 0; $i < $n; $i++) {
            $t = $i / self::SFX_RATE;
            $s[] = $f($t) * min($t / 0.002, ($d - $t) / 0.01, 1); // no click at either end
        }
        return Audio::newSound(self::SFX_RATE, 1, $s);
    }

    /**
     * Plays the sounds of what happened in step $n, a little different each
     * time, as games vary effects that play often. Stones picked up in a
     * row climb in pitch. @param list<int> $events
     */
    public function play(array $events, float $n): void
    {
        $sang = false;
        foreach ($events as $e) {
            $volume = 1.0; $speed = 0.94 + $this->rng->getFloat(0, 0.12);
            switch ($e) {
                case EV_SING:
                    if ($sang) { continue 2; } // one for a volley
                    $sang = true; $volume = 0.7;
                    // On the notes of the tune's scale.
                    $speed = 2 ** ([0, 2, 4, 7, 9][$this->rng->getInt(0, 4)] / 12);
                    break;
                case EV_HIT: $volume = 0.5; break;
                case EV_KILL: $volume = 0.55; break;
                case EV_STONE:
                    if ($n - $this->lastStone > 30) { $this->stones = 0; }
                    $this->lastStone = $n;
                    $speed = 2 ** (min($this->stones, 12) / 12);
                    $this->stones++;
                    break;
                case EV_LEVEL: case EV_CHOOSE: case EV_JOIN: case EV_OVER: $speed = 1.0; break;
            }
            $this->sfx[$e]->newPlayer()->setVolume($volume)->setSpeed($speed)->play();
        }
    }

    /** Tells the music who plays: each bandmate adds their part. @param list<int> $band */
    public function setBand(array $band): void
    {
        $mask = 0;
        foreach ($band as $m) { $mask |= 1 << $m; }
        $this->tune->band = $mask;
    }
}

// The game.

/** A 32-bit product of $a and $b, without the float a 64-bit product can overflow to. */
function mul32(int $a, int $b): int
{
    return ($a * ($b & 0xffff) + ((($a * ($b >> 16)) & 0xffff) << 16)) & 0xffffffff;
}

/** The street's tile at ($x, $y): paving, here and there sakura petals, a crack or a drain. */
function tileAt(int $x, int $y): int
{
    $n = mul32($x & 0xffffffff, 0x9e3779b1) ^ mul32($y & 0xffffffff, 0x85ebca77);
    $n = mul32($n ^ ($n >> 15), 0x2c1b3c6d);
    $v = ($n ^ ($n >> 13)) % 100;
    return match (true) {
        $v < 3 => C_PETALS,
        $v < 5 => C_PETALS2,
        $v < 7 => C_CRACK,
        $v < 8 => C_GRATE,
        $v < 50 => C_PAVE2,
        default => C_PAVE,
    };
}

/** How many points a pixel of the art takes: a whole number of the screen's, about 200 of them across the view's shorter side. */
function zoom(Painter $p, array $r): float
{
    $s = $p->scale();
    return max(2, round(min($r[2], $r[3]) * $s / 200)) / $s;
}

const WHITE = 0xffffff;
const MUTED = 0xc8c4dc;
const GOLD = 0xffd95a;
const PINK = 0xf6b8cf;

/** Draws a line of $text centered at $x, from $y down, at $size and $weight in $rgb at $alpha. */
function center(Painter $p, float $x, float $y, string $text, float $size, int $rgb, int $weight = 400, float $alpha = 1.0, float $spacing = 0): void
{
    $s = new Span($text, $size, color: $rgb, weight: $weight, alpha: $alpha, letterSpacing: $spacing);
    [$w] = $p->measureText(0, $s);
    $p->richText($x - $w / 2, $y, 0, $s);
}

function dim(Painter $p, array $r): void { $p->fill($r, 0x0e0c1c, 0, 0.7); }

function clock(float $sec): string { return sprintf('%d:%02d', intdiv((int)$sec, 60), (int)$sec % 60); }

/** Where the upgrades offered show in the view $r. @return list<array{0: float, 1: float, 2: float, 3: float}> */
function cards(int $n, array $r): array
{
    $gap = 14; $ch = 176;
    $cw = min(200, ($r[2] - 40 - $gap * 2) / 3);
    $total = $n * $cw + ($n - 1) * $gap;
    $x = $r[0] + ($r[2] - $total) / 2; $y = $r[1] + $r[3] / 2 - $ch / 2 + 16;
    $out = [];
    for ($i = 0; $i < $n; $i++) { $out[] = [$x + $i * ($cw + $gap), $y, $cw, $ch]; }
    return $out;
}

/** The game Game::view runs: update() steps the world with the input, draw() draws it. */
final class Mayoi extends Game
{
    public World $w;
    public Image $sheet;
    /** The player wants the music (M); it plays; paused by the player; the world did not step, so nothing moves between steps. */
    public bool $music = true, $sounding = false, $paused = false, $frozen = false;
    public float $steps = 0;
    /** The upgrade chosen with keys or a pad. */
    public int $pick = 0;
    private State $pad;
    private Randomizer $rng;

    /** $snd plays the game's sounds; tests play none. */
    public function __construct(public readonly ?Sounds $snd = null)
    {
        $this->w = World::seeded(random_int(0, PHP_INT_MAX));
        $this->sheet = sheet();
        $this->pad = new State();
        $this->rng = new Randomizer(new Xoshiro256StarStar(11));
    }

    public function start(): void
    {
        $this->w->reset();
        $this->paused = $this->frozen = false;
        $this->pick = 0;
    }

    /** Plays or pauses the music, as the game wants it. */
    private function playMusic(bool $on): void
    {
        if ($on === $this->sounding) { return; }
        $this->sounding = $on;
        if ($this->snd === null) { return; }
        $on ? $this->snd->music->play() : $this->snd->music->pause();
    }

    /** Reads the keys, the pointer and the gamepad, and steps the world unless it waits for the player, then plays what happened. */
    public function update(Input $in): void
    {
        $w = $this->w;
        $this->steps++;
        [$pad, $prev] = $this->readPad();
        $padPressed = fn(Button $b): bool => $pad->justPressed($prev, $b);
        $confirm = $in->pressed(Key::SPACE) || $in->pressed(Key::RETURN) || $padPressed(Button::South);
        if ($in->pressed(Key::M)) { $this->music = !$this->music; }
        $this->frozen = true;
        switch ($w->state) {
            case TITLE:
                if ($confirm || $in->pointerPressed(0) || $padPressed(Button::Start)) { $this->start(); }
                break;
            case OVER:
                if ($w->overTime > 0.8 && ($confirm || $in->pointerPressed(0) || $padPressed(Button::Start))) { $this->start(); }
                $w->step(STEP_TIME);
                break;
            case CHOOSING:
                $this->choose($in, $pad, $prev, $confirm);
                break;
            case PLAYING:
                if ($in->pressed(Key::ESCAPE) || $in->pressed(Key::P) || $padPressed(Button::Start)) { $this->paused = !$this->paused; }
                if ($this->paused && ($confirm || $in->pointerPressed(0))) { $this->paused = false; }
                $mx = 0.0; $my = 0.0;
                if ($in->down(Key::LEFT) || $in->down(Key::A) || $pad->pressed(Button::Left)) { $mx--; }
                if ($in->down(Key::RIGHT) || $in->down(Key::D) || $pad->pressed(Button::Right)) { $mx++; }
                if ($in->down(Key::UP) || $in->down(Key::W) || $pad->pressed(Button::Up)) { $my--; }
                if ($in->down(Key::DOWN) || $in->down(Key::S) || $pad->pressed(Button::Down)) { $my++; }
                [$sx, $sy] = $pad->leftStick();
                $move = new Vec($mx + $sx, $my + $sy);
                $w->move = $move->len() > 1 ? $move->norm() : $move;
                // Without the keyboard, the player is elsewhere: wait for them.
                if (!$this->paused && $in->focused()) {
                    $w->step(STEP_TIME);
                    $this->frozen = false;
                }
                break;
        }
        // The music plays with the game: not before it, nor while it waits for a player who paused it or is elsewhere.
        $this->playMusic($this->music && $w->state !== TITLE && $in->focused() && !($w->state === PLAYING && $this->paused));
        if ($this->snd !== null) {
            $this->snd->setBand($w->band);
            if ($w->events !== []) { $this->snd->play($w->events, $this->steps); }
        }
        $w->events = [];
    }

    /** Takes the upgrade the player picks: by its number, a click, or moving to it and confirming. */
    private function choose(Input $in, State $pad, State $prev, bool $confirm): void
    {
        $w = $this->w;
        $n = count($w->choices);
        foreach ([Key::N1, Key::N2, Key::N3] as $i => $k) {
            if ($i < $n && $in->pressed($k)) { $this->pick = $i; $confirm = true; }
        }
        $left = $in->pressed(Key::LEFT) || $in->pressed(Key::A) || $pad->justPressed($prev, Button::Left);
        $right = $in->pressed(Key::RIGHT) || $in->pressed(Key::D) || $pad->justPressed($prev, Button::Right);
        [$px] = $prev->leftStick();
        [$x] = $pad->leftStick();
        $left = $left || $px > -0.5 && $x <= -0.5;
        $right = $right || $px < 0.5 && $x >= 0.5;
        if ($left) { $this->pick = ($this->pick + $n - 1) % $n; }
        if ($right) { $this->pick = ($this->pick + 1) % $n; }
        [$vw, $vh] = $in->size();
        [$x, $y, $over] = $in->pointer();
        if ($over) {
            foreach (cards($n, [0, 0, $vw, $vh]) as $i => $c) {
                if ($x >= $c[0] && $x < $c[0] + $c[2] && $y >= $c[1] && $y < $c[1] + $c[3]) {
                    $this->pick = $i;
                    if ($in->pointerPressed(0)) { $confirm = true; }
                }
            }
        }
        if ($confirm) {
            $w->choose($this->pick);
            $this->pick = 0;
        }
    }

    /** The first gamepad, and how it was the step before. @return array{0: State, 1: State} */
    private function readPad(): array
    {
        $prev = $this->pad;
        $pads = Gamepad::pads();
        $this->pad = $pads === [] ? new State() : $pads[0]->state();
        return [$this->pad, $prev];
    }

    /** Draws the world through a camera that follows Tomori, then the HUD and what waits for the player over it. */
    public function draw(Painter $p, array $r, float $alpha): void
    {
        $w = $this->w;
        if ($this->frozen) { $alpha = 1.0; }
        $z = zoom($p, $r);
        $w->view = new Vec($r[2] / 2 / $z, $r[3] / 2 / $z);
        $tomori = $w->prev->lerp($w->pos, $alpha);
        $cam = new Camera($tomori->x, $tomori->y - 6, $z);
        if ($w->shake > 0) {
            $cam->x += $this->rng->getFloat(-1, 1) * $w->shake * 10;
            $cam->y += $this->rng->getFloat(-1, 1) * $w->shake * 10;
        }
        $cam->draw($p, $r, fn() => $this->drawWorld($p, $cam->visible($r), $alpha, $tomori));
        // Numbers of damage, in the screen's pixels, sharp at any zoom.
        foreach ($w->popups as $pu) {
            $x = $r[0] + $r[2] / 2 + ($pu->pos->x - $cam->x) * $z;
            $y = $r[1] + $r[3] / 2 + ($pu->pos->y - $pu->t * 20 - $cam->y) * $z;
            $fade = 1 - $pu->t / 0.6;
            center($p, $x + 1, $y + 1, $pu->text, 13, 0x000000, 700, $fade * 0.6);
            center($p, $x, $y, $pu->text, 13, 0xfff0c8, 700, $fade);
        }
        if ($w->state !== TITLE) { $this->drawHUD($p, $r); }
        if ($w->state === TITLE) {
            $this->drawTitle($p, $r);
        } elseif ($w->state === CHOOSING) {
            $this->drawChoices($p, $r);
        } elseif ($w->state === OVER) {
            $this->drawOver($p, $r);
        } elseif ($this->frozen) {
            dim($p, $r);
            center($p, $r[0] + $r[2] / 2, $r[1] + $r[3] / 2 - 24, 'Paused', 28, WHITE, 700);
            center($p, $r[0] + $r[2] / 2, $r[1] + $r[3] / 2 + 14, 'Click or press Space to play', 14, MUTED);
        }
    }

    /** Draws what the camera sees of the world, in its pixels: the street, the stones, the beat, the enemies and the band back to front, then the picks and the notes. */
    private function drawWorld(Painter $p, array $vis, float $a, Vec $tomori): void
    {
        $w = $this->w;
        $x0 = (int)floor($vis[0] / CELL) - 1; $y0 = (int)floor($vis[1] / CELL) - 1;
        $x1 = (int)ceil(($vis[0] + $vis[2]) / CELL) + 1; $y1 = (int)ceil(($vis[1] + $vis[3]) / CELL) + 1;
        for ($ty = $y0; $ty < $y1; $ty++) {
            for ($tx = $x0; $tx < $x1; $tx++) { drawCell($p, $this->sheet, tileAt($tx, $ty), [$tx * CELL, $ty * CELL, CELL, CELL]); }
        }
        $box = fn(Vec $v, float $dy): array => [$v->x - CELL / 2, $v->y - CELL + $dy, CELL, CELL];
        if ($w->beatFlash > 0) {
            $t = $w->beatFlash / 0.4;
            $rr = $w->beatRadius() * (1 - 0.4 * $t);
            $ring = [$tomori->x - $rr, $tomori->y - 5 - $rr, 2 * $rr, 2 * $rr];
            $p->fill($ring, 0xa05ad6, $rr, $t * 0.18);
            $p->stroke($ring, 0xdcbeff, $rr, 2, $t);
        }
        foreach ($w->stones as $i => $s) {
            $c = $s->value >= 5 ? C_STONE + 2 : ($s->value >= 2 ? C_STONE + 1 : C_STONE);
            drawCell($p, $this->sheet, $c, $box($s->prev->lerp($s->pos, $a), 4 + sin($this->steps * 0.08 + $i)));
        }
        foreach ($w->puffs as $pu) {
            drawCell($p, $this->sheet, C_PUFF + min((int)($pu->t / 0.32 * 3), 2), $box($pu->pos, 4), new ImageOptions(tint: 0xffffff, tintAlpha: 1 - $pu->t / 0.32));
        }
        // The band and the enemies, by their feet, back to front: [where, the enemy or null, the bandmate].
        $figures = [];
        $walking = $w->move->len() > 0.05 && $w->state === PLAYING;
        if ($w->state !== TITLE) {
            $figures[] = [$tomori, null, VOCALIST];
            foreach ($w->band as $i => $m) { $figures[] = [$w->follower($i), null, $m]; }
        }
        foreach ($w->enemies as $e) { $figures[] = [$e->prev->lerp($e->pos, $a), $e, 0]; }
        usort($figures, fn(array $x, array $y) => $x[0]->y <=> $y[0]->y);
        foreach ($figures as [$at, $e, $member]) {
            if ($e === null) {
                if ($member === VOCALIST && $w->hurt > 0 && (int)($w->hurt * 14) % 2 === 0) { continue; } // blinking, unhurtable
                $frame = $walking ? (int)($w->walked / 7 + $member) % 2 : 0;
                $left = $member === VOCALIST ? $w->facingLeft : $at->x > $tomori->x + 2 || ($at->x >= $tomori->x - 2 && $w->facingLeft);
                drawCell($p, $this->sheet, C_MEMBER + $member * 2 + $frame, $box($at, 1), new ImageOptions(flipX: $left));
                continue;
            }
            [$c, $speed] = match ($e->kind) { KIND_CROW => [C_CROW, 9], KIND_SHADOW => [C_SHADOW, 3], default => [C_STATIC, 6] };
            drawCell($p, $this->sheet, $c + (int)($e->phase * $speed) % 2, $box($at, 1), new ImageOptions(flipX: $at->x > $tomori->x, tint: $e->flash > 0 ? 0xff8caa : null));
        }
        $n = $w->levels[UP_PICKS];
        for ($i = 0; $i < $n; $i++) {
            $b = $w->pickPos($i, $n, $tomori, $w->pickAngle);
            $deg = $w->pickAngle * 180 / M_PI + $i * 360 / $n;
            $p->transform(Transform::rotation($deg), $b->x, $b->y, fn() => drawCell($p, $this->sheet, C_PICK, [$b->x - 6, $b->y - 7, 12, 12]));
        }
        foreach ($w->sung as $i => $note) {
            $at = $note->prev->lerp($note->pos, $a);
            drawCell($p, $this->sheet, C_NOTE, [$at->x - 7, $at->y - 9 + sin($this->steps * 0.3 + $i) * 1.5, 14, 14]);
        }
    }

    /** Draws the experience bar, the band-aids, the time, the band, the stones collected, and who just joined. */
    private function drawHUD(Painter $p, array $r): void
    {
        $w = $this->w;
        $bar = [$r[0], $r[1], $r[2], 8];
        $p->fill($bar, 0x000000, 0, 0.55);
        $bar[2] *= $w->xp / $w->xpNext;
        $p->fill($bar, PINK);
        for ($i = 0; $i < $w->maxHP; $i++) { drawCell($p, $this->sheet, $i >= $w->hp ? C_BANDAID_EMPTY : C_BANDAID, [$r[0] + 6 + $i * 24, $r[1] + 8, 30, 30]); }
        center($p, $r[0] + $r[2] / 2, $r[1] + 16, clock($w->time), 18, WHITE, 700);
        center($p, $r[0] + $r[2] / 2, $r[1] + 40, "Lv {$w->level}", 13, GOLD, 600);
        $stones = new Span((string)$w->collected, 16, color: WHITE, weight: 700);
        [$sw] = $p->measureText(0, $stones);
        $p->richText($r[0] + $r[2] - 14 - $sw, $r[1] + 17, 0, $stones);
        drawCell($p, $this->sheet, C_STONE + 1, [$r[0] + $r[2] - 46 - $sw, $r[1] + 4, 32, 32]);
        // The band so far, by the bottom left corner: who has yet to join is a silhouette.
        for ($m = 0; $m < MEMBER_COUNT; $m++) {
            $o = $m === VOCALIST || $w->joined($m) ? new ImageOptions() : new ImageOptions(tint: 0x000000, tintAlpha: 0.45);
            drawCell($p, $this->sheet, C_MEMBER + $m * 2, [$r[0] + 8 + $m * 30, $r[1] + $r[3] - 42, 32, 32], $o);
        }
        if ($w->bannerTime > 0) {
            $fade = min($w->bannerTime / 0.4, 1);
            $span = new Span($w->banner, 20, color: GOLD, weight: 800, alpha: $fade);
            [$bw, $bh] = $p->measureText(0, $span);
            $y = $r[1] + $r[3] * 0.24;
            $p->fill([$r[0] + ($r[2] - $bw) / 2 - 16, $y - 8, $bw + 32, $bh + 16], 0x0e0c1c, ($bh + 16) / 2, 0.75 * $fade);
            $p->richText($r[0] + ($r[2] - $bw) / 2, $y, 0, $span);
        }
    }

    private function drawTitle(Painter $p, array $r): void
    {
        dim($p, $r);
        $cx = $r[0] + $r[2] / 2; $cy = $r[1] + $r[3] / 2;
        for ($m = 0; $m < MEMBER_COUNT; $m++) {
            $bob = sin($this->steps * 0.08 + $m * 1.2) * 3;
            $x = $cx + ($m - 2) * 52 - 20;
            drawCell($p, $this->sheet, C_MEMBER + $m * 2 + (int)($this->steps / 20 + $m) % 2, [$x, $cy - 128 + $bob, 40, 40], new ImageOptions(flipX: $m > 2));
            center($p, $x + 20, $cy - 84, MEMBER_NAMES[$m], 11, MUTED, 600);
        }
        center($p, $cx, $cy - 58, 'MAYOI', 44, WHITE, 800, spacing: 8);
        center($p, $cx, $cy - 6, '迷子でもいい、前へ進め', 15, PINK);
        center($p, $cx, $cy + 18, 'Hold out against stage fright, gather stones, and find your band.', 14, MUTED);
        if ((int)($this->steps / 30) % 2 === 0) { center($p, $cx, $cy + 60, 'Click or press Space to start', 17, GOLD, 700); }
        center($p, $cx, $r[1] + $r[3] - 34, 'A MyGO!!!!! fan game · WASD or arrows walk · Esc pauses · M music · gamepads too', 12, MUTED);
    }

    private function drawChoices(Painter $p, array $r): void
    {
        $w = $this->w;
        dim($p, $r);
        $cs = cards(count($w->choices), $r);
        if ($cs !== []) { center($p, $r[0] + $r[2] / 2, $cs[0][1] - 50, "Level {$w->level}!", 28, GOLD, 800); }
        foreach ($cs as $i => $c) {
            $u = UPGRADES[$w->choices[$i]];
            $p->fill($c, 0x282240, 12, 0.96);
            if ($i === $this->pick) { $p->stroke($c, GOLD, 12, 2); }
            $p->text($c[0] + 10, $c[1] + 8, (string)($i + 1), 12, MUTED);
            $cx = $c[0] + $c[2] / 2;
            $icon = [$cx - 24, $c[1] + 14, 48, 48];
            $m = $u['member'];
            if ($m >= 0 && !$w->joined($m)) {
                // Who joins, with what they play.
                drawCell($p, $this->sheet, C_MEMBER + $m * 2, [$cx - 44, $c[1] + 14, 48, 48]);
                $icon = [$cx + 2, $c[1] + 26, 36, 36];
            }
            drawCell($p, $this->sheet, $u['icon'], $icon);
            center($p, $cx, $c[1] + 72, $u['name'], 15, WHITE, 700);
            foreach (explode("\n", $u['desc']) as $j => $line) { center($p, $cx, $c[1] + 98 + $j * 17, $line, 12, MUTED); }
            $lv = $w->levels[$w->choices[$i]];
            center($p, $cx, $c[1] + $c[3] - 24, $lv > 0 ? sprintf('Level %d → %d', $lv, $lv + 1) : 'New', 11, GOLD, 600);
        }
    }

    private function drawOver(Painter $p, array $r): void
    {
        $w = $this->w;
        dim($p, $r);
        $cx = $r[0] + $r[2] / 2; $cy = $r[1] + $r[3] / 2;
        center($p, $cx, $cy - 70, "It's okay to be lost.", 30, WHITE, 800);
        center($p, $cx, $cy - 22, sprintf('You held out %s with a band of %d, reached level %d,', clock($w->time), count($w->band) + 1, $w->level), 15, MUTED);
        center($p, $cx, $cy + 2, sprintf('calmed %d frights and collected %d stones.', $w->calmed, $w->collected), 15, MUTED);
        if ($w->overTime > 0.8) { center($p, $cx, $cy + 52, 'Click or press Space to go on', 17, GOLD, 700); }
    }
}

function view(Context $c, Mayoi $g): void
{
    Game::view($c, $g)->fill()->background(0x272c3f)->label('Mayoi');
}

App::setName('Mayoi');
$g = new Mayoi(new Sounds());
App::whenReady(function () use ($g) {
    new Window(new WindowOptions(title: 'Mayoi', width: 960, height: 640, content: Content::view(fn(Context $c) => view($c, $g))));
});
App::run();
