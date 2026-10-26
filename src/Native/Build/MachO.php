<?php
declare(strict_types=1);

namespace MyPHP\Native\Build;

use RuntimeException;

/**
 * Mach-O files as packaging reads and writes them (mygo's resources.go and
 * bundle.go): which files are code, for which architecture, whether they
 * are signed, and universal (fat) binaries made like lipo, so no Xcode
 * tools are needed.
 *
 * @internal
 */
final class MachO
{
    public const CPU_AMD64 = 0x01000007, CPU_ARM64 = 0x0100000c;

    /** The CPU type of a 64-bit Mach-O file for one architecture, what writeUniversal combines, or null for anything else. */
    public static function cpu(string $path): ?int
    {
        $h = @file_get_contents($path, false, null, 0, 12);
        if ($h === false || strlen($h) < 12 || unpack('V', $h)[1] !== 0xfeedfacf) {
            return null;
        }
        return unpack('V', $h, 4)[1];
    }

    /** Describes the code in a file for messages, or '' when it is no Mach-O file. */
    public static function kind(string $path): string
    {
        $cpu = self::cpu($path);
        return match (true) {
            $cpu === self::CPU_ARM64 => 'arm64 code',
            $cpu === self::CPU_AMD64 => 'x86_64 code',
            $cpu !== null => 'code for another architecture',
            self::read($path)[0] => 'universal or 32-bit code',
            default => '',
        };
    }

    /**
     * Whether the file at $path is a Mach-O file or a universal binary, and
     * whether every architecture in it has a code signature.
     *
     * @return array{bool, bool}
     */
    public static function read(string $path): array
    {
        $f = @fopen($path, 'rb');
        if ($f === false) {
            return [false, false];
        }
        try {
            $head = fread($f, 8);
            if ($head === false || strlen($head) < 8) {
                return [false, false];
            }
            $magic = unpack('N', $head)[1];
            if (in_array($magic, [0xfeedface, 0xfeedfacf, 0xcefaedfe, 0xcffaedfe], true)) {
                return [true, self::signed($f, 0)];
            }
            if ($magic !== 0xcafebabe && $magic !== 0xcafebabf) {
                return [false, false];
            }
            $n = unpack('N', $head, 4)[1];
            if ($magic === 0xcafebabe && $n >= 20) {
                return [false, false]; // Java class files share the magic; their version follows it, a much larger number than the architectures of a universal binary
            }
            $size = $magic === 0xcafebabf ? 32 : 20; // fat_arch_64, fat_arch
            $signed = $n > 0;
            for ($i = 0; $i < $n; $i++) {
                fseek($f, 8 + $i * $size);
                $arch = fread($f, $size);
                if ($arch === false || strlen($arch) < $size) {
                    return [true, false];
                }
                $offset = $magic === 0xcafebabf ? unpack('J', $arch, 8)[1] : unpack('N', $arch, 8)[1];
                $signed = $signed && self::signed($f, $offset);
            }
            return [true, $signed];
        } finally {
            fclose($f);
        }
    }

    /** Whether the Mach-O file at $offset in $f has a code signature, an LC_CODE_SIGNATURE load command. @param resource $f */
    private static function signed($f, int $offset): bool
    {
        fseek($f, $offset);
        $h = fread($f, 28); // mach_header; mach_header_64 adds a reserved field
        if ($h === false || strlen($h) < 28) {
            return false;
        }
        $le = true;
        $magic = unpack('V', $h)[1];
        if ($magic === 0xcefaedfe || $magic === 0xcffaedfe) {
            $le = false;
            $magic = unpack('N', $h)[1];
        }
        $u32 = fn(string $b, int $at): int => unpack($le ? 'V' : 'N', $b, $at)[1];
        $size = match ($magic) { 0xfeedface => 28, 0xfeedfacf => 32, default => 0 };
        if ($size === 0) {
            return false;
        }
        $ncmds = $u32($h, 16); $sizeofcmds = $u32($h, 20);
        if ($sizeofcmds > 1 << 24) {
            return false;
        }
        fseek($f, $offset + $size);
        $cmds = $sizeofcmds > 0 ? fread($f, $sizeofcmds) : '';
        if ($cmds === false || strlen($cmds) < $sizeofcmds) {
            return false;
        }
        for ($i = 0, $at = 0; $i < $ncmds; $i++) {
            if (strlen($cmds) - $at < 8) {
                break;
            }
            $cmd = $u32($cmds, $at); $cmdsize = $u32($cmds, $at + 4);
            if ($cmd === 0x1d) { // LC_CODE_SIGNATURE
                return true;
            }
            if ($cmdsize < 8 || $cmdsize > strlen($cmds) - $at) {
                break;
            }
            $at += $cmdsize;
        }
        return false;
    }

