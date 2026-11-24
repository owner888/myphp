<?php
declare(strict_types=1);

namespace MyPHP\Native\Build;

use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Compiles and compacts an app's PHP tree and the framework's `src/` for
 * production builds (`myphp build`), and packs the read-only native app image
 * (`libapp.so` on Android, `__TEXT,__const` on iOS) that mobile release builds
 * map directly into memory without shipping `.php` source files on disk.
 *
 * Development runs (`myphp dev`, `myphp run`) bypass this completely so live
 * file-based hot reload stays untouched.
 *
 * @internal
 */
final class AppImage
{
    public const MAGIC = "MYPHPAP1";

    /**
     * Strips PHP comments, docblocks and redundant horizontal whitespace while
     * keeping every newline at its exact line number (`#[Attribute]` metadata,
     * `Observer` closure line ranges, and exception stack traces stay exact).
     */
    public static function compactPhp(string $source): string
    {
        $tokens = PhpToken::tokenize($source);
        $out = '';
        $prevWord = false;
        $pendingSpace = false;
        foreach ($tokens as $t) {
            if ($t->is([T_COMMENT, T_DOC_COMMENT, T_WHITESPACE])) {
                $nl = substr_count($t->text, "
");
                if ($nl > 0) {
                    $out .= str_repeat("
", $nl);
                    $pendingSpace = false;
                    $prevWord = false;
                } elseif ($prevWord) {
                    $pendingSpace = true;
                }
                continue;
            }
            $text = $t->text;
            $first = $text[0] ?? '';
            $startsWord = ($first >= 'a' && $first <= 'z') || ($first >= 'A' && $first <= 'Z')
                || ($first >= '0' && $first <= '9') || $first === '_' || $first === '$' || $first === chr(92) || $first >= "\x80";
            if ($pendingSpace && $startsWord) {
                $out .= ' ';
            }
            $out .= $text;
            $pendingSpace = false;
            $last = $text[strlen($text) - 1] ?? '';
            $prevWord = (($last >= 'a' && $last <= 'z') || ($last >= 'A' && $last <= 'Z')
                || ($last >= '0' && $last <= '9') || $last === '_' || $last >= "\x80")
                && !str_ends_with($text, "
");
        }
        return $out;
    }

    /**
     * Whether a framework path relative to `src/` belongs to $targetOs
     * (`darwin`, `linux`, `windows`, `android`, `ios`). Prunes other platforms'
     * backends, build-only classes, C/ObjC launcher sources and test files.
     */
    public static function keepFrameworkFile(string $relFromSrc, string $targetOs): bool
    {
        $rel = strtr($relFromSrc, [chr(92) => '/']);
        if (str_ends_with($rel, '.test.ts') || str_ends_with($rel, '.c') || str_ends_with($rel, '.m')) {
            return false;
        }
        // Native/Build: Windows runtime uses Png (Tray, MenuDraw, Clipboard); S3 & Slug are used by Update\Publish.
        if (str_starts_with($rel, 'Native/Build/')) {
            $base = basename($rel);
            if ($targetOs === 'windows' && $base === 'Png.php') {
                return true;
            }
            if (($targetOs === 'darwin' || $targetOs === 'windows' || $targetOs === 'linux') && ($base === 'S3.php' || $base === 'Slug.php' || $base === 'pinyin.bin' || $base === 'Linux.php')) {
                return true;
            }
            return false;
        }
        if (str_starts_with($rel, 'Platform/')) {
            $parts = explode('/', $rel, 3);
            $sub = $parts[1] ?? '';
            if (!in_array($sub, ['Darwin', 'Linux', 'Windows', 'Android', 'iOS'], true)) {
                return true; // Platform interfaces & enums
            }
            return match ($targetOs) {
                'darwin' => $sub === 'Darwin',
                'linux' => $sub === 'Linux' || ($sub === 'Android' && ($parts[2] ?? '') === 'Symbols.php') || ($sub === 'Android' && str_starts_with($parts[2] ?? '', 'MaterialSymbols')),
                'windows' => $sub === 'Windows'
                    || ($sub === 'Linux' && ($parts[2] ?? '') === 'Keys.php')
                    || ($sub === 'Android' && (($parts[2] ?? '') === 'Symbols.php' || str_starts_with($parts[2] ?? '', 'MaterialSymbols'))),
                'android' => $sub === 'Android',
                'ios' => $sub === 'iOS',
                default => true,
            };
        }
        return true;
    }

    /**
     * Prunes unused platform files from an already-copied framework `src` tree
     * on disk, and compacts every `.php` file under $appRoot in place.
     */
    public static function optimizeTree(string $appRoot, string $frameworkSrcDir, string $targetOs): void
    {
        if (is_dir($frameworkSrcDir)) {
            $prefixLen = strlen(rtrim($frameworkSrcDir, chr(47) . chr(92))) + 1;
            $toDelete = [];
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($frameworkSrcDir, \FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $full = $f->getPathname();
                if ($f->isDir()) {
                    continue;
                }
                $rel = substr($full, $prefixLen);
                if (!self::keepFrameworkFile($rel, $targetOs)) {
                    $toDelete[] = $full;
                }
            }
            foreach ($toDelete as $p) {
                @unlink($p);
            }
            // Remove any directories left empty
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($frameworkSrcDir, \FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                if ($f->isDir() && !$f->isLink() && (array_diff(scandir($f->getPathname()) ?: [], ['.', '..'])) === []) {
                    @rmdir($f->getPathname());
                }
            }
        }

        if (!is_dir($appRoot)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appRoot, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && !$f->isLink() && strtolower($f->getExtension()) === 'php') {
                $path = $f->getPathname();
                $raw = (string)file_get_contents($path);
                file_put_contents($path, self::compactPhp($raw));
            }
        }
    }

    /**
     * Encodes a map of relative POSIX paths => raw file bytes into a binary
     * `MYPHPAP1` read-only image (sorted by path for O(log N) binary search in
     * C, each payload raw-DEFLATE compressed at level 9).
     *
     * Binary layout (little-endian):
     *   0..7   magic "MYPHPAP1"
     *   8..11  uint32 version (1)
     *   12..15 uint32 file_count
     *   16..   file_count * 24-byte entries:
     *            uint32 path_off
     *            uint32 path_len
     *            uint32 data_off
     *            uint32 comp_len
     *            uint32 raw_len
     *            uint32 flags (bit 0: 1 = raw deflate, 0 = stored)
     *   followed by NUL-terminated path strings, then compressed payloads.
     *
     * @param array<string, string> $files relative path (no leading slash) => raw contents
     */
    public static function encode(array $files): string
    {
        $norm = [];
        foreach ($files as $rel => $content) {
            $clean = trim(strtr($rel, [chr(92) => '/']), '/');
            if ($clean === '') {
                throw new RuntimeException('AppImage: empty file path');
            }
            $norm[$clean] = $content;
        }
        ksort($norm, SORT_STRING);

        $count = count($norm);
        $headerSize = 16 + $count * 24;
        $pathsBlob = '';
        $dataBlob = '';
        $meta = [];

        foreach ($norm as $path => $raw) {
            $pathOff = $headerSize + strlen($pathsBlob);
            $pathsBlob .= $path . "\0";
            $rawLen = strlen($raw);
            $comp = $rawLen > 32 ? gzdeflate($raw, 9) : false;
            if (is_string($comp) && strlen($comp) < $rawLen) {
                $payload = $comp;
                $flags = 1;
            } else {
                $payload = $raw;
                $flags = 0;
            }
            $meta[] = [$pathOff, strlen($path), strlen($dataBlob), strlen($payload), $rawLen, $flags];
            $dataBlob .= $payload;
        }

        $dataBase = $headerSize + strlen($pathsBlob);
        $entries = '';
        foreach ($meta as [$pOff, $pLen, $dRel, $cLen, $rLen, $flags]) {
            $entries .= pack('V6', $pOff, $pLen, $dataBase + $dRel, $cLen, $rLen, $flags);
        }

        return self::MAGIC . pack('V2', 1, $count) . $entries . $pathsBlob . $dataBlob;
    }

    /**
     * Decodes a `MYPHPAP1` binary image back into `path => raw contents` (for
     * tests and verification).
     *
     * @return array<string, string>
     */
    public static function decode(string $blob): array
    {
        if (strlen($blob) < 16 || substr($blob, 0, 8) !== self::MAGIC) {
            throw new RuntimeException('AppImage: invalid magic');
        }
        [, $ver, $count] = unpack('V2', $blob, 8);
        if ($ver !== 1) {
            throw new RuntimeException("AppImage: unsupported version $ver");
        }
        if (strlen($blob) < 16 + $count * 24) {
            throw new RuntimeException('AppImage: truncated entry table');
        }
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            [, $pOff, $pLen, $dOff, $cLen, $rLen, $flags] = unpack('V6', $blob, 16 + $i * 24);
            $path = substr($blob, $pOff, $pLen);
            $payload = substr($blob, $dOff, $cLen);
            $raw = ($flags & 1) !== 0 ? gzinflate($payload) : $payload;
            if (!is_string($raw) || strlen($raw) !== $rLen) {
                throw new RuntimeException("AppImage: corrupt payload for $path");
            }
            $out[$path] = $raw;
        }
        return $out;
    }

    /**
     * Collects all mobile bundle code files (framework `src/` + non-PHP files
     * inside `src/` such as `l10n/*.json` and `bridge.js`, plus the project's
     * `.php` files, `myphp.json`, and generated i18n catalog `.json` files)
     * into a single `MYPHPAP1` binary blob.
     *
     * @param list<string> $excludePatterns
     */
    public static function buildMobileBlob(string $frameworkRoot, ?string $projectDir, ?string $entryFile, string $targetOs, array $excludePatterns = []): string
    {
        $files = [];
        $srcDir = rtrim($frameworkRoot, chr(47) . chr(92)) . chr(47) . "src";
        $srcPrefix = strlen($srcDir) + 1;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->isLink()) {
                continue;
            }
            $rel = strtr(substr($f->getPathname(), $srcPrefix), [chr(92) => '/']);
            if (basename($rel)[0] === '.' || !self::keepFrameworkFile($rel, $targetOs)) {
                continue;
            }
            $raw = (string)file_get_contents($f->getPathname());
            $files["src/$rel"] = str_ends_with(strtolower($rel), '.php') ? self::compactPhp($raw) : $raw;
        }

