<?php
declare(strict_types=1);

use MyPHP\Device;
use MyPHP\UI\Painter;

$moves = [
    ['U (Top)',    'y',  1, -1],
    ['D (Bottom)', 'y', -1,  1],
    ['R (Right)',  'x',  1, -1],
    ['L (Left)',   'x', -1,  1],
    ['F (Front)',  'z',  1, -1],
    ['B (Back)',   'z', -1,  1],
];

$controls = fn() => ScrollView(Axis::vertical)(
    VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
        Text('3D Rubik\'s Cube')->font(Font::title3)->bold(),
        Text(verbatim: $this->status)->font(Font::caption)->foregroundStyle(Color::secondary),

        Divider(),

        HStack(spacing: 8)(
            Button('Scramble', action: fn() => $this->scramble(12))->buttonStyle(ButtonStyle::borderedProminent),
            Button('Auto-Solve', action: fn() => $this->autoSolve())->buttonStyle(ButtonStyle::borderedProminent),
            Button('Reset', action: fn() => $this->resetCube())->buttonStyle(ButtonStyle::bordered),
        ),

        Divider(),

        Text('Twist Faces (90° CW / CCW)')->font(Font::subheadline)->bold(),
        FlowLayout(spacing: 6)(
            Each($moves, id: fn(array $m) => $m[0])(fn(array $m) =>
                HStack(spacing: 4)(
                    Button($m[0], action: fn() => $this->queueMove($m[1], $m[2], $m[3]))
                        ->buttonStyle(ButtonStyle::bordered),
                    Button($m[0][0] . "'", action: fn() => $this->queueMove($m[1], $m[2], -$m[3]))
                        ->buttonStyle(ButtonStyle::bordered),
                )
            ),
        ),

        Divider(),

        Text('Environment & Audio')->font(Font::subheadline)->bold(),
        Toggle('64-Bar 3D Audio Spectrum Ring', isOn: $this->spectrumEnabled),
        Toggle('Synthesized Twist Sound Effects', isOn: $this->soundEnabled),
        Toggle('1024×1024 PCF Soft Shadows', isOn: $this->scene->shadowsEnabled),
        Toggle('Exponential Distance Fog', isOn: $this->scene->fogEnabled),

        VStack(alignment: HorizontalAlignment::leading, spacing: 4)(
            Text('Twist Animation Speed')->font(Font::caption),
            Slider(value: $this->moveSpeed, in: [2.0, 14.0]),
        ),

        Divider(),

        Text('Tip: Click/tap any face on the 3D cube to twist it directly, or drag to orbit camera!')
            ->font(Font::caption2)->foregroundStyle(Color::secondary),
    )->padding(14),
);

$viewport = fn() => SceneView(
    $this->scene,
    orbitControls: true,
    onUpdate: $this->update(...),
    onPick: $this->onPick(...),
    overlay: function (Painter $p, array $r): void {
        $p->fill([$r[0] + 14, $r[1] + 14, 340, 54], 0x000000, 8.0, 0.65);
        $p->text($r[0] + 24, $r[1] + 24, 'MyPHP 3D Rubik\'s Cube + Audio Spectrum', 13, 0xffffff);
        $p->text($r[0] + 24, $r[1] + 44, sprintf('27 Cubies · 64 Instanced Spectrum Bars · Queue: %d', count($this->moveQueue)), 11, 0x4dd0e1);
    },
);

return fn() => Device::isDesktop()
    ? HStack(spacing: 0)(
        $controls()->frame(width: 300),
        Divider(),
        $viewport(),
    )
    : VStack(spacing: 0)(
        $viewport()->frame(height: 360),
        Divider(),
        $controls(),
    );
