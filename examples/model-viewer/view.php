<?php
declare(strict_types=1);

use MyPHP\Device;
use MyPHP\Plugins\Scene\Math\Vec3;
use MyPHP\UI\Painter;

$presets = ['Torus', 'Capsule', 'Cylinder', 'Cone', 'Sphere', 'Cube', 'Crystal', 'Terrain', 'Creature'];

$controls = fn() => ScrollView(Axis::vertical)(
    VStack(alignment: HorizontalAlignment::leading, spacing: 9)(
        HStack(spacing: 8)(
            Text('3D Model Studio')->font(Font::title3)->bold(),
            Spacer(),
            Button('Spark Burst', action: fn() => $this->burstParticles())->buttonStyle(ButtonStyle::borderedProminent),
        ),
        Text(verbatim: $this->statusMessage)->font(Font::caption)->foregroundStyle(Color::secondary),

        Divider(),

        Text('Preset Geometry')->font(Font::subheadline)->bold(),
        FlowLayout(spacing: 5)(
            Each($presets, id: fn(string $p) => $p)(fn(string $p) =>
                Button($p, action: fn() => $this->selectPreset($p))
                    ->buttonStyle($this->preset === $p ? ButtonStyle::borderedProminent : ButtonStyle::bordered)
            ),
        ),
        $this->animator !== null ? HStack(spacing: 6)(
            Button('Walk', action: fn() => $this->animator?->play(\MyPHP\Plugins\Scene\Animator3D::WALK))->buttonStyle(ButtonStyle::bordered),
            Button('Lunge', action: function (): void {
                $this->animator?->play(\MyPHP\Plugins\Scene\Animator3D::LUNGE, duration: 0.42, direction: new Vec3(0.0, 0.0, 1.0));
                $this->scene->camera->shake(0.16, 0.22);
            })->buttonStyle(ButtonStyle::borderedProminent),
            Button('Hurt', action: function (): void {
                $this->animator?->play(\MyPHP\Plugins\Scene\Animator3D::HURT, duration: 0.35);
                $this->scene->camera->shake(0.22, 0.25);
            })->buttonStyle(ButtonStyle::bordered),
        ) : null,

        Divider(),

        Text('Viewport & Environment')->font(Font::subheadline)->bold(),
        Toggle('Auto-Rotate Model', isOn: $this->autoRotate),
        Toggle('Hardware Wireframe Mode', isOn: $this->wireframe),
        Toggle('3D Reference Grid & XYZ Gizmo', isOn: $this->showGrid),
        Toggle('Atmospheric Sky Dome', isOn: $this->skyDome),
        Toggle('Shadow-Receiving Floor', isOn: $this->showFloor),
        Toggle('1024×1024 PCF Soft Shadows', isOn: $this->shadows),
        Toggle('Exponential Distance Fog', isOn: $this->fog),

        Divider(),

        Text('PBR Material & Lighting')->font(Font::subheadline)->bold(),
        VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
            Text('PBR Metallic')->font(Font::caption),
            Slider(value: $this->metallic, in: [0.0, 1.0]),
        ),
        VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
            Text('PBR Roughness')->font(Font::caption),
            Slider(value: $this->roughness, in: [0.05, 0.99]),
        ),
        VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
            Text('Fresnel Hologram Rim')->font(Font::caption),
            Slider(value: $this->rimStrength, in: [0.0, 2.5]),
        ),
        VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
            Text('Sun Direction Angle')->font(Font::caption),
            Slider(value: $this->lightAngle, in: [0.0, 6.28]),
        ),
        VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
            Text('Point Light Intensity')->font(Font::caption),
            Slider(value: $this->pointIntensity, in: [0.0, 5.0]),
        ),

        Divider(),

        HStack(spacing: 8)(
            Button('Export PNG', action: fn() => $this->exportSnapshot())->buttonStyle(ButtonStyle::bordered),
            Text('Vertices: {v} · Tris: {t}', v: $this->vertexCount, t: $this->triangleCount)
                ->font(Font::caption)->foregroundStyle(Color::secondary),
        ),
    )->padding(12),
);

$viewport = fn() => SceneView(
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
        $p->text($r[0] + 24, $r[1] + 44, sprintf('%d verts · %d tris · %s', $this->vertexCount, $this->triangleCount, $this->wireframe ? 'Wireframe' : 'Solid + Rim'), 11, 0x80cbc4);

        // Project 3D entity top anchor to 2D screen coordinates for floating 3D label!
        $topWorld = $this->modelEntity->worldPosition()->add(new Vec3(0.0, 1.35, 0.0));
        $pt = $this->scene->camera->worldToScreen($topWorld, $r[2], $r[3]);
        if ($pt !== null) {
            [$sx, $sy] = [$r[0] + $pt[0], $r[1] + $pt[1]];
            $p->fill([$sx - 52, $sy - 14, 104, 22], 0x0d1b2a, 11.0, 0.78);
            $p->text($sx - 42, $sy - 10, "● {$this->modelEntity->name}", 11, 0x4dd0e1);
        }
    },
);

return fn() => Device::isDesktop()
    ? HStack(spacing: 0)(
        $controls()->frame(width: 295),
        Divider(),
        $viewport(),
    )
    : VStack(spacing: 0)(
        $viewport()->frame(height: 360),
        Divider(),
        $controls(),
    );
