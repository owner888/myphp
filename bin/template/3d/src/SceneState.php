<?php
// The app's 3D state and actions: view.php renders it via SceneView() and calls its methods.
declare(strict_types=1);

use MyPHP\Plugins\Scene\{Camera3D, Entity, Material, Mesh, ParticleEmitter3D, PointLight, Scene, Tween3D};
use MyPHP\Plugins\Scene\Math\Vec3;

final class SceneState
{
    public Scene $scene;
    public Entity $hero;
    public ParticleEmitter3D $sparks;
    public bool $autoRotate = true;
    public int $taps = 0;

    public function __construct()
    {
        $this->scene = new Scene(new Camera3D(position: new Vec3(2.2, 2.0, 4.6)));
        $this->scene->shadowsEnabled = true;
        $this->scene->fogEnabled = true;
        $this->scene->addSkyDome();

        $floor = new Entity(Mesh::plane(14.0, 14.0), new Material([0.16, 0.18, 0.22, 1.0]), position: new Vec3(0.0, -1.2, 0.0), name: 'Floor');
        $floor->pickable = false;
        $floor->castShadow = false;
        $this->scene->add($floor);
        $this->scene->addGridHelper(size: 12.0, divisions: 12, y: -1.18, showYAxis: false);

        $this->scene->addPointLight(new PointLight(new Vec3(2.0, 2.0, 2.0), [0.3, 0.85, 1.0], intensity: 2.2, radius: 9.0));
        $this->sparks = $this->scene->addEmitter(new ParticleEmitter3D(Mesh::cube(1.0), maxParticles: 128));

        $this->hero = new Entity(
            Mesh::torus(0.95, 0.35, 32, 18),
            Material::pbr([0.35, 0.78, 1.0, 1.0], metallic: 0.35, roughness: 0.25, rimStrength: 0.7),
            name: 'HeroTorus'
        );
        $this->scene->add($this->hero);
    }

    public function update(float $dt): void
    {
        if ($this->autoRotate) {
            $this->hero->rotation->y += $dt * 0.9;
            $this->hero->rotation->x = sin(hrtime(true) / 1e9 * 1.4) * 0.25;
        }
    }

    public function burst(): void
    {
        $this->taps++;
        $this->sparks->burst(new Vec3(0.0, 0.2, 0.0), count: 40, color: [1.0, 0.85, 0.3, 1.0]);
        $this->scene->addTween(
            (new Tween3D($this->hero, duration: 0.2, easing: Tween3D::EASE_OUT_CUBIC))
                ->toScale(new Vec3(1.25, 1.25, 1.25), new Vec3(1.0, 1.0, 1.0))
                ->yoyo(true)
        );
    }
}