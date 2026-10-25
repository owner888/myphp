<?php
declare(strict_types=1);

namespace MyPHP\Native\Build;

use RuntimeException;

/**
 * The .DS_Store file that lays out the Finder window of a disk image
 * (window bounds, icon size, icon positions), as mygo's dsstore.go writes
 * it: Finder reads it when the image is opened, so building the image needs
 * no AppleScript and no Finder automation permission. The layout matches
 * the ds_store Python package that dmgbuild uses: the output is byte for
 * byte what `DSStore.open(path, "w+")` writes for the same records.
 *
 * A record is [name, code, kind, value]: a property (four-character code)
 * of a file in the folder, or of the folder itself when name is "."; kind
 * is "long", "blob" or "type", and value its big-endian bytes.
 *
 * @internal
 */
final class DsStore
{
    // The Finder window of disk images: the app on the left, a link to /Applications on the right (the layout create-dmg makes for quickgui).
    public const WINDOW_X = 200, WINDOW_Y = 400; // bottom-left corner, from the bottom of the screen
    public const WINDOW_WIDTH = 660, WINDOW_HEIGHT = 400;
    public const ICON_SIZE = 128;
    public const APP_X = 180, APP_Y = 190;
    public const APPLICATIONS_X = 480, APPLICATIONS_Y = 185;

    /** @return array{string, string, string, string} */
    public static function long(string $name, string $code, int $v): array
    {
        return [$name, $code, 'long', pack('N', $v)];
    }

    /** @return array{string, string, string, string} */
    public static function blob(string $name, string $code, string $b): array
    {
        return [$name, $code, 'blob', $b];
    }

    /** Places the center of a file's icon, in points from the top-left corner of the window content. @return array{string, string, string, string} */
    public static function iconLocation(string $name, int $x, int $y): array
    {
        return self::blob($name, 'Iloc', pack('NN', $x, $y) . "\xff\xff\xff\xff\xff\xff\x00\x00");
    }

    /** @param array{string, string, string, string} $r */
    private static function encode(array $r): string
    {
        [$name, $code, $kind, $value] = $r;
        $utf16 = mb_convert_encoding($name, 'UTF-16BE', 'UTF-8');
        return pack('N', intdiv(strlen($utf16), 2)) . $utf16 . $code . $kind . ($kind === 'blob' ? pack('N', strlen($value)) : '') . $value;
    }

    /**
     * Encodes a .DS_Store file holding $records. The file is a
     * buddy-allocated address space (after a 4-byte prefix) with a header at
     * 0, the B-tree header ("DSDB") at 0x20, the allocator's root block at
     * 0x800 and a single B-tree leaf at 0x2000. The records must fit in that
     * leaf, which is plenty for the few records of a disk image window.
     *
     * @param list<array{string, string, string, string}> $records
     */
    public static function encodeFile(array $records): string
    {
        usort($records, fn(array $a, array $b): int => strcmp(mb_strtolower($a[0]), mb_strtolower($b[0])) ?: strcmp($a[1], $b[1]));
        $node = pack('NN', 0, count($records)); // a leaf: no child pointer
        foreach ($records as $r) {
            $node .= self::encode($r);
        }
        $pageSize = 0x1000;
        if (strlen($node) > $pageSize) {
            throw new RuntimeException('.DS_Store records need ' . strlen($node) . ' bytes, more than one page');
        }
        $dsdbAddr = 0x20; $rootAddr = 0x800; $nodeAddr = 0x2000; // blocks 1 (2^5 bytes), 0 (2^11) and 2 (2^13)
        $mem = str_repeat("\0", 0x4000); // allocator addresses are relative to this
        $put = function (int $at, string $bytes) use (&$mem): void { $mem = substr_replace($mem, $bytes, $at, strlen($bytes)); };
        $put(0, 'Bud1' . pack('NNN', $rootAddr, 0x800, $rootAddr) . "\x00\x00\x10\x0c\x00\x00\x00\x87\x00\x00\x20\x0b\x00\x00\x00\x00");
        $put($dsdbAddr, pack('NNNNN', 2, 0, count($records), 1, $pageSize)); // root node: block 2; one node
        $root = pack('NN', 3, 0); // blocks
        foreach ([$rootAddr | 11, $dsdbAddr | 5, $nodeAddr | 13] as $addr) {
            $root .= pack('N', $addr); // an offset with its log2 size
        }
        $root = str_pad($root, 8 + 256 * 4, "\0"); // the block list is padded to 256 entries
        $root .= pack('N', 1) . "\x04DSDB" . pack('N', 1); // the B-tree header is block 1
        // Free lists by log2 size. Carving the blocks above out of the initial 2 GiB leaves one free block of each other size at an offset equal to its size.
        for ($width = 0; $width < 32; $width++) {
            $root .= $width < 6 || $width === 11 || $width === 13 || $width === 31 ? pack('N', 0) : pack('NN', 1, 1 << $width);
        }
        $put($rootAddr, $root);
        $put($nodeAddr, $node);
        return "\x00\x00\x00\x01" . $mem;
    }

