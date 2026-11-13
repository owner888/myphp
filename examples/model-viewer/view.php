<?php
declare(strict_types=1);

use MyPHP\UI\Painter;

$presets = ['Torus', 'Capsule', 'Cylinder', 'Cone', 'Sphere', 'Cube', 'Crystal'];

return fn() => HStack(spacing: 0)(
    // Left Inspector Sidebar
    VStack(alignment: HorizontalAlignment::leading, spacing: 14)(
        Text('3D Model Studio')->font(Font::title3)->bold(),
        Text(verbatim: $this->statusMessage)->font(Font::caption)->foregroundStyle(Color::secondary),

        Divider(),

        Text('Preset Geometry')->font(Font::subheadline)->bold(),
        FlowLayout(spacing: 6)(
            Each($presets, id: fn(string $p) => $p)(fn(string $p) =>
                Button($p, action: fn() => $this->selectPreset($p))
                    ->buttonStyle($this->preset === $p ? ButtonStyle::borderedProminent : ButtonStyle::bordered)
            ),
        ),

        Divider(),

        Text('Viewport & Environment')->font(Font::subheadline)->bold(),
        Toggle('Auto-Rotate Model', isOn: $this->autoRotate),
        Toggle('3D Reference Grid & XYZ Gizmo', isOn: $this->showGrid),
        Toggle('Shadow-Receiving Floor', isOn: $this->showFloor),
        Toggle('1024×1024 PCF Soft Shadows', isOn: $this->shadows),
        Toggle('Exponential Distance Fog', isOn: $this->fog),

        Divider(),

        Text('Lighting & Material')->font(Font::subheadline)->bold(),
        VStack(alignment: HorizontalAlignment::leading, spacing: 4)(
            Text('Sun Direction Angle')->font(Font::caption),
            Slider(value: $this->lightAngle, min: 0.0, max: 6.28),
        ),
        VStack(alignment: HorizontalAlignment::leading, spacing: 4)(
            Text('Point Light Intensity')->font(Font::caption),
            Slider(value: $this->pointIntensity, min: 0.0, max: 5.0),
        ),
        VStack(alignment: HorizontalAlignment::leading, spacing: 4)(
            Text('Specular Shininess')->font(Font::caption),
            Slider(value: $this->shininess, min: 4.0, max: 128.0),
        ),

        Divider(),

        HStack(spacing: 8)(
            Button('Spark Burst', action: fn() => $this->burstParticles())->buttonStyle(ButtonStyle::borderedProminent),
            Button('Export PNG', action: fn() => $this->exportSnapshot())->buttonStyle(ButtonStyle::bordered),
        ),

        Spacer(),

        VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
            Text('Vertices: {v}  ·  Triangles: {t}', v: $this->vertexCount, t: $this->triangleCount)
                ->font(Font::caption)->foregroundStyle(Color::secondary),
            Text('Tip: Drag to orbit, scroll to zoom, click model to spark')
                ->font(Font::caption2)->foregroundStyle(Color::secondary),
        ),
    )->padding(16)->frame(width: 290),

    Divider(),

    // Right 3D Hardware Viewport
    SceneView(
        $this->scene,
        orbitControls: true,
        onUpdate: $this->updateScene(...),
        onPick: function (?array $hit): void {
            if ($hit !== null) {
                $this->burstParticles();
            }
        },
        overlay: function (Painter $p, array $r): void {
            $p->fill([$r[0] + 14, $r[1] + 14, 295, 52], 0x000000, 8.0, 0.6);
            $p->text($r[0] + 24, $r[1] + 24, "Active Mesh: {$this->modelEntity->name}", 13, 0xffffff);
            $p->text($r[0] + 24, $r[1] + 44, sprintf('%d verts · %d tris · Drop .obj/.glb', $this->vertexCount, $this->triangleCount), 11, 0x80cbc4);
        },
    ),
);