    /** Combines 64-bit Mach-O executables into a universal (fat) binary at $out, like lipo, each slice aligned to 16 KiB. */
    public static function writeUniversal(string $out, string ...$slices): void
    {
        $align = 14; // 2^14 = 16 KiB
        $archs = [];
        foreach ($slices as $p) {
            $data = @file_get_contents($p);
            if ($data === false) {
                throw new RuntimeException("cannot read $p");
            }
            if (strlen($data) < 12 || unpack('V', $data)[1] !== 0xfeedfacf) {
                throw new RuntimeException("$p is not a 64-bit Mach-O file");
            }
            $archs[] = [unpack('V', $data, 4)[1], unpack('V', $data, 8)[1], $data];
        }
        $buf = pack('NN', 0xcafebabe, count($archs));
        $offset = 1 << $align;
        $offsets = [];
        foreach ($archs as $i => [$type, $subtype, $data]) {
            $offsets[$i] = $offset;
            $buf .= pack('NNNNN', $type, $subtype, $offset, strlen($data), $align);
            $offset += (strlen($data) + (1 << $align) - 1) & ~((1 << $align) - 1);
        }
        foreach ($archs as $i => [, , $data]) {
            $buf .= str_repeat("\0", $offsets[$i] - strlen($buf)) . $data;
        }
        if (file_put_contents($out, $buf) === false) {
            throw new RuntimeException("cannot write $out");
        }
        chmod($out, 0755);
    }

    /** The section phpmicro reads where its payload is (a big-endian offset and limit), which `myphp micro` links into micro.sfx. */
    public const MICRO_SECTION = '__micro_sfxsize';

    /**
     * $payload inside the 64-bit Mach-O executable $sfx (a micro.sfx), so
     * that codesign signs it: past the end of the file codesign refuses
     * the executable ("main executable failed strict validation"), so the
     * signature comes off, __LINKEDIT grows over the payload, and the
     * __DATA,__micro_sfxsize section says where it starts and ends, which
     * phpmicro reads instead of the end of __LINKEDIT. The executable is
     * written to $out unsigned, a piece at a time (micro.sfx with ICU is
     * some 50 MB); signing the bundle signs it.
     */
    public static function embed(string $sfx, string $payload, string $out): void
    {
        [$cpu, $ncmds, $sizeofcmds, $linkedit, $section, $signature] = self::microLayout($sfx);
        if ($section === null || $section['size'] < 16) {
            throw new RuntimeException('micro.sfx has no __DATA,' . self::MICRO_SECTION . ' section of 16 bytes, which `myphp micro` links into it');
        }
        if ($linkedit === null) {
            throw new RuntimeException('micro.sfx has no __LINKEDIT segment');
        }
        ['fileoff' => $fileoff, 'filesize' => $filesize] = unpack('Pfileoff/Pfilesize', $sfx, $linkedit + 40);
        if ($fileoff + $filesize !== strlen($sfx)) {
            throw new RuntimeException('micro.sfx has data after its __LINKEDIT segment');
        }
        $head = substr($sfx, 0, 32 + $sizeofcmds);
        $end = strlen($sfx); // where what is kept of micro.sfx ends
        if ($signature !== null) { // the signature, at the end of __LINKEDIT, comes off with its load command, the last
            [$cmdAt, $off, $sigSize] = $signature;
            if ($off < $fileoff || $off + $sigSize !== strlen($sfx)) {
                throw new RuntimeException('micro.sfx has its code signature elsewhere than at its end');
            }
            if ($cmdAt + 16 !== 32 + $sizeofcmds) {
                throw new RuntimeException('micro.sfx has load commands after its code signature');
            }
            $head = substr_replace(substr_replace($head, str_repeat("\0", 16), $cmdAt, 16), pack('VV', $ncmds - 1, $sizeofcmds - 16), 16, 8);
            $end = $off;
            $filesize = $off - $fileoff;
        }
        $at = $section['offset'];
        if ($at < strlen($head) || $at + 16 > $end) {
            throw new RuntimeException('micro.sfx has its ' . self::MICRO_SECTION . ' section outside its data');
        }
        $filesize += strlen($payload);
        $page = $cpu === self::CPU_ARM64 ? 0x4000 : 0x1000;
        $head = substr_replace($head, pack('PPP', intdiv($filesize + $page - 1, $page) * $page, $fileoff, $filesize), $linkedit + 32, 24);
        $f = @fopen($out, 'wb') ?: throw new RuntimeException("cannot write $out");
        try {
            $put = function (int $from, int $to) use ($f, $sfx): void {
                for ($p = $from; $p < $to; $p += 1 << 20) {
                    fwrite($f, substr($sfx, $p, min(1 << 20, $to - $p)));
                }
            };
            fwrite($f, $head);
            $put(strlen($head), $at);
            fwrite($f, pack('JJ', $end, $end + strlen($payload)));
            $put($at + 16, $end);
            fwrite($f, $payload) === strlen($payload) || throw new RuntimeException("cannot write $out");
        } finally {
            fclose($f);
        }
    }

