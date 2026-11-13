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

$plane = Mesh::plane(10.0, 10.0, 4.0);
check($plane->vertexCount === 4 && $plane->indexCount === 6, 'Plane mesh vertices and indices');

// 3b. Wavefront OBJ Loader tests
$objSrc = <<<'OBJ'
v  0.0  1.0  0.0
v -1.0 -1.0  1.0
v  1.0 -1.0  1.0
vt 0.5 1.0
vt 0.0 0.0
vt 1.0 0.0
vn 0.0 0.0 1.0
f 1/1/1 2/2/1 3/3/1
OBJ;
$objMesh = Mesh::fromObj($objSrc);
check($objMesh->vertexCount === 3 && $objMesh->indexCount === 3, 'Mesh::fromObj parses v/vt/vn triangle');
check(strlen($objMesh->vertexData) === 3 * 48, 'Mesh::fromObj 48-byte stride');

// 3c. Binary glTF 2.0 (.glb) Loader tests
$binPositions = pack('f9', 0.0, 1.0, 0.0, -1.0, -1.0, 0.0, 1.0, -1.0, 0.0);
$binIndices   = pack('v3', 0, 1, 2) . "\x00\x00"; // 4-byte padded
$binPayload   = $binPositions . $binIndices;
$gltfJson = json_encode([
    'asset' => ['version' => '2.0'],
    'meshes' => [['primitives' => [['attributes' => ['POSITION' => 0], 'indices' => 1]]]],
    'accessors' => [
        ['bufferView' => 0, 'componentType' => 5126, 'count' => 3, 'type' => 'VEC3'],
        ['bufferView' => 1, 'componentType' => 5123, 'count' => 3, 'type' => 'SCALAR'],
    ],
    'bufferViews' => [
        ['buffer' => 0, 'byteOffset' => 0, 'byteLength' => 36],
        ['buffer' => 0, 'byteOffset' => 36, 'byteLength' => 6],
    ],
]);
while (strlen($gltfJson) % 4 !== 0) { $gltfJson .= ' '; }
$totalGlbLen = 12 + 8 + strlen($gltfJson) + 8 + strlen($binPayload);
$glbBytes = pack('V3', 0x46546C67, 2, $totalGlbLen)
    . pack('V2', strlen($gltfJson), 0x4E4F534A) . $gltfJson
    . pack('V2', strlen($binPayload), 0x004E4942) . $binPayload;

$glbMesh = Mesh::fromGlb($glbBytes);
check($glbMesh->vertexCount === 3 && $glbMesh->indexCount === 3, 'Mesh::fromGlb parses binary glTF 2.0 mesh');
check(strlen($glbMesh->vertexData) === 3 * 48, 'Mesh::fromGlb 48-byte stride');

// 4. Scene & Entity
$scene = new Scene();
$entity = new Entity($cube);
$entity->position = new Vec3(0.0, 0.0, -5.0);
$entity->rotation = new Vec3(0.1, 0.2, 0.3);
$scene->add($entity);
check(count($scene->entities) === 1, 'Scene entities count');
check($entity->modelMatrix() instanceof Mat4, 'Entity cached model matrix');

// 5. Native GPU Off-screen RenderPass (macOS Metal & Windows Direct3D 11)
if ((PHP_OS_FAMILY === 'Darwin' && php_uname('m') === 'arm64') || PHP_OS_FAMILY === 'Windows') {
    $img = $scene->renderOffscreen(128, 128);
    check($img->w === 128 && $img->h === 128, 'Scene::renderOffscreen image size');
    check($img->address() !== 0, 'Scene::renderOffscreen has rendered pixels');

    // Sample center pixel to verify 3D rasterization produced pixels
    $offset = (64 * 128 + 64) * 4;
    $bytes = unpack('C4', substr($img->pixels(), $offset, 4));
    check($bytes[4] === 255, 'Alpha channel is 255');
    check($bytes[1] > 0 || $bytes[2] > 0 || $bytes[3] > 0, 'Center pixel has non-zero color');

    // Textured 3D rendering verification (using white cube vertex color so green texture shows)
    $whiteCube = Mesh::cube(2.0, [1.0, 1.0, 1.0, 1.0]);
    $entity->mesh = $whiteCube;
    $texImg = \MyPHP\UI\Image::fromPixels(2, 2, str_repeat("\x00\xff\x00\xff", 4))->pixelated();
    $entity->material = Material::textured($texImg);
    $texRendered = $scene->renderOffscreen(64, 64);
    $tOffset = (32 * 64 + 32) * 4;
    $tBytes = unpack('C4', substr($texRendered->pixels(), $tOffset, 4));
    check($tBytes[2] > 50 && $tBytes[1] < 20, 'Textured 3D mesh samples green texture on GPU');
}
check(str_contains(\MyPHP\Plugins\Scene\Native\D3dRenderPass::SHADER_HLSL, 'vs_scene'), 'D3dRenderPass HLSL shader defined');

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
$taken = \MyPHP\UI\CanvasAtlas::take();
check(in_array($app->canvas, $taken, true), 'CanvasAtlas::take() returns 3D scene canvas for GPU rendering');


