<?php
// The window's 3D + 2D declarative view, $this the SceneState app.php makes.
// Edit and save under `myphp dev` or `myphp run`: it hot reloads!
declare(strict_types=1);

return fn() => VStack(spacing: 0)(
    HStack(spacing: 12)(
        Text('3D Scene · Taps: {n}', n: $this->taps)->font(Font::headline),
        Spacer(),
        Toggle('Auto-Rotate', isOn: $this->autoRotate),
        Button('Spark Burst', action: $this->burst(...))->buttonStyle(ButtonStyle::borderedProminent),
    )->padding(12),
    Divider(),
    SceneView(
        $this->scene,
        orbitControls: true,
        onUpdate: $this->update(...),
        onPick: function (?array $hit): void {
            if ($hit !== null) {
                $this->burst();
            }
        },
    ),
);