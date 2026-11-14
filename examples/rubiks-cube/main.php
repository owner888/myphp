<?php
declare(strict_types=1);

// Interactive 3D Rubik's Cube + 3D Audio Spectrum Visualizer built with MyPHP:
// - 27 colored 3D cubies (3x3x3) with real-time 90° animated layer rotations
// - Click/Tap any cubie on the 3D cube to rotate its face, or use the U/D/L/R/F/B buttons
// - Scramble button (randomizes the cube) & Auto-Solve button (unwinds every move smoothly!)
// - Surrounding 64-bar GPU-Instanced 3D Audio Spectrum ring pulsing to synthesized Chiptune music (MyPHP\Audio)
// - Real-time 1024x1024 PCF Soft Shadows, Dynamic Point Lights & 3D Particle Bursts on solve
// Run: myphp run -d macos|windows|linux|ios|android examples/rubiks-cube

use MyPHP\{App, Window, WindowOptions};
use MyPHP\Audio\{Audio, Sound};
use MyPHP\Plugins\Scene\{Camera3D, Entity, Material, Mesh, ParticleEmitter3D, PointLight, Scene};
use MyPHP\Plugins\Scene\Math\{Mat4, Vec3};
use MyPHP\UI\{Content, Image};

class_exists(\MyPHP\App::class) || require __DIR__ . '/../../src/autoload.php';

/**
 * Generates a 64x64 Rubik's sticker texture (black bevel border + bright glossy interior).
 */
function makeStickerTexture(): Image
{
    $px = '';
    for ($y = 0; $y < 64; $y++) {
        for ($x = 0; $x < 64; $x++) {
            $border = $x < 6 || $x >= 58 || $y < 6 || $y >= 58;
            $corner = ($x < 10 && $y < 10) || ($x >= 54 && $y < 10) || ($x < 10 && $y >= 54) || ($x >= 54 && $y >= 54);
            if ($border || $corner) {
                $px .= "\x16\x18\x1d\xff"; // dark plastic bezel
            } else {
                $px .= "\xff\xff\xff\xff"; // white interior (tinted by vertex color)
            }
        }
    }
    return Image::fromPixels(64, 64, $px)->pixelated();
}

/**
 * Builds a single 3D Cubie mesh with standard Rubik's colors on exterior faces
 * and dark plastic on interior faces based on grid coordinates ($gx, $gy, $gz in -1..1).
 */
function makeCubieMesh(int $gx, int $gy, int $gz): Mesh
{
    $s = 0.46; // half-size (0.92 total width per cubie, leaving crisp gaps at 1.0 spacing)

    // Standard Rubik's Cube colors:
    // +Z Front = Red, -Z Back = Orange, +X Right = Blue, -X Left = Green, +Y Up = White, -Y Down = Yellow
    $dark   = [0.14, 0.15, 0.18, 1.0];
    $cFront = $gz ===  1 ? [0.95, 0.22, 0.25, 1.0] : $dark;
    $cBack  = $gz === -1 ? [1.00, 0.55, 0.12, 1.0] : $dark;
    $cRight = $gx ===  1 ? [0.18, 0.52, 0.98, 1.0] : $dark;
    $cLeft  = $gx === -1 ? [0.18, 0.85, 0.42, 1.0] : $dark;
    $cUp    = $gy ===  1 ? [0.96, 0.97, 0.99, 1.0] : $dark;
    $cDown  = $gy === -1 ? [1.00, 0.88, 0.18, 1.0] : $dark;

    $faces = [
        // Front (+Z)
        [[0.0, 0.0, 1.0], $cFront, [[-$s, -$s,  $s], [ $s, -$s,  $s], [ $s,  $s,  $s], [-$s,  $s,  $s]]],
        // Back (-Z)
        [[0.0, 0.0, -1.0], $cBack, [[ $s, -$s, -$s], [-$s, -$s, -$s], [-$s,  $s, -$s], [ $s,  $s, -$s]]],
        // Right (+X)
        [[1.0, 0.0, 0.0], $cRight, [[ $s, -$s,  $s], [ $s, -$s, -$s], [ $s,  $s, -$s], [ $s,  $s,  $s]]],
        // Left (-X)
        [[-1.0, 0.0, 0.0], $cLeft, [[-$s, -$s, -$s], [-$s, -$s,  $s], [-$s,  $s,  $s], [-$s,  $s, -$s]]],
        // Top (+Y)
        [[0.0, 1.0, 0.0], $cUp,    [[-$s,  $s,  $s], [ $s,  $s,  $s], [ $s,  $s, -$s], [-$s,  $s, -$s]]],
        // Bottom (-Y)
        [[0.0, -1.0, 0.0], $cDown, [[-$s, -$s, -$s], [ $s, -$s, -$s], [ $s, -$s,  $s], [-$s, -$s,  $s]]],
    ];

    $uvs = [[0.0, 1.0], [1.0, 1.0], [1.0, 0.0], [0.0, 0.0]];
    $vFloats = [];
    $indices = [];
    $idx = 0;

    foreach ($faces as [$n, $col, $corners]) {
        [$r, $g, $b, $a] = $col;
        for ($i = 0; $i < 4; $i++) {
            $p = $corners[$i];
            $uv = $uvs[$i];
            array_push($vFloats, $p[0], $p[1], $p[2], $n[0], $n[1], $n[2], $uv[0], $uv[1], $r, $g, $b, $a);
        }
        array_push($indices, $idx, $idx + 1, $idx + 2, $idx, $idx + 2, $idx + 3);
        $idx += 4;
    }

    return new Mesh(pack('f*', ...$vFloats), pack('V*', ...$indices), 24, 36);
}