    /**
     * A flat dictionary of bools, ints, floats and strings as a binary
     * property list, laid out as Python's plistlib does it: the
     * dictionary, then its keys and values with equal scalars shared.
     *
     * @param array<string, bool|int|float|string> $dict
     */
    public static function binaryPlist(array $dict): string
    {
        $keys = array_map('strval', array_keys($dict));
        sort($keys, SORT_STRING);
        $objs = [$dict];
        $refs = [];
        $ref = function (bool|int|float|string $v) use (&$objs, &$refs): int {
            $k = get_debug_type($v) . ':' . var_export($v, true);
            if (!isset($refs[$k])) {
                $refs[$k] = count($objs);
                $objs[] = $v;
            }
            return $refs[$k];
        };
        $keyRefs = array_map($ref, $keys);
        $valRefs = array_map(fn(string $k) => $ref($dict[$k]), $keys);
        $sizeOf = fn(int $n): int => $n < 1 << 8 ? 1 : ($n < 1 << 16 ? 2 : ($n < 1 << 32 ? 4 : 8));
        $uint = fn(int $v, int $size): string => substr(pack('J', $v), 8 - $size);
        $refSize = $sizeOf(count($objs));
        $int = fn(int $v): string => match (true) {
            $v < 0 => "\x13" . $uint($v, 8),
            $v < 1 << 8 => "\x10" . chr($v),
            $v < 1 << 16 => "\x11" . $uint($v, 2),
            $v < 1 << 32 => "\x12" . $uint($v, 4),
            default => "\x13" . $uint($v, 8),
        };
        $size = fn(int $marker, int $n): string => $n < 15 ? chr($marker | $n) : chr($marker | 0xf) . $int($n);
        $buf = 'bplist00';
        $offsets = [];
        foreach ($objs as $i => $o) {
            $offsets[$i] = strlen($buf);
            if (is_array($o)) {
                $b = $size(0xd0, count($keys));
                foreach ([...$keyRefs, ...$valRefs] as $r) {
                    $b .= $uint($r, $refSize);
                }
            } elseif (is_bool($o)) {
                $b = $o ? "\x09" : "\x08";
            } elseif (is_int($o)) {
                $b = $int($o);
            } elseif (is_float($o)) {
                $b = "\x23" . pack('E', $o);
            } elseif (preg_match('/^[\x00-\x7f]*$/', $o) === 1) {
                $b = $size(0x50, strlen($o)) . $o;
            } else {
                $u = mb_convert_encoding($o, 'UTF-16BE', 'UTF-8');
                $b = $size(0x60, intdiv(strlen($u), 2)) . $u;
            }
            $buf .= $b;
        }
        $tableOffset = strlen($buf);
        $offsetSize = $sizeOf($tableOffset);
        foreach ($offsets as $off) {
            $buf .= $uint($off, $offsetSize);
        }
        return $buf . "\0\0\0\0\0\0" . chr($offsetSize) . chr($refSize) . pack('J', count($objs)) . pack('J', 0) . pack('J', $tableOffset); // the top object is the first
    }

    /** The layout of the window of a disk image holding $app (the bundle's file name) and an Applications link. @return list<array{string, string, string, string}> */
    public static function dmgRecords(string $app): array
    {
        $bwsp = [
            'ShowStatusBar' => false,
            'WindowBounds' => sprintf('{{%d, %d}, {%d, %d}}', self::WINDOW_X, self::WINDOW_Y, self::WINDOW_WIDTH, self::WINDOW_HEIGHT),
            'ContainerShowSidebar' => false,
            'PreviewPaneVisibility' => false,
            'SidebarWidth' => 180,
            'ShowTabView' => false,
            'ShowToolbar' => false,
            'ShowPathbar' => false,
            'ShowSidebar' => false,
        ];
        $icvp = [
            'viewOptionsVersion' => 1,
            'backgroundType' => 0,
            'backgroundColorRed' => 1.0,
            'backgroundColorGreen' => 1.0,
            'backgroundColorBlue' => 1.0,
            'gridOffsetX' => 0.0,
            'gridOffsetY' => 0.0,
            'gridSpacing' => 100.0,
            'arrangeBy' => 'none',
            'showIconPreview' => false,
            'showItemInfo' => false,
            'labelOnBottom' => true,
            'textSize' => 16.0,
            'iconSize' => (float)self::ICON_SIZE,
            'scrollPositionX' => 0.0,
            'scrollPositionY' => 0.0,
        ];
        return [
            self::long('.', 'vSrn', 1),
            self::blob('.', 'bwsp', self::binaryPlist($bwsp)),
            self::blob('.', 'icvp', self::binaryPlist($icvp)),
            ['.', 'icvl', 'type', 'icnv'], // icon view
            self::iconLocation($app, self::APP_X, self::APP_Y),
            self::iconLocation('Applications', self::APPLICATIONS_X, self::APPLICATIONS_Y),
        ];
    }
}
