<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Scene;

use MyPHP\Native\ObjC;
use RuntimeException;

/**
 * 3D Mesh with GPU resident buffers (MTLBuffer on Metal, ID3D11Buffer on D3D11).
 *
 * Vertex format (48 bytes, 16-byte aligned for maximum memory bandwidth):
 *   float3 position;  // offset 0  (12 bytes)
 *   float3 normal;    // offset 12 (12 bytes)
 *   float2 uv;        // offset 24 (8 bytes)
 *   float4 color;     // offset 32 (16 bytes)
 */
final class Mesh
{
    public const VERTEX_STRIDE = 48;

    /** GPU Vertex Buffer handle */
    public mixed $nativeVertexBuffer = null;
    /** GPU Index Buffer handle */
    public mixed $nativeIndexBuffer = null;
    public int $vertexCount = 0;
    public int $indexCount = 0;

    /**
     * @param string $vertexData Raw binary float data (48 bytes per vertex)
     * @param string $indexData  Raw binary uint32 data (4 bytes per index)
     */
    public function __construct(
        public readonly string $vertexData,
        public readonly string $indexData,
        int $vertexCount,
        int $indexCount
    ) {
        $this->vertexCount = $vertexCount;
        $this->indexCount = $indexCount;
    }

    /**
     * Ensures GPU buffers are allocated and resident in VRAM.
     */
    public function uploadMetal($device): void
    {
        if ($this->nativeVertexBuffer !== null) {
            return;
        }

        $vLen = strlen($this->vertexData);
        $iLen = strlen($this->indexData);

        // MTLResourceStorageModeShared = 0
        $this->nativeVertexBuffer = ObjC::msg('p:pLL', $device, 'newBufferWithBytes:length:options:', $this->vertexData, $vLen, 0)
            ?? throw new RuntimeException('Scene: failed to create vertex buffer');

        $this->nativeIndexBuffer = ObjC::msg('p:pLL', $device, 'newBufferWithBytes:length:options:', $this->indexData, $iLen, 0)
            ?? throw new RuntimeException('Scene: failed to create index buffer');
    }

    public function release(): void
    {
        if ($this->nativeVertexBuffer !== null) {
            ObjC::msg('v:', $this->nativeVertexBuffer, 'release');
            $this->nativeVertexBuffer = null;
        }
        if ($this->nativeIndexBuffer !== null) {
            ObjC::msg('v:', $this->nativeIndexBuffer, 'release');
            $this->nativeIndexBuffer = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }

    /**
     * Creates a cube mesh with independent per-face normals.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $color
     */
    public static function cube(float $size = 1.0, array $color = [1.0, 1.0, 1.0, 1.0]): self
    {
        $h = $size * 0.5;
        [$r, $g, $b, $a] = $color;

        // 6 faces * 4 vertices = 24 vertices
        $faces = [
            // Front (Z+)
            [[-$h, -$h,  $h], [ $h, -$h,  $h], [ $h,  $h,  $h], [-$h,  $h,  $h], [0.0, 0.0, 1.0]],
            // Back (Z-)
            [[ $h, -$h, -$h], [-$h, -$h, -$h], [-$h,  $h, -$h], [ $h,  $h, -$h], [0.0, 0.0, -1.0]],
            // Top (Y+)
            [[-$h,  $h,  $h], [ $h,  $h,  $h], [ $h,  $h, -$h], [-$h,  $h, -$h], [0.0, 1.0, 0.0]],
            // Bottom (Y-)
            [[-$h, -$h, -$h], [ $h, -$h, -$h], [ $h, -$h,  $h], [-$h, -$h,  $h], [0.0, -1.0, 0.0]],
            // Right (X+)
            [[ $h, -$h,  $h], [ $h, -$h, -$h], [ $h,  $h, -$h], [ $h,  $h,  $h], [1.0, 0.0, 0.0]],
            // Left (X-)
            [[-$h, -$h, -$h], [-$h, -$h,  $h], [-$h,  $h,  $h], [-$h,  $h, -$h], [-1.0, 0.0, 0.0]],
        ];

        $vFloats = [];
        $indices = [];
        $idx = 0;

        foreach ($faces as $face) {
            [$p0, $p1, $p2, $p3, $n] = $face;
            $uvs = [[0.0, 1.0], [1.0, 1.0], [1.0, 0.0], [0.0, 0.0]];
            $pts = [$p0, $p1, $p2, $p3];

            for ($i = 0; $i < 4; $i++) {
                $pt = $pts[$i];
                $uv = $uvs[$i];
                // pos(3), normal(3), uv(2), color(4)
                array_push($vFloats,
                    $pt[0], $pt[1], $pt[2],
                    $n[0],  $n[1],  $n[2],
                    $uv[0], $uv[1],
                    $r, $g, $b, $a
                );
            }

            // Two triangles per face (CCW)
            array_push($indices,
                $idx, $idx + 1, $idx + 2,
                $idx, $idx + 2, $idx + 3
            );
            $idx += 4;
        }

        $vBinary = pack('f*', ...$vFloats);
        $iBinary = pack('V*', ...$indices);

        return new self($vBinary, $iBinary, 24, 36);
    }

    /**
     * Creates a UV sphere mesh.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $color
     */
    public static function sphere(float $radius = 1.0, int $segments = 24, int $rings = 16, array $color = [1.0, 1.0, 1.0, 1.0]): self
    {
        $vFloats = [];
        $indices = [];
        [$r, $g, $b, $a] = $color;

        for ($ring = 0; $ring <= $rings; $ring++) {
            $theta = $ring * M_PI / $rings;
            $sinTheta = sin($theta);
            $cosTheta = cos($theta);

            for ($seg = 0; $seg <= $segments; $seg++) {
                $phi = $seg * 2.0 * M_PI / $segments;
                $sinPhi = sin($phi);
                $cosPhi = cos($phi);

                $nx = $cosPhi * $sinTheta;
                $ny = $cosTheta;
                $nz = $sinPhi * $sinTheta;

                $u = $seg / $segments;
                $v = $ring / $rings;

                array_push($vFloats,
                    $radius * $nx, $radius * $ny, $radius * $nz,
                    $nx, $ny, $nz,
                    $u, $v,
                    $r, $g, $b, $a
                );
            }
        }

        for ($ring = 0; $ring < $rings; $ring++) {
            for ($seg = 0; $seg < $segments; $seg++) {
                $first = $ring * ($segments + 1) + $seg;
                $second = $first + $segments + 1;

                array_push($indices,
                    $first, $second, $first + 1,
                    $second, $second + 1, $first + 1
                );
            }
        }

        $vCount = ($rings + 1) * ($segments + 1);
        $iCount = count($indices);

        return new self(pack('f*', ...$vFloats), pack('V*', ...$indices), $vCount, $iCount);
    }
}
