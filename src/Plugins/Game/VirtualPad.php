<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Game;

use MyPHP\UI\{Key, Painter};

/**
 * On-screen virtual handheld controller (D-Pad / Analog Stick + A, B, Start, Select buttons)
 * inspired by Flutter Flame's JoystickComponent & HudButtonComponent.
 *
 * Reads pointer/touch events from Game\Input, injects synthetic Key presses
 * (UP/DOWN/LEFT/RIGHT, SPACE/RETURN, ESCAPE/X, TAB) or reports normalized stick axes,
 * and draws a translucent handheld overlay via Painter.
 *
 *     $this->pad->step($in); // at the start of Game::update(Input $in)
 *     // ... existing keyboard logic works unmodified!
 *     $this->pad->draw($p, $r); // at the end of Game::draw(Painter $p, array $r, float $alpha)
 */
final class VirtualPad
{
    /** Normalized analog stick / D-pad vector in [-1.0, 1.0]. */
    public float $axisX = 0.0;
    public float $axisY = 0.0;

    /** Whether each virtual button is currently held down. */
    public bool $up = false;
    public bool $down = false;
    public bool $left = false;
    public bool $right = false;
    public bool $buttonA = false;
    public bool $buttonB = false;
    public bool $buttonStart = false;

    private bool $prevUp = false;
    private bool $prevDown = false;
    private bool $prevLeft = false;
    private bool $prevRight = false;
    private bool $prevA = false;
    private bool $prevB = false;
    private bool $prevStart = false;

    public function __construct(
        public bool $enabled = true,
        public bool $injectKeys = true,
        public float $dpadRadius = 56.0,
        public float $buttonRadius = 28.0,
        public string $labelA = 'A',
        public string $labelB = 'B',
        public string $labelStart = 'MENU',
    ) {}

    /**
     * Evaluates touch/pointer state in $in, updates virtual axes/buttons,
     * and optionally injects synthetic key events into $in so existing desktop
     * keyboard game logic works on mobile touchscreens with zero changes.
     */
    public function step(Input $in): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->prevUp = $this->up;
        $this->prevDown = $this->down;
        $this->prevLeft = $this->left;
        $this->prevRight = $this->right;
        $this->prevA = $this->buttonA;
        $this->prevB = $this->buttonB;
        $this->prevStart = $this->buttonStart;

        $this->up = $this->down = $this->left = $this->right = false;
        $this->buttonA = $this->buttonB = $this->buttonStart = false;
        $this->axisX = $this->axisY = 0.0;

        [$w, $h] = $in->size();
        if ($w <= 0 || $h <= 0 || !$in->pointerDown(0)) {
            if ($this->injectKeys) {
                $this->syncKeys($in);
            }
            return;
        }

        [$px, $py] = $in->pointer();
        [$dpadCx, $dpadCy, $ax, $ay, $bx, $by, $sx, $sy, $sw, $sh] = $this->layout($w, $h);

        // 1. Check D-Pad / Joystick region (bottom-left)
        $dx = $px - $dpadCx;
        $dy = $py - $dpadCy;
        $dist = hypot($dx, $dy);
        if ($dist <= $this->dpadRadius * 1.45 && $dist >= 10.0) {
            $norm = min(1.0, $dist / $this->dpadRadius);
            $this->axisX = ($dx / $dist) * $norm;
            $this->axisY = ($dy / $dist) * $norm;
            if (abs($dx) >= abs($dy) * 0.55) {
                if ($dx > 0) {
                    $this->right = true;
                } else {
                    $this->left = true;
                }
            }
            if (abs($dy) >= abs($dx) * 0.55) {
                if ($dy > 0) {
                    $this->down = true;
                } else {
                    $this->up = true;
                }
            }
        }

        // 2. Check A button (Confirm: Space / Return)
        if (hypot($px - $ax, $py - $ay) <= $this->buttonRadius * 1.25) {
            $this->buttonA = true;
        }

        // 3. Check B button (Cancel: Escape / X)
        if (hypot($px - $bx, $py - $by) <= $this->buttonRadius * 1.25) {
            $this->buttonB = true;
        }

        // 4. Check Start / Menu pill button
        if ($px >= $sx && $px <= $sx + $sw && $py >= $sy && $py <= $sy + $sh) {
            $this->buttonStart = true;
        }

