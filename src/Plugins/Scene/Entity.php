<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Scene;

use MyPHP\Native\ObjC;
use MyPHP\Plugins\Scene\Math\{Mat4, Vec3};
use RuntimeException;

/**
 * 3D Scene Entity with Mesh, Material, Transform, and optional GPU Instancing.
 * Caches model matrix and GPU instance buffers to avoid per-frame CPU overhead.
 */
final class Entity
{
    public const INSTANCE_STRIDE = 80; // 64 bytes Mat4 + 16 bytes RGBA color

    public Vec3 $position;
    public Vec3 $rotation; // Euler angles in radians (X, Y, Z)
    public Vec3 $scale;
    public Material $material;
    public Mesh $mesh;
    public bool $pickable = true;
    public string $name = '';

    private ?Mat4 $cachedModel = null;
    private float $lastPx = 0.0, $lastPy = 0.0, $lastPz = 0.0;
    private float $lastRx = 0.0, $lastRy = 0.0, $lastRz = 0.0;
    private float $lastSx = 1.0, $lastSy = 1.0, $lastSz = 1.0;

    /** Hardware instancing state */
    public int $instanceCount = 0;
    public string $instanceData = '';
    public mixed $nativeInstanceBuffer = null;
    public mixed $nativeInstanceSrv = null;
    private bool $instancesDirty = false;

    public function __construct(
        Mesh $mesh,
        ?Material $material = null,
        ?Vec3 $position = null,
        ?Vec3 $rotation = null,
        ?Vec3 $scale = null,
        string $name = ''
    ) {
        $this->mesh = $mesh;
        $this->material = $material ?? new Material();
        $this->position = $position ?? Vec3::zero();
        $this->rotation = $rotation ?? Vec3::zero();
        $this->scale = $scale ?? new Vec3(1.0, 1.0, 1.0);
        $this->name = $name;
    }

    /**
     * World-space bounding sphere radius for fast raycast picking.
     */
    public function worldBoundingRadius(): float
    {
        $maxScale = max(abs($this->scale->x), abs($this->scale->y), abs($this->scale->z));
        return $this->mesh->boundingRadius * $maxScale;
    }

    /**
     * Configures hardware GPU instancing for this entity.
     * Renders $transforms in a SINGLE GPU draw call!
     *
     * @param list<Mat4> $transforms Model matrix for each instance
     * @param list<array{0: float, 1: float, 2: float, 3: float}>|null $colors Optional per-instance RGBA color
     */
    public function setInstances(array $transforms, ?array $colors = null): self
    {
        $count = count($transforms);
        $this->instanceCount = $count;
        if ($count === 0) {
            $this->instanceData = '';
            $this->releaseInstances();
            return $this;
        }

        $chunks = [];
        $defaultColor = pack('f4', 1.0, 1.0, 1.0, 1.0);
        foreach ($transforms as $i => $mat) {
            $cBin = isset($colors[$i]) ? pack('f4', ...$colors[$i]) : $defaultColor;
            $chunks[] = $mat->toBinary() . $cBin;
        }

        $this->instanceData = implode('', $chunks);
        $this->instancesDirty = true;
        return $this;
    }

    /**
     * Sets raw pre-packed binary instance data ($count * 80 bytes: float4x4 model + float4 color).
     */
    public function setInstanceData(string $binaryData, int $count): self
    {
        $this->instanceData = $binaryData;
        $this->instanceCount = $count;
        $this->instancesDirty = true;
        return $this;
    }

    public function uploadInstancesMetal(mixed $device): void
    {
        if ($this->instanceCount <= 0) {
            return;
        }
        if ($this->nativeInstanceBuffer !== null && !$this->instancesDirty) {
            return;
        }
        if ($this->nativeInstanceBuffer !== null) {
            ObjC::msg('v:', $this->nativeInstanceBuffer, 'release');
        }
        $len = strlen($this->instanceData);
        $this->nativeInstanceBuffer = ObjC::msg('p:pLL', $device, 'newBufferWithBytes:length:options:', $this->instanceData, $len, 0)
            ?? throw new RuntimeException('Scene: failed to allocate Metal instance buffer');
        $this->instancesDirty = false;
    }

