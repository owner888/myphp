<?php
declare(strict_types=1);

namespace MyPHP\Native;

use FFI;
use RuntimeException;

/**
 * The app made the default of file types, as IINA's "Set IINA as the
 * Default Application" makes it: for each extension, every type the system
 * has for it (UTTypeCreateAllIdentifiersForTag), the app's bundle their
 * handler in all roles (LSSetDefaultRoleHandlerForContentType).
 *
 * @internal UI\VideoPlayerSettingsPages
 */
final class DefaultApp
{
    private const ROLES_ALL = 0xFFFFFFFF;
    private static ?FFI $ls = null;

    /**
     * The app the default of the files of $extensions: how many of their
     * types it is now the handler of, and how many it is not.
     * @param list<string> $extensions
     * @return array{0: int, 1: int}
     */
    public static function set(array $extensions): array
    {
        $id = Bundle::info('CFBundleIdentifier') ?? throw new RuntimeException('myphp: only an app bundle (myphp build) is made the default of files');
        $ls = self::$ls ??= FFI::cdef('
            extern void* kUTTagClassFilenameExtension;
            void* UTTypeCreateAllIdentifiersForTag(void* tagClass, void* tag, void* conformingTo);
            int32_t LSSetDefaultRoleHandlerForContentType(void* contentType, uint32_t role, void* handler);
            void CFRelease(void*);
        ', '/System/Library/Frameworks/CoreServices.framework/CoreServices');
        ObjC::boot(['Foundation']);
        $own = fn($o) => $ls->cast('void*', ObjC::addr($o)); // ObjC's pointers as this instance's (ObjC::import's trap, the other way)
        $types = [];
        foreach ($extensions as $ext) {
            $all = $ls->UTTypeCreateAllIdentifiersForTag($ls->kUTTagClassFilenameExtension, $own(ObjC::str($ext)), null);
            if ($all === null || FFI::isNull($all)) { continue; }
            $arr = ObjC::import($all);
            for ($i = 0, $n = ObjC::msg('L:', $arr, 'count'); $i < $n; $i++) {
                $types[ObjC::phpStr(ObjC::msg('p:L', $arr, 'objectAtIndex:', $i))] = true;
            }
            $ls->CFRelease($all);
        }
        $ok = 0; $failed = 0;
        foreach (array_keys($types) as $type) {
            $ls->LSSetDefaultRoleHandlerForContentType($own(ObjC::str((string)$type)), self::ROLES_ALL, $own(ObjC::str($id))) === 0 ? $ok++ : $failed++;
        }
        return [$ok, $failed];
    }
}