    /** Whether the micro.sfx at $path has the section embed() writes where its payload is. */
    public static function hasMicroSection(string $path): bool
    {
        $sfx = @file_get_contents($path, false, null, 0, 1 << 20); // the load commands are at its start
        try {
            $section = $sfx === false ? null : self::microLayout($sfx)[4];
        } catch (RuntimeException) {
            return false;
        }
        return $section !== null && $section['size'] >= 16;
    }

    /**
     * What embed() changes in a 64-bit Mach-O executable: its CPU, load
     * commands, __LINKEDIT's load command, the micro section and the code
     * signature's load command with where the signature is.
     *
     * @return array{int, int, int, ?int, ?array{size: int, offset: int}, ?array{int, int, int}}
     */
    private static function microLayout(string $sfx): array
    {
        if (strlen($sfx) < 32 || unpack('V', $sfx)[1] !== 0xfeedfacf) {
            throw new RuntimeException('micro.sfx is not a 64-bit Mach-O executable');
        }
        ['cpu' => $cpu, 'ncmds' => $ncmds, 'sizeofcmds' => $sizeofcmds] = unpack('Vmagic/Vcpu/Vsub/Vtype/Vncmds/Vsizeofcmds', $sfx);
        $linkedit = $section = $signature = null;
        for ($i = 0, $at = 32; $i < $ncmds; $i++) {
            if ($at + 8 > 32 + $sizeofcmds || 32 + $sizeofcmds > strlen($sfx)) {
                throw new RuntimeException('micro.sfx has load commands past its header');
            }
            ['cmd' => $cmd, 'size' => $size] = unpack('Vcmd/Vsize', $sfx, $at);
            if ($size < 8 || $at + $size > 32 + $sizeofcmds) {
                throw new RuntimeException('micro.sfx has a load command past its header');
            }
            if ($cmd === 0x19) { // LC_SEGMENT_64
                $seg = rtrim(substr($sfx, $at + 8, 16), "\0");
                if ($seg === '__LINKEDIT') { $linkedit = $at; }
                $nsects = $size >= 72 ? unpack('V', $sfx, $at + 64)[1] : 0;
                for ($k = 0; $k < $nsects && 72 + ($k + 1) * 80 <= $size; $k++) {
                    $s = $at + 72 + $k * 80;
                    if ($seg === '__DATA' && rtrim(substr($sfx, $s, 16), "\0") === self::MICRO_SECTION) {
                        $section = unpack('Psize/Voffset', $sfx, $s + 40);
                    }
                }
            } elseif ($cmd === 0x1d) { // LC_CODE_SIGNATURE
                $signature = [$at, ...array_values(unpack('Voff/Vsize', $sfx, $at + 8))];
            }
            $at += $size;
        }
        return [$cpu, $ncmds, $sizeofcmds, $linkedit, $section, $signature];
    }

    /** What phpmicro reads after itself: the INI it runs with (a magic, its length) and the app's phar. */
    public static function microPayload(string $ini, string $phar): string
    {
        return "\xfd\xf6\x69\xe6" . pack('N', strlen($ini)) . $ini . $phar;
    }
}