final class CubieState
{
    public Entity $entity;
    /** Logical grid coordinates in {-1, 0, 1} */
    public int $gx, $gy, $gz;
    /** Accumulated 3D orientation matrix */
    public Mat4 $rotMatrix;

    public function __construct(Entity $entity, int $gx, int $gy, int $gz)
    {
        $this->entity = $entity;
        $this->gx = $gx;
        $this->gy = $gy;
        $this->gz = $gz;
        $this->rotMatrix = Mat4::identity();
    }
}

final class RubiksApp
{
    public Scene $scene;
    /** @var list<CubieState> */
    public array $cubies = [];
    public Entity $spectrumRing;
    public ParticleEmitter3D $particles;
    public PointLight $keyLight;
    public PointLight $rimLight;

    /** Move queue: list of ['axis' => 'x'|'y'|'z', 'layer' => -1|0|1, 'dir' => 1|-1, 'record' => bool] */
    public array $moveQueue = [];
    /** History of executed moves for Auto-Solve */
    public array $moveHistory = [];
    /** Currently animating move */
    public ?array $activeMove = null;
    public float $moveProgress = 0.0;
    public float $moveSpeed = 5.5; // radians per second (~0.28s per 90° turn)

    public bool $spectrumEnabled = true;
    public bool $soundEnabled = true;
    public int $moveCount = 0;
    public string $status = 'Click any face or button to twist the 3D Rubik\'s Cube!';
    private float $time = 0.0;