        if ($projectDir !== null && is_dir($projectDir)) {
            $projDir = (string)(realpath($projectDir) ?: rtrim($projectDir, chr(47) . chr(92)));
            $entryAbs = $entryFile !== null && $entryFile !== '' ? (string)(realpath($entryFile) ?: $entryFile) : "$projDir/main.php";
            if (!is_file($entryAbs) && is_file("$projDir/app.php")) {
                $entryAbs = "$projDir/app.php";
            }
            if (is_file("$projDir/myphp.json")) {
                $files['myphp.json'] = (string)file_get_contents("$projDir/myphp.json");
            }
            if ($projDir !== (string)realpath($frameworkRoot)) {
                $projPrefix = strlen($projDir) + 1;
                $pit = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projDir, \FilesystemIterator::SKIP_DOTS));
                $i18nDirs = [];
                foreach ($pit as $f) {
                    if (!$f->isFile() || $f->isLink()) {
                        continue;
                    }
                    $abs = (string)($f->getRealPath() ?: $f->getPathname());
                    $rel = strtr(substr($f->getPathname(), $projPrefix), [chr(92) => '/']);
                    if (self::skipProjectPhp($rel, $excludePatterns)) {
                        continue;
                    }
                    if (!str_ends_with(strtolower($rel), '.php')) {
                        continue;
                    }
                    $raw = (string)file_get_contents($f->getPathname());
                    if (str_contains($raw, '// Code generated by myphp generate from the catalogs in this directory')) {
                        $i18nDirs[dirname($rel)] = true;
                    }
                    $dest = $abs === $entryAbs ? 'app.php' : $rel;
                    $files[$dest] = self::compactPhp($raw);
                }
                if (is_file($entryAbs) && !isset($files['app.php'])) {
                    $files['app.php'] = self::compactPhp((string)file_get_contents($entryAbs));
                }
                foreach (array_keys($i18nDirs) as $d) {
                    $dirPath = $d === '.' ? $projDir : "$projDir/$d";
                    foreach (glob("$dirPath/*.json") ?: [] as $jf) {
                        $jRel = ($d === '.' ? '' : "$d/") . basename($jf);
                        $files[$jRel] = (string)file_get_contents($jf);
                    }
                }
            } elseif (is_file($entryAbs)) {
                $files['app.php'] = self::compactPhp((string)file_get_contents($entryAbs));
            }
        }

        return self::encode($files);
    }

    /**
     * Emits an assembler source file (`.S`) embedding $blobPath in `.rodata`
     * (ELF / Android `libapp.so`) or `__TEXT,__const` (Mach-O / iOS).
     */
    public static function assemblySource(string $blobPath, bool $machO): string
    {
        $escaped = strtr($blobPath, [chr(92) => chr(92) . chr(92), chr(34) => chr(92) . chr(34)]);
        if ($machO) {
            return <<<ASM
            .section __TEXT,__const
            .globl _myphp_app_blob
            .p2align 3
            _myphp_app_blob:
            .incbin "$escaped"
            .globl _myphp_app_blob_end
            _myphp_app_blob_end:

            ASM;
        }
        return <<<ASM
        .section .rodata,"a",@progbits
        .global myphp_app_blob
        .balign 8
        myphp_app_blob:
        .incbin "$escaped"
        .global myphp_app_blob_end
        myphp_app_blob_end:

        ASM;
    }

    /** @param list<string> $excludePatterns */
    private static function skipProjectPhp(string $rel, array $excludePatterns): bool
    {
        foreach (explode('/', $rel) as $seg) {
            if ($seg !== '' && $seg[0] === '.') {
                return true;
            }
        }
        foreach (['dist/', 'build/', 'vendor/owner888/myphp/', 'node_modules/', 'tests/', 'test/', 'resources/'] as $pre) {
            if (str_starts_with($rel, $pre)) {
                return true;
            }
        }
        if (str_ends_with($rel, '.local.php')) {
            return true;
        }
        foreach ($excludePatterns as $pat) {
            if ($pat === '') {
                continue;
            }
            if (fnmatch($pat, $rel) || fnmatch("*/$pat", $rel) || str_starts_with($rel, rtrim($pat, '/') . '/')) {
                return true;
            }
        }
        return false;
    }
}
