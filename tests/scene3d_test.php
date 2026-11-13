<?php
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Plugins\Scene\{Camera3D, Collider3D, Entity, Material, Mesh, ParticleEmitter3D, PointLight, Scene, Tween3D};
use MyPHP\Plugins\Scene\Math\{Frustum, Mat4, Ray, Vec3};
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

// 4. Scene, Entity, Raycasting & Instancing
$scene = new Scene();
$entity = new Entity($cube, name: 'TestCube');
$entity->position = new Vec3(0.0, 0.0, -5.0);
$entity->rotation = new Vec3(0.1, 0.2, 0.3);
$scene->add($entity);
check(count($scene->entities) === 1, 'Scene entities count');
check($entity->modelMatrix() instanceof Mat4, 'Entity cached model matrix');

// 4b. Ray & Screen-to-Ray Picking tests
$ray = new Ray(new Vec3(0.0, 0.0, 3.0), new Vec3(0.0, 0.0, -1.0));
$sphereHit = $ray->intersectSphere(new Vec3(0.0, 0.0, -5.0), 1.0);
check($sphereHit !== null && abs($sphereHit - 7.0) < 1e-4, 'Ray::intersectSphere hits at distance 7.0');
$aabbHit = $ray->intersectAABB(new Vec3(-1.0, -1.0, -6.0), new Vec3(1.0, 1.0, -4.0));
check($aabbHit !== null && abs($aabbHit - 7.0) < 1e-4, 'Ray::intersectAABB hits at distance 7.0');

$picked = $scene->pick(64.0, 64.0, 128.0, 128.0);
check($picked !== null && $picked['entity'] === $entity, 'Scene::pick hits center 3D entity');
$missed = $scene->pick(2.0, 2.0, 128.0, 128.0);
check($missed === null, 'Scene::pick misses at far corner');

// 4c. Hardware Instancing data packing
$instEntity = new Entity(Mesh::cube(1.0));
$instEntity->setInstances([
    Mat4::translation(-1.0, 0.0, -5.0),
    Mat4::translation(1.0, 0.0, -5.0),
], [
    [1.0, 0.0, 0.0, 1.0],
    [0.0, 0.0, 1.0, 1.0],
]);
check($instEntity->instanceCount === 2, 'Entity::setInstances sets instanceCount = 2');
check(strlen($instEntity->instanceData) === 2 * Entity::INSTANCE_STRIDE, 'Entity::setInstances packs 80 bytes per instance');

// 4d. Scene Graph Parent-Child Hierarchy & Frustum Culling tests
$parentEnt = new Entity(Mesh::cube(1.0), position: new Vec3(10.0, 0.0, 0.0));
$childEnt  = new Entity(Mesh::cube(0.5), position: new Vec3(0.0, 5.0, 0.0));
$parentEnt->addChild($childEnt);
$childWorldPos = $childEnt->worldPosition();
check(abs($childWorldPos->x - 10.0) < 1e-4 && abs($childWorldPos->y - 5.0) < 1e-4, 'Scene Graph child inherits parent world transform');

$cullScene = new Scene(new Camera3D(position: new Vec3(0.0, 0.0, 5.0), target: new Vec3(0.0, 0.0, 0.0)));
$inFront = new Entity(Mesh::cube(1.0), position: new Vec3(0.0, 0.0, 0.0));
$behindCam = new Entity(Mesh::cube(1.0), position: new Vec3(0.0, 0.0, 25.0)); // far behind camera
$cullScene->add($inFront);
$cullScene->add($behindCam);
$vpMat = $cullScene->camera->projectionMatrix(1.0)->multiply($cullScene->camera->viewMatrix());
$visibleList = $cullScene->visibleEntities($vpMat);
check(count($visibleList) === 1 && $visibleList[0] === $inFront && $cullScene->lastCulledCount === 1, 'Frustum culling culls entity behind camera');

// 4e. Point Lights & Shadow Map extension packing
$cullScene->shadowsEnabled = true;
$cullScene->fogEnabled = true;
$cullScene->fogDensity = 0.05;
$cullScene->addPointLight(new PointLight(new Vec3(1.0, 2.0, 3.0), [1.0, 0.5, 0.2], intensity: 2.0, radius: 8.0));
$extBin = $cullScene->packLightingExtension();
check(strlen($extBin) === 144, 'Scene::packLightingExtension packs 144-byte shadow, point light & fog block');

// 4f. Tween3D, ParticleEmitter3D, Billboard, and sceneFromGlb tests
$tweenEnt = new Entity(Mesh::cube(1.0), position: new Vec3(0.0, 0.0, 0.0));
$tweenEnt->billboard = true;
$cullScene->add($tweenEnt);
$cullScene->addTween((new Tween3D($tweenEnt, duration: 1.0, easing: Tween3D::LINEAR))->toPosition(new Vec3(10.0, 0.0, 0.0)));
$emitter = $cullScene->addEmitter(new ParticleEmitter3D(Mesh::cube(0.5), maxParticles: 64));
$emitter->burst(new Vec3(0.0, 1.0, 0.0), count: 16, lifeMin: 1.0, lifeMax: 2.0);
check($emitter->activeCount() === 16, 'ParticleEmitter3D::burst spawns 16 active 3D particles');
$cullScene->step(0.5);
check(abs($tweenEnt->position->x - 5.0) < 1e-4, 'Tween3D interpolates Entity position over time');
check($emitter->entity->instanceCount === 16, 'ParticleEmitter3D::step packs live particles into GPU instance buffer');
check(abs($tweenEnt->rotation->y - atan2(-5.0, 5.0)) < 1e-4, 'Entity::$billboard rotates entity toward camera during Scene::step');

