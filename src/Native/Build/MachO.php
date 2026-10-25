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
}