        if ($this->injectKeys) {
            $this->syncKeys($in);
        }
    }

    private function syncKeys(Input $in): void
    {
        $this->applyKey($in, Key::UP, $this->up, $this->prevUp);
        $this->applyKey($in, Key::DOWN, $this->down, $this->prevDown);
        $this->applyKey($in, Key::LEFT, $this->left, $this->prevLeft);
        $this->applyKey($in, Key::RIGHT, $this->right, $this->prevRight);
        $this->applyKey($in, Key::SPACE, $this->buttonA, $this->prevA);
        $this->applyKey($in, Key::ESCAPE, $this->buttonB, $this->prevB);
        $this->applyKey($in, Key::TAB, $this->buttonStart, $this->prevStart);
    }

    private function applyKey(Input $in, int $key, bool $now, bool $prev): void
    {
        if ($now) {
            if (!$prev && !isset($in->held[$key])) {
                $in->pressed[$key] = true;
            }
            $in->held[$key] = true;
        } elseif ($prev) {
            $in->released[$key] = true;
            unset($in->held[$key]);
        }
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float, 7: float, 8: float, 9: float}
     */
    private function layout(float $w, float $h): array
    {
        $r = $this->dpadRadius;
        $br = $this->buttonRadius;
        $dpadCx = 28.0 + $r;
        $dpadCy = $h - 32.0 - $r;

        $ax = $w - 28.0 - $br;
        $ay = $h - 48.0 - $br * 2.1;

        $bx = $ax - $br * 2.25;
        $by = $h - 32.0 - $br;

        $sw = 68.0;
        $sh = 28.0;
        $sx = $w - 28.0 - $sw;
        $sy = 20.0;

        return [$dpadCx, $dpadCy, $ax, $ay, $bx, $by, $sx, $sy, $sw, $sh];
    }

    /**
     * Draws the translucent virtual controller HUD over the game viewport $r.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $r
     */
    public function draw(Painter $p, array $r): void
    {
        if (!$this->enabled) {
            return;
        }
        [$ox, $oy, $w, $h] = $r;
        [$dpadCx, $dpadCy, $ax, $ay, $bx, $by, $sx, $sy, $sw, $sh] = $this->layout($w, $h);
        $rad = $this->dpadRadius;
        $br = $this->buttonRadius;

        $baseBg = 0x0f172a;
        $activeBg = 0x38bdf8;
        $borderCol = 0xffffff;
        $textCol = 0xffffff;

        // 1. D-Pad outer ring & cross arms
        $p->fill([$ox + $dpadCx - $rad, $oy + $dpadCy - $rad, $rad * 2, $rad * 2], $baseBg, $rad, 0.45);
        $p->stroke([$ox + $dpadCx - $rad, $oy + $dpadCy - $rad, $rad * 2, $rad * 2], $borderCol, $rad, 1.5, 0.35);

        $arm = $rad * 0.56;
        if ($this->up) {
            $p->fill([$ox + $dpadCx - $arm * 0.5, $oy + $dpadCy - $rad + 4, $arm, $arm], $activeBg, 8.0, 0.78);
        }
        if ($this->down) {
            $p->fill([$ox + $dpadCx - $arm * 0.5, $oy + $dpadCy + $rad - $arm - 4, $arm, $arm], $activeBg, 8.0, 0.78);
        }
        if ($this->left) {
            $p->fill([$ox + $dpadCx - $rad + 4, $oy + $dpadCy - $arm * 0.5, $arm, $arm], $activeBg, 8.0, 0.78);
        }
        if ($this->right) {
            $p->fill([$ox + $dpadCx + $rad - $arm - 4, $oy + $dpadCy - $arm * 0.5, $arm, $arm], $activeBg, 8.0, 0.78);
        }

        // Thumb knob
        $knobR = $rad * 0.36;
        $kx = $ox + $dpadCx + $this->axisX * ($rad - $knobR - 4);
        $ky = $oy + $dpadCy + $this->axisY * ($rad - $knobR - 4);
        $p->fill([$kx - $knobR, $ky - $knobR, $knobR * 2, $knobR * 2], 0xffffff, $knobR, 0.55);

        // 2. A Button
        $p->fill([$ox + $ax - $br, $oy + $ay - $br, $br * 2, $br * 2], $this->buttonA ? 0x34d399 : $baseBg, $br, $this->buttonA ? 0.85 : 0.45);
        $p->stroke([$ox + $ax - $br, $oy + $ay - $br, $br * 2, $br * 2], $borderCol, $br, 1.5, 0.35);
        $p->text($ox + $ax - 5, $oy + $ay - 8, $this->labelA, 15.0, $textCol, 0.9);

        // 3. B Button
        $p->fill([$ox + $bx - $br, $oy + $by - $br, $br * 2, $br * 2], $this->buttonB ? 0xfb7185 : $baseBg, $br, $this->buttonB ? 0.85 : 0.45);
        $p->stroke([$ox + $bx - $br, $oy + $by - $br, $br * 2, $br * 2], $borderCol, $br, 1.5, 0.35);
        $p->text($ox + $bx - 5, $oy + $by - 8, $this->labelB, 15.0, $textCol, 0.9);

        // 4. Start / Menu Pill Button
        if ($this->labelStart !== '') {
            $p->fill([$ox + $sx, $oy + $sy, $sw, $sh], $this->buttonStart ? $activeBg : $baseBg, $sh * 0.5, $this->buttonStart ? 0.85 : 0.45);
            $p->stroke([$ox + $sx, $oy + $sy, $sw, $sh], $borderCol, $sh * 0.5, 1.2, 0.35);
            $p->text($ox + $sx + 15, $oy + $sy + 7, $this->labelStart, 12.0, $textCol, 0.9);
        }
    }
}