$glbEntity = Mesh::sceneFromGlb($glbBytes);
check($glbEntity instanceof Entity && $glbEntity->mesh->vertexCount === 3, 'Mesh::sceneFromGlb parses GLB into Scene Graph Entity');

// 4g. Extended Procedural Primitives (cylinder, cone, torus, capsule), Collider3D & GridHelper tests
$cyl = Mesh::cylinder(0.8, 0.8, 2.0, 16);
$cone = Mesh::cone(0.9, 2.0, 16);
$torus = Mesh::torus(1.0, 0.35, 16, 12);
$capsule = Mesh::capsule(0.5, 1.0, 16, 12);
check($cyl->vertexCount > 30 && $cyl->indexCount > 90, 'Mesh::cylinder generates valid geometry');
check($cone->vertexCount > 15 && $cone->indexCount > 45, 'Mesh::cone generates valid geometry');
check($torus->vertexCount === 17 * 13 && $torus->indexCount === 16 * 12 * 6, 'Mesh::torus generates valid geometry');
check($capsule->vertexCount > 100 && $capsule->indexCount > 300, 'Mesh::capsule generates valid geometry');

$physScene = new Scene();
$gridEnt = $physScene->addGridHelper(size: 10.0, divisions: 10);
check($gridEnt->instanceCount === 23, 'Scene::addGridHelper creates 23 hardware-instanced grid & XYZ axis lines');

$collided = false;
$wall = new Entity(Mesh::cube(2.0), position: new Vec3(0.0, 0.0, 0.0));
$wall->collider = Collider3D::box(new Vec3(1.0, 1.0, 1.0), isStatic: true);
$ball = new Entity(Mesh::sphere(0.5), position: new Vec3(1.2, 0.0, 0.0)); // penetrating wall by 0.3m along +X
$ball->collider = Collider3D::sphere(0.5, onCollide: static function () use (&$collided): void {
    $collided = true;
});
$physScene->add($wall);
$physScene->add($ball);
$physScene->step(0.016);
check($collided && abs($ball->position->x - 1.5) < 1e-4, 'Collider3D resolves Sphere-vs-Box penetration and fires onCollide');

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

    // Hardware Instanced 3D rendering verification
    $instScene = new Scene();
    $instCenter = new Entity(Mesh::cube(2.0));
    $instCenter->setInstances([Mat4::translation(0.0, 0.0, -4.0)], [[0.0, 0.0, 1.0, 1.0]]);
    $instScene->add($instCenter);
    $instImg = $instScene->renderOffscreen(64, 64);
    $iBytes = unpack('C4', substr($instImg->pixels(), $tOffset, 4));
    check($iBytes[3] > 50 && $iBytes[1] < 20, 'Hardware GPU Instancing renders blue instanced mesh');

    // Shadow Mapping + Point Light rendering verification
    $shadowScene = new Scene();
    $shadowScene->shadowsEnabled = true;
    $shadowScene->addPointLight(new PointLight(new Vec3(0.0, 1.0, -2.0), [1.0, 0.1, 0.1], intensity: 4.0, radius: 10.0));
    $shadowScene->add(new Entity(Mesh::cube(2.0), position: new Vec3(0.0, 0.0, -4.0)));
    $sImg = $shadowScene->renderOffscreen(64, 64);
    $sBytes = unpack('C4', substr($sImg->pixels(), $tOffset, 4));
    check($sBytes[1] > $sBytes[3], 'Point light & shadow pass illuminates 3D surface with red point light');
}
check(str_contains(\MyPHP\Plugins\Scene\Native\D3dRenderPass::SHADER_HLSL, 'vs_scene'), 'D3dRenderPass HLSL shader defined');
check(str_contains(\MyPHP\Plugins\Scene\Native\GlRenderPass::VERTEX_GLSL, 'iModelC0'), 'GlRenderPass GLSL vertex shader supports hardware instancing');
check(str_contains(\MyPHP\Plugins\Scene\Native\GlRenderPass::FRAGMENT_GLSL, 'uTex'), 'GlRenderPass GLSL fragment shader supports texture mapping');

// 7. Full headless UI build & paint pass with Scene::view & SceneView()
\MyPHP\UI\CanvasAtlas::take(); // flush any pending canvases left by earlier tests
$sceneForView = new Scene();
$sceneForView->add(new Entity(Mesh::cube(1.5), name: 'ViewCube'));
$updated = false;
$h = new Headless(function (\MyPHP\UI\Context $c) use ($sceneForView, &$updated): void {
    Scene::view(
        $c,
        $sceneForView,
        orbitControls: true,
        onUpdate: static function (float $dt) use (&$updated): void {
            $updated = $dt > 0.0;
        },
        overlay: static function (\MyPHP\UI\Painter $p, array $r): void {
            $p->fill([$r[0] + 10, $r[1] + 10, 100, 40], 0x000000, 8.0, 0.5);
            $p->text($r[0] + 15, $r[1] + 15, 'MyPHP 3D', 14, 0xffffff);
        }
    )->grow = 1;
}, 400, 300);
$h->paint();
check($updated, 'Scene::view invokes onUpdate callback during paint');
$taken = \MyPHP\UI\CanvasAtlas::take();
check(count($taken) > 0 && $taken[0]->scene === $sceneForView, 'CanvasAtlas::take() returns Scene::view 3D canvas for GPU rendering');
check(function_exists('SceneView'), 'Declarative SceneView() helper is registered');