    public function uploadInstancesD3d(\FFI $f, \FFI\CData $device): void
    {
        if ($this->instanceCount <= 0) {
            return;
        }
        if ($this->nativeInstanceBuffer !== null && !$this->instancesDirty) {
            return;
        }
        $this->releaseInstances();

        $len = strlen($this->instanceData);
        $numFloat4 = intdiv($len, 16);

        $desc = $f->new('D3D11_BUFFER_DESC');
        $desc->ByteWidth = $len;
        $desc->Usage = 0; // DEFAULT
        $desc->BindFlags = 0x8; // BIND_SHADER_RESOURCE

        $buf = $f->new("char[$len]");
        \FFI::memcpy($buf, $this->instanceData, $len);
        $init = $f->new('D3D11_SUBRESOURCE_DATA');
        $init->pSysMem = $f->cast('void *', \FFI::addr($buf));

        $out = $f->new('void *');
        $hr = ($device->v->CreateBuffer)($device, \FFI::addr($desc), \FFI::addr($init), \FFI::addr($out));
        \MyPHP\Native\Com::check($hr, 'Scene: CreateBuffer (instances)');
        $this->nativeInstanceBuffer = \MyPHP\Native\Com::typed($f, $out, 'void *');

        $srvDesc = $f->new('D3D11_SHADER_RESOURCE_VIEW_DESC');
        $srvDesc->Format = 2; // DXGI_FORMAT_R32G32B32A32_FLOAT
        $srvDesc->ViewDimension = 1; // D3D11_SRV_DIMENSION_BUFFER
        $srvDesc->u[1] = $numFloat4;

        $srvOut = $f->new('void *');
        $hr = ($device->v->CreateShaderResourceView)($device, $this->nativeInstanceBuffer, \FFI::addr($srvDesc), \FFI::addr($srvOut));
        \MyPHP\Native\Com::check($hr, 'Scene: CreateShaderResourceView (instances)');
        $this->nativeInstanceSrv = \MyPHP\Native\Com::typed($f, $srvOut, 'void *');

        $this->instancesDirty = false;
    }

    public function uploadInstancesGl(\FFI $g): void
    {
        if ($this->instanceCount <= 0) {
            return;
        }
        if ($this->nativeInstanceBuffer !== null && !$this->instancesDirty) {
            return;
        }
        $this->releaseInstances();

        $ids = $g->new('unsigned int[1]');
        ($g->epoxy_glGenBuffers)(1, $ids);
        $vbo = (int)$ids[0];

        // GL_ARRAY_BUFFER = 0x8892, GL_STATIC_DRAW = 0x88E4
        ($g->epoxy_glBindBuffer)(0x8892, $vbo);
        ($g->epoxy_glBufferData)(0x8892, strlen($this->instanceData), $this->instanceData, 0x88E4);
        ($g->epoxy_glBindBuffer)(0x8892, 0);

        $this->nativeInstanceBuffer = $vbo;
        $this->instancesDirty = false;
    }

    public function releaseInstances(): void
    {
        if ($this->nativeInstanceBuffer === null) {
            return;
        }
        if (is_int($this->nativeInstanceBuffer)) {
            $g = \MyPHP\Native\Gl::ffi();
            $ids = $g->new('unsigned int[1]');
            $ids[0] = $this->nativeInstanceBuffer;
            ($g->epoxy_glDeleteBuffers)(1, $ids);
            $this->nativeInstanceBuffer = null;
            return;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            if ($this->nativeInstanceSrv !== null) {
                \MyPHP\Native\Com::release($this->nativeInstanceSrv);
                $this->nativeInstanceSrv = null;
            }
            \MyPHP\Native\Com::release($this->nativeInstanceBuffer);
        } else {
            ObjC::msg('v:', $this->nativeInstanceBuffer, 'release');
        }
        $this->nativeInstanceBuffer = null;
    }

    public function __destruct()
    {
        $this->releaseInstances();
    }

    public function modelMatrix(): Mat4
    {
        $p = $this->position;
        $r = $this->rotation;
        $s = $this->scale;

        if (
            $this->cachedModel !== null &&
            $p->x === $this->lastPx && $p->y === $this->lastPy && $p->z === $this->lastPz &&
            $r->x === $this->lastRx && $r->y === $this->lastRy && $r->z === $this->lastRz &&
            $s->x === $this->lastSx && $s->y === $this->lastSy && $s->z === $this->lastSz
        ) {
            return $this->cachedModel;
        }

        // T * Rz * Ry * Rx * S
        $t = Mat4::translation($p->x, $p->y, $p->z);
        $rx = Mat4::rotationX($r->x);
        $ry = Mat4::rotationY($r->y);
        $rz = Mat4::rotationZ($r->z);
        $sc = Mat4::scaling($s->x, $s->y, $s->z);

        $this->cachedModel = $t->multiply($rz)->multiply($ry)->multiply($rx)->multiply($sc);

        $this->lastPx = $p->x; $this->lastPy = $p->y; $this->lastPz = $p->z;
        $this->lastRx = $r->x; $this->lastRy = $r->y; $this->lastRz = $r->z;
        $this->lastSx = $s->x; $this->lastSy = $s->y; $this->lastSz = $s->z;

        return $this->cachedModel;
    }
}
