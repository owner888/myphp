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

    /**
     * Creates a horizontal ground/floor plane mesh (XZ plane, normal pointing Y+).
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $color
     */
    public static function plane(float $width = 10.0, float $depth = 10.0, float $uvRepeat = 1.0, array $color = [1.0, 1.0, 1.0, 1.0]): self
    {
        $hw = $width * 0.5;
        $hd = $depth * 0.5;
        [$r, $g, $b, $a] = $color;

        $vFloats = [
            -$hw, 0.0,  $hd,   0.0, 1.0, 0.0,   0.0,       $uvRepeat,   $r, $g, $b, $a,
             $hw, 0.0,  $hd,   0.0, 1.0, 0.0,   $uvRepeat, $uvRepeat,   $r, $g, $b, $a,
             $hw, 0.0, -$hd,   0.0, 1.0, 0.0,   $uvRepeat, 0.0,         $r, $g, $b, $a,
            -$hw, 0.0, -$hd,   0.0, 1.0, 0.0,   0.0,       0.0,         $r, $g, $b, $a,
        ];
        $indices = [0, 1, 2, 0, 2, 3];

        return new self(pack('f*', ...$vFloats), pack('V*', ...$indices), 4, 6);
    }

    /**
     * Loads a 3D mesh from a Wavefront .obj file.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $color
     */
    public static function fromObjFile(string $path, array $color = [1.0, 1.0, 1.0, 1.0]): self
    {
        $src = @file_get_contents($path);
        if ($src === false) {
            throw new RuntimeException("Scene: cannot read OBJ file: $path");
        }
        return self::fromObj($src, $color);
    }

    /**
     * Parses Wavefront .obj source string into a GPU-aligned 3D Mesh.
     * Supports v (positions), vt (UVs), vn (normals), and triangulates n-gon faces.
     * Automatically computes flat face normals when vn is omitted.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $color
     */
    public static function fromObj(string $objSource, array $color = [1.0, 1.0, 1.0, 1.0]): self
    {
        $positions = []; // 1-indexed: [x, y, z]
        $uvs = [];       // 1-indexed: [u, v]
        $normals = [];   // 1-indexed: [nx, ny, nz]

        $vFloats = [];
        $indices = [];
        $vertexMap = []; // "v/vt/vn" => vertexIndex
        $vCount = 0;
        [$cr, $cg, $cb, $ca] = $color;

        $lines = explode("\n", $objSource);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = preg_split('/\s+/', $line);
            if (!$parts) {
                continue;
            }
            $cmd = $parts[0];
            if ($cmd === 'v' && count($parts) >= 4) {
                $positions[] = [(float)$parts[1], (float)$parts[2], (float)$parts[3]];
            } elseif ($cmd === 'vt' && count($parts) >= 3) {
                // Flip V for top-left texture origin (1.0 - v)
                $uvs[] = [(float)$parts[1], 1.0 - (float)$parts[2]];
            } elseif ($cmd === 'vn' && count($parts) >= 4) {
                $normals[] = [(float)$parts[1], (float)$parts[2], (float)$parts[3]];
            } elseif ($cmd === 'f' && count($parts) >= 4) {
                $faceSpecs = array_slice($parts, 1);
                $nVerts = count($faceSpecs);

                // Parse face vertex indices
                $parsed = [];
                $hasNormal = true;
                foreach ($faceSpecs as $spec) {
                    $sub = explode('/', $spec);
                    $vi = (int)$sub[0];
                    $ti = isset($sub[1]) && $sub[1] !== '' ? (int)$sub[1] : 0;
                    $ni = isset($sub[2]) && $sub[2] !== '' ? (int)$sub[2] : 0;

                    if ($vi < 0) { $vi = count($positions) + 1 + $vi; }
                    if ($ti < 0) { $ti = count($uvs) + 1 + $ti; }
                    if ($ni < 0) { $ni = count($normals) + 1 + $ni; }
                    if ($ni === 0) { $hasNormal = false; }

                    $parsed[] = [$vi, $ti, $ni, $spec];
                }

                // Compute flat normal if vn was omitted
                $flatNormal = [0.0, 1.0, 0.0];
                if (!$hasNormal && $nVerts >= 3) {
                    $p0 = $positions[$parsed[0][0] - 1] ?? [0.0, 0.0, 0.0];
                    $p1 = $positions[$parsed[1][0] - 1] ?? [0.0, 0.0, 0.0];
                    $p2 = $positions[$parsed[2][0] - 1] ?? [0.0, 0.0, 0.0];
                    $ux = $p1[0] - $p0[0]; $uy = $p1[1] - $p0[1]; $uz = $p1[2] - $p0[2];
                    $vx = $p2[0] - $p0[0]; $vy = $p2[1] - $p0[1]; $vz = $p2[2] - $p0[2];
                    $nx = $uy * $vz - $uz * $vy;
                    $ny = $uz * $vx - $ux * $vz;
                    $nz = $ux * $vy - $uy * $vx;
                    $len = sqrt($nx * $nx + $ny * $ny + $nz * $nz);
                    if ($len > 1e-6) {
                        $flatNormal = [$nx / $len, $ny / $len, $nz / $len];
                    }
                }

                $faceIndices = [];
                foreach ($parsed as [$vi, $ti, $ni, $spec]) {
                    $key = $hasNormal ? "$vi/$ti/$ni" : '';
                    if ($key !== '' && isset($vertexMap[$key])) {
                        $faceIndices[] = $vertexMap[$key];
                        continue;
                    }

                    $pos = $positions[$vi - 1] ?? [0.0, 0.0, 0.0];
                    $uv  = $ti > 0 ? ($uvs[$ti - 1] ?? [0.0, 0.0]) : [0.0, 0.0];
                    $nrm = $ni > 0 ? ($normals[$ni - 1] ?? [0.0, 1.0, 0.0]) : $flatNormal;

                    array_push($vFloats,
                        $pos[0], $pos[1], $pos[2],
                        $nrm[0], $nrm[1], $nrm[2],
                        $uv[0],  $uv[1],
                        $cr, $cg, $cb, $ca
                    );

                    $idx = $vCount++;
                    if ($key !== '') {
                        $vertexMap[$key] = $idx;
                    }
                    $faceIndices[] = $idx;
                }

                // Fan triangulation for n-gons (quads, polygons)
                for ($i = 1; $i < $nVerts - 1; $i++) {
                    array_push($indices, $faceIndices[0], $faceIndices[$i], $faceIndices[$i + 1]);
                }
            }
        }

        if ($vCount === 0 || $indices === []) {
            throw new RuntimeException('Scene: OBJ contains no valid geometry');
        }

        return new self(pack('f*', ...$vFloats), pack('V*', ...$indices), $vCount, count($indices));
    }

    /**
     * Loads a 3D mesh from a binary glTF 2.0 (.glb) file.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $color
     */
    public static function fromGlbFile(string $path, array $color = [1.0, 1.0, 1.0, 1.0]): self
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException("Scene: cannot read GLB file: $path");
        }
        return self::fromGlb($bytes, $color);
    }

    /**
     * Parses a binary glTF 2.0 (.glb) payload into a GPU-aligned 3D Mesh.
     * Extracts POSITION, NORMAL, TEXCOORD_0, COLOR_0, and indices from the first mesh primitive.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $color
     */
    public static function fromGlb(string $glbBytes, array $color = [1.0, 1.0, 1.0, 1.0]): self
    {
        if (strlen($glbBytes) < 20) {
            throw new RuntimeException('Scene: invalid GLB header');
        }

        $header = unpack('Vmagic/Vversion/Vlength', substr($glbBytes, 0, 12));
        if ($header['magic'] !== 0x46546C67) { // 'glTF'
            throw new RuntimeException('Scene: invalid GLB magic bytes');
        }

        $offset = 12;
        $totalLen = strlen($glbBytes);
        $jsonChunk = null;
        $binChunk = '';

        while ($offset + 8 <= $totalLen) {
            $chunkHeader = unpack('Vlength/Vtype', substr($glbBytes, $offset, 8));
            $cLen = $chunkHeader['length'];
            $cType = $chunkHeader['type'];
            $offset += 8;

            $chunkData = substr($glbBytes, $offset, $cLen);
            $offset += $cLen;

            if ($cType === 0x4E4F534A) { // 'JSON'
                $jsonChunk = json_decode($chunkData, true, flags: JSON_THROW_ON_ERROR);
            } elseif ($cType === 0x004E4942) { // 'BIN\0'
                $binChunk = $chunkData;
            }
        }

        if (!is_array($jsonChunk) || empty($jsonChunk['meshes'][0]['primitives'][0])) {
            throw new RuntimeException('Scene: GLB contains no mesh primitives');
        }

        $prim = $jsonChunk['meshes'][0]['primitives'][0];
        $attrs = $prim['attributes'] ?? [];
        if (!isset($attrs['POSITION'])) {
            throw new RuntimeException('Scene: GLB primitive missing POSITION attribute');
        }

        $accessors = $jsonChunk['accessors'] ?? [];
        $bufferViews = $jsonChunk['bufferViews'] ?? [];

        $readAccessor = static function (int $accIdx) use ($accessors, $bufferViews, $binChunk): array {
            $acc = $accessors[$accIdx] ?? throw new RuntimeException("Scene: missing accessor $accIdx");
            $bv = $bufferViews[$acc['bufferView']] ?? throw new RuntimeException('Scene: missing bufferView');
            $count = (int)$acc['count'];
            $compType = (int)$acc['componentType'];
            $type = (string)$acc['type'];

            $numComps = match ($type) {
                'SCALAR' => 1,
                'VEC2'   => 2,
                'VEC3'   => 3,
                'VEC4'   => 4,
                default  => 1,
            };
            $compBytes = match ($compType) {
                5126 => 4, // FLOAT
                5125 => 4, // UNSIGNED_INT
                5123 => 2, // UNSIGNED_SHORT
                5121 => 1, // UNSIGNED_BYTE
                default => throw new RuntimeException("Scene: unsupported GLB componentType $compType"),
            };

            $byteOffset = (int)($bv['byteOffset'] ?? 0) + (int)($acc['byteOffset'] ?? 0);
            $stride = (int)($bv['byteStride'] ?? ($numComps * $compBytes));

            $out = [];
            for ($i = 0; $i < $count; $i++) {
                $pos = $byteOffset + $i * $stride;
                $slice = substr($binChunk, $pos, $numComps * $compBytes);
                $vals = match ($compType) {
                    5126 => array_values(unpack("f$numComps", $slice)),
                    5125 => array_values(unpack("V$numComps", $slice)),
                    5123 => array_values(unpack("v$numComps", $slice)),
                    5121 => array_values(unpack("C$numComps", $slice)),
                };
                $out[] = $numComps === 1 ? $vals[0] : $vals;
            }
            return $out;
        };

        /** @var list<array{0: float, 1: float, 2: float}> $positions */
        $positions = $readAccessor((int)$attrs['POSITION']);
        $vCount = count($positions);

        /** @var ?list<array{0: float, 1: float, 2: float}> $normals */
        $normals = isset($attrs['NORMAL']) ? $readAccessor((int)$attrs['NORMAL']) : null;
        /** @var ?list<array{0: float, 1: float}> $uvs */
        $uvs = isset($attrs['TEXCOORD_0']) ? $readAccessor((int)$attrs['TEXCOORD_0']) : null;

        [$cr, $cg, $cb, $ca] = $color;
        $vFloats = [];

        for ($i = 0; $i < $vCount; $i++) {
            $p = $positions[$i];
            $n = $normals[$i] ?? [0.0, 1.0, 0.0];
            $u = $uvs[$i] ?? [0.0, 0.0];

            array_push($vFloats,
                (float)$p[0], (float)$p[1], (float)$p[2],
                (float)$n[0], (float)$n[1], (float)$n[2],
                (float)$u[0], (float)$u[1],
                $cr, $cg, $cb, $ca
            );
        }

        if (isset($prim['indices'])) {
            /** @var list<int> $indices */
            $indices = array_map('intval', $readAccessor((int)$prim['indices']));
        } else {
            $indices = range(0, $vCount - 1);
        }

        return new self(pack('f*', ...$vFloats), pack('V*', ...$indices), $vCount, count($indices));
    }
}