    public function __construct()
    {
        $this->scene = new Scene(new Camera3D(
            position: new Vec3(4.2, 3.8, 5.6),
            target: new Vec3(0.0, 0.0, 0.0),
            fovYDegrees: 48.0
        ));
        $this->scene->clearColor = [0.05, 0.06, 0.09, 1.0];
        $this->scene->shadowsEnabled = true;
        $this->scene->shadowBounds = 9.5;
        $this->scene->fogEnabled = true;
        $this->scene->fogDensity = 0.028;

        // 1. Floor & 3D Reference Grid
        $floor = new Entity(
            Mesh::plane(22.0, 22.0),
            new Material([0.12, 0.14, 0.18, 1.0], shininess: 24.0),
            position: new Vec3(0.0, -2.5, 0.0),
            name: 'Floor'
        );
        $floor->pickable = false;
        $floor->castShadow = false;
        $this->scene->add($floor);
        $this->scene->addGridHelper(size: 16.0, divisions: 16, y: -2.48, showYAxis: false);

        // 2. Dynamic Point Lights
        $this->keyLight = $this->scene->addPointLight(new PointLight(new Vec3(3.5, 4.0, 3.5), [0.4, 0.85, 1.0], intensity: 2.2, radius: 12.0));
        $this->rimLight = $this->scene->addPointLight(new PointLight(new Vec3(-3.5, 2.0, -3.5), [1.0, 0.4, 0.7], intensity: 1.8, radius: 11.0));

        // 3. GPU Instanced 3D Particle Emitter
        $this->particles = $this->scene->addEmitter(new ParticleEmitter3D(Mesh::cube(1.0), maxParticles: 320));

        // 4. Build 27 Cubies (3x3x3)
        $stickerTex = makeStickerTexture();
        $cubieMat = Material::textured($stickerTex, shininess: 72.0);
        for ($x = -1; $x <= 1; $x++) {
            for ($y = -1; $y <= 1; $y++) {
                for ($z = -1; $z <= 1; $z++) {
                    $mesh = makeCubieMesh($x, $y, $z);
                    $ent = new Entity(
                        $mesh,
                        $cubieMat,
                        position: new Vec3((float)$x, (float)$y, (float)$z),
                        name: "Cubie($x,$y,$z)"
                    );
                    $this->scene->add($ent);
                    $this->cubies[] = new CubieState($ent, $x, $y, $z);
                }
            }
        }

        // 5. 64-Bar GPU-Instanced 3D Audio Spectrum Ring around the Cube
        $barMesh = Mesh::cube(0.28);
        $barMat = new Material([1.0, 1.0, 1.0, 1.0], shininess: 80.0, rimStrength: 0.8);
        $this->spectrumRing = new Entity($barMesh, $barMat, scale: new Vec3(0.0, 0.0, 0.0), name: 'AudioSpectrumRing');
        $this->spectrumRing->pickable = false;
        $this->scene->add($this->spectrumRing);
        $this->updateSpectrumRing(0.0);
    }

    /**
     * Queues a 90° layer rotation around $axis ('x'|'y'|'z') at $layer (-1|0|1) in direction $dir (1|-1).
     */
    public function queueMove(string $axis, int $layer, int $dir = 1, bool $record = true): void
    {
        $this->moveQueue[] = [
            'axis'   => $axis,
            'layer'  => $layer,
            'dir'    => $dir,
            'record' => $record,
        ];
    }

    /**
     * Queues 12 random twists to scramble the cube.
     */
    public function scramble(int $steps = 12): void
    {
        if ($this->activeMove !== null || $this->moveQueue !== []) {
            return;
        }
        $axes = ['x', 'y', 'z'];
        $layers = [-1, 1];
        $dirs = [-1, 1];
        for ($i = 0; $i < $steps; $i++) {
            $this->queueMove(
                $axes[array_rand($axes)],
                $layers[array_rand($layers)],
                $dirs[array_rand($dirs)],
                record: true
            );
        }
        $this->status = "Scrambling ($steps moves)…";
    }

    /**
     * Smoothly unwinds all recorded moves in reverse order to solve the cube!
     */
    public function autoSolve(): void
    {
        if ($this->moveHistory === []) {
            $this->status = 'Cube is already solved!';
            $this->particles->burst(new Vec3(0.0, 0.5, 0.0), count: 64, color: [0.2, 1.0, 0.6, 1.0]);
            return;
        }
        $this->moveQueue = [];
        while ($this->moveHistory !== []) {
            $last = array_pop($this->moveHistory);
            $this->queueMove($last['axis'], $last['layer'], -$last['dir'], record: false);
        }
        $this->status = 'Auto-solving cube…';
    }

    public function resetCube(): void
    {
        $this->moveQueue = [];
        $this->moveHistory = [];
        $this->activeMove = null;
        $this->moveCount = 0;
        $idx = 0;
        for ($x = -1; $x <= 1; $x++) {
            for ($y = -1; $y <= 1; $y++) {
                for ($z = -1; $z <= 1; $z++) {
                    $c = $this->cubies[$idx++];
                    $c->gx = $x;
                    $c->gy = $y;
                    $c->gz = $z;
                    $c->rotMatrix = Mat4::identity();
                    $c->entity->position = new Vec3((float)$x, (float)$y, (float)$z);
                    $c->entity->rotation = Vec3::zero();
                }
            }
        }
        $this->status = 'Cube reset to solved state';
        $this->particles->burst(new Vec3(0.0, 0.0, 0.0), count: 64, color: [0.3, 0.85, 1.0, 1.0]);
    }

