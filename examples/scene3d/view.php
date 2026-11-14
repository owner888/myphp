<?php
// The window's view of the 3D scene state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\UI\Painter;

return fn() => SceneView(
    $this->scene,
    orbitControls: true,
    onUpdate: $this->update(...),
    onPick: $this->onPick(...),
    overlay: function (Painter $p, array $r): void {
        $p->fill([$r[0] + 16, $r[1] + 16, 415, 102], 0x000000, 8.0, 0.68);
        $p->text($r[0] + 28, $r[1] + 28, 'MyPHP Native 3D Scene (Shadows + PointLight + SceneGraph)', 14, 0xffffff);
        $p->text($r[0] + 28, $r[1] + 50, sprintf('FPS: %.1f | PCF Shadows: ON | Culled: %d', $this->fps, $this->scene->lastCulledCount), 12, 0x00e676);
        $p->text($r[0] + 28, $r[1] + 68, 'Picked: ' . $this->selectedName, 12, 0xffd54f);
        $p->text($r[0] + 28, $r[1] + 88, 'Click object to pick | Drag to orbit | Wheel to zoom', 11, 0xaaaaaa);
    },
);
