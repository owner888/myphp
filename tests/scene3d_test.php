<?php
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Plugins\Scene\{Camera3D, Entity, Material, Mesh, Scene};
use MyPHP\Plugins\Scene\Math\{Mat4, Vec3};
use MyPHP\UI\Canvas;

// 1. Vec3 tests
$v1 = new Vec3(1.0, 2.0, 3.0);
$v2 = new Vec3(4.0, 5.0, 6.0);
check($v1->add($v2)->toArray() === [5.0, 7.0, 9.0], 'Vec3 add');
check($v2->sub($v1)->toArray() === [3.0, 3.0, 3.0], 'Vec3 sub');
check($v1->dot($v2) === 32.0, 'Vec3 dot product');
$cross = $v1->cross($v2);
check($cross->x === -3.0 && $cross->y === 6.0 && $cross->z === -3.0, 'Vec3 cross product');

// 2. Mat4 tests
$ident = Mat4::identity();
check(count($ident->m) === 16 && $ident->m[0] === 1.0 && $ident->m[15] === 1.0, 'Mat4 identity');
$t = Mat4::translation(10.0, 20.0, 30.0);
check($t->m[12] === 10.0 && $t->m[13] === 20.0 && $t->m[14] === 30.0, 'Mat4 translation');

$persp = Mat4::perspective(deg2rad(60), 16.0 / 9.0, 0.1, 100.0);
check(strlen($persp->toBinary()) === 64, 'Mat4 64-byte binary');

// 3. Mesh tests
$cube = Mesh::cube(2.0, [1.0, 0.0, 0.0, 1.0]);
check($cube->vertexCount === 24, 'Cube mesh 24 vertices');
check($cube->indexCount === 36, 'Cube mesh 36 indices');
check(strlen($cube->vertexData) === 24 * 48, 'Cube vertex data 48-byte aligned');
check(strlen($cube->indexData) === 36 * 4, 'Cube index data 4-byte uint32');

$sphere = Mesh::sphere(1.0, 12, 8);
check($sphere->vertexCount === (8 + 1) * (12 + 1), 'Sphere vertices count');
check($sphere->indexCount === 8 * 12 * 6, 'Sphere indices count');

// 4. Scene & Entity
$scene = new Scene();
$entity = new Entity($cube);
$entity->position = new Vec3(0.0, 0.0, -5.0);
$entity->rotation = new Vec3(0.1, 0.2, 0.3);
$scene->add($entity);
check(count($scene->entities) === 1, 'Scene entities count');
check($entity->modelMatrix() instanceof Mat4, 'Entity cached model matrix');

// 5. Metal Off-screen RenderPass (macOS arm64 only)
if (PHP_OS_FAMILY === 'Darwin' && php_uname('m') === 'arm64') {
    $img = $scene->renderOffscreen(128, 128);
    check($img->w === 128 && $img->h === 128, 'Scene::renderOffscreen image size');
    check($img->address() !== 0, 'Scene::renderOffscreen has rendered pixels');

    // Sample center pixel to verify 3D rasterization produced pixels
    $offset = (64 * 128 + 64) * 4;
    $bytes = unpack('C4', substr($img->pixels(), $offset, 4));
    check($bytes[4] === 255, 'Alpha channel is 255');
    check($bytes[1] > 0 || $bytes[2] > 0 || $bytes[3] > 0, 'Center pixel has non-zero color');
}

// 7. Full headless UI build & paint pass with examples/scene3d
$app = new class {
    public Scene $scene;
    public Canvas $canvas;
    public function __construct() {
        $this->canvas = new Canvas(200, 150);
        $this->scene = new Scene();
        $this->scene->add(new Entity(Mesh::cube(1.5)));
    }
};
$h = new Headless(function (\MyPHP\UI\Context $c) use ($app): void {
    $e = $c->column();
    $c->end();
    $e->grow = 1;
    $e->draw(function (\MyPHP\UI\Painter $p, array $r) use ($app): void {
        $p->animationFrame();
        $app->canvas->renderScene($app->scene);
        $p->image($app->canvas->bitmap(), $r, \MyPHP\UI\Node::FILL_BOX);
        $p->fill([$r[0] + 10, $r[1] + 10, 100, 40], 0x000000, 8.0, 0.5);
        $p->text($r[0] + 15, $r[1] + 15, 'MyPHP 3D', 14, 0xffffff);
    });
}, 400, 300);
$h->paint();
check($app->canvas->drawn > 0, 'Headless frame rendered 3D scene canvas and painted UI overlay');