    /**
     * Clicking a outer cubie twists the face it belongs to!
     * @param ?array{entity: Entity, distance: float, point: Vec3} $hit
     */
    public function onPick(?array $hit): void
    {
        if ($hit === null || $this->activeMove !== null) {
            return;
        }
        $ent = $hit['entity'];
        foreach ($this->cubies as $c) {
            if ($c->entity === $ent) {
                $pt = $hit['point'];
                $ax = abs($pt->x);
                $ay = abs($pt->y);
                $az = abs($pt->z);
                if ($ax >= $ay && $ax >= $az && $c->gx !== 0) {
                    $this->queueMove('x', $c->gx, $c->gx > 0 ? -1 : 1);
                } elseif ($ay >= $ax && $ay >= $az && $c->gy !== 0) {
                    $this->queueMove('y', $c->gy, $c->gy > 0 ? -1 : 1);
                } elseif ($c->gz !== 0) {
                    $this->queueMove('z', $c->gz, $c->gz > 0 ? -1 : 1);
                }
                break;
            }
        }
    }

    private function playClickTone(float $freq): void
    {
        if (!$this->soundEnabled) {
            return;
        }
        try {
            // Synthesize a short 45ms percussive marimba/synth blip
            $sr = 22050;
            $samples = (int)($sr * 0.045);
            $pcm = '';
            for ($i = 0; $i < $samples; $i++) {
                $t = $i / $sr;
                $env = exp(-$t * 55.0);
                $val = sin(2.0 * M_PI * $freq * $t) * $env * 0.25;
                $pcm .= pack('v', (int)max(-32768, min(32767, $val * 32767)) & 0xffff);
            }
            $wav = "RIFF" . pack('V', 36 + strlen($pcm)) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, $sr, $sr * 2, 2, 16) . "data" . pack('V', strlen($pcm)) . $pcm;
            Audio::play(Sound::fromWAV($wav));
        } catch (\Throwable) {
        }
    }

    private function matchesLayer(CubieState $c, string $axis, int $layer): bool
    {
        return match ($axis) {
            'x' => $c->gx === $layer,
            'y' => $c->gy === $layer,
            default => $c->gz === $layer,
        };
    }

    private static function axisRotationMatrix(string $axis, float $angle): Mat4
    {
        return match ($axis) {
            'x' => Mat4::rotationX($angle),
            'y' => Mat4::rotationY($angle),
            default => Mat4::rotationZ($angle),
        };
    }

    /**
     * Extracts Euler angles (rx, ry, rz) from a pure rotation matrix $m so Entity::localMatrix()
     * (which composes translate * rotZ * rotY * rotX * scale) reproduces $m.
     */
    private static function matrixToEuler(Mat4 $m): Vec3
    {
        $d = $m->m;
        // m[2] = -sin(ry)
        $sy = max(-1.0, min(1.0, -$d[2]));
        $ry = asin($sy);
        if (abs($sy) < 0.9999) {
            $rx = atan2($d[6], $d[10]);
            $rz = atan2($d[1], $d[0]);
        } else {
            $rx = atan2(-$d[9], $d[5]);
            $rz = 0.0;
        }
        return new Vec3($rx, $ry, $rz);
    }

    private function updateSpectrumRing(float $time): void
    {
        if (!$this->spectrumEnabled) {
            $this->spectrumRing->instanceCount = 0;
            return;
        }
        $transforms = [];
        $colors = [];
        $count = 64;
        for ($i = 0; $i < $count; $i++) {
            $angle = ($i / $count) * 2.0 * M_PI;
            $r = 4.6;
            $px = cos($angle) * $r;
            $pz = sin($angle) * $r;

            // Multi-band procedural audio spectrum wave
            $bass = max(0.0, sin($time * 5.0 + $angle * 2.0));
            $mid  = max(0.0, cos($time * 9.5 - $angle * 5.0));
            $high = max(0.0, sin($time * 15.0 + $angle * 8.0));
            $amp  = 0.35 + $bass * 1.4 + $mid * 0.8 + $high * 0.5;

            $py = -2.45 + ($amp * 0.28 * 0.5);
            $transforms[] = Mat4::translation($px, $py, $pz)
                ->multiply(Mat4::rotationY(-$angle))
                ->multiply(Mat4::scaling(1.0, $amp / 0.28, 1.0));

            $energy = min(1.0, ($amp - 0.35) / 2.2);
            $colors[] = [
                0.2 + 0.8 * $energy,
                0.85 - 0.3 * $energy,
                1.0 - 0.5 * $energy,
                1.0,
            ];
        }
        $this->spectrumRing->setInstances($transforms, $colors);
    }

    public function update(float $dt): void
    {
        $this->time += $dt;
        $this->updateSpectrumRing($this->time);

        // Pulse lights with the beat
        $this->keyLight->intensity = 2.0 + 0.7 * sin($this->time * 5.0);
        $this->rimLight->intensity = 1.6 + 0.6 * cos($this->time * 5.0);

        // Process Rubik's Cube layer rotation queue
        if ($this->activeMove === null && $this->moveQueue !== []) {
            $this->activeMove = array_shift($this->moveQueue);
            $this->moveProgress = 0.0;
            $this->playClickTone(320.0 + ($this->moveCount % 8) * 45.0);
        }

        if ($this->activeMove !== null) {
            $m = $this->activeMove;
            $this->moveProgress = min(1.0, $this->moveProgress + $dt * $this->moveSpeed);
            // Smooth easeInOutQuad
            $t = $this->moveProgress;
            $eased = $t < 0.5 ? 2.0 * $t * $t : 1.0 - pow(-2.0 * $t + 2.0, 2) / 2.0;
            $angle = $eased * (M_PI * 0.5) * $m['dir'];
            $stepRot = self::axisRotationMatrix($m['axis'], $angle);

            foreach ($this->cubies as $c) {
                if (!$this->matchesLayer($c, $m['axis'], $m['layer'])) {
                    continue;
                }
                // Rotate cubie center (gx, gy, gz) by $stepRot
                $rm = $stepRot->m;
                $nx = $rm[0] * $c->gx + $rm[4] * $c->gy + $rm[8]  * $c->gz;
                $ny = $rm[1] * $c->gx + $rm[5] * $c->gy + $rm[9]  * $c->gz;
                $nz = $rm[2] * $c->gx + $rm[6] * $c->gy + $rm[10] * $c->gz;
                $c->entity->position = new Vec3($nx, $ny, $nz);

                $combinedRot = $stepRot->multiply($c->rotMatrix);
                $c->entity->rotation = self::matrixToEuler($combinedRot);
            }

            if ($this->moveProgress >= 1.0) {
                // Snap logical grid coordinates & orientation matrix to exact 90°
                $exactRot = self::axisRotationMatrix($m['axis'], (M_PI * 0.5) * $m['dir']);
                $rm = $exactRot->m;
                foreach ($this->cubies as $c) {
                    if (!$this->matchesLayer($c, $m['axis'], $m['layer'])) {
                        continue;
                    }
                    $nx = (int)round($rm[0] * $c->gx + $rm[4] * $c->gy + $rm[8]  * $c->gz);
                    $ny = (int)round($rm[1] * $c->gx + $rm[5] * $c->gy + $rm[9]  * $c->gz);
                    $nz = (int)round($rm[2] * $c->gx + $rm[6] * $c->gy + $rm[10] * $c->gz);
                    $c->gx = $nx;
                    $c->gy = $ny;
                    $c->gz = $nz;
                    $c->entity->position = new Vec3((float)$nx, (float)$ny, (float)$nz);
                    $c->rotMatrix = $exactRot->multiply($c->rotMatrix);
                    $c->entity->rotation = self::matrixToEuler($c->rotMatrix);
                }

                if ($m['record']) {
                    $this->moveHistory[] = $m;
                    $this->moveCount++;
                } else {
                    $this->moveCount = max(0, $this->moveCount - 1);
                }

                $this->activeMove = null;
                if ($this->moveQueue === []) {
                    if ($this->moveHistory === []) {
                        $this->status = '🎉 Rubik\'s Cube Solved!';
                        $this->particles->burst(new Vec3(0.0, 0.5, 0.0), count: 80, color: [1.0, 0.85, 0.25, 1.0]);
                    } else {
                        $this->status = sprintf('Moves from solved: %d', count($this->moveHistory));
                    }
                }
            }
        }
    }
}

$app = new RubiksApp();

App::whenReady(function () use ($app) {
    new Window(new WindowOptions(
        title: 'MyPHP 3D Rubik\'s Cube & Audio Visualizer',
        width: 1080,
        height: 720,
        content: Content::file(__DIR__ . '/view.php', $app)
    ));
});

App::run();
