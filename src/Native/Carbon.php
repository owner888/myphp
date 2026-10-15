<?php
declare(strict_types=1);

namespace MyPHP\Native;

use Closure;
use FFI;
use RuntimeException;

/**
 * Carbon's hot keys: RegisterEventHotKey works for any process without
 * the accessibility permission that NSEvent's global monitors need, and
 * its handler runs on the main thread inside NSApplication's loop.
 */
final class Carbon
{
    private const SIGNATURE = 0x4D795048; // 'MyPH'
    private const CLASS_KEYBOARD = 0x6B657962; // 'keyb'
    private const HOT_KEY_PRESSED = 5;
    private const PARAM_DIRECT_OBJECT = 0x2D2D2D2D; // '----'
    private const TYPE_HOT_KEY_ID = 0x686B6964; // 'hkid'
    public const CMD = 1 << 8, SHIFT = 1 << 9, OPTION = 1 << 11, CONTROL = 1 << 12;

    private static ?FFI $c = null;
    private static bool $installed = false;
    private static $handler = null; // the callback, kept alive
    /** @var array<int, Closure> by hot key id */
    private static array $pressed = [];
    /** @var array<int, FFI\CData> registered hot key refs by id */
    private static array $refs = [];
    private static int $next = 0;

    private static function c(): FFI
    {
        return self::$c ??= FFI::cdef('
            typedef struct { unsigned int signature; unsigned int id; } EventHotKeyID;
            typedef struct { unsigned int eventClass; unsigned int eventKind; } EventTypeSpec;
            typedef int (*HotKeyHandler)(void* next, void* event, void* userData);
            typedef struct { HotKeyHandler fn; } HotKeyHolder;
            void* GetApplicationEventTarget(void);
            int InstallEventHandler(void* target, void* handler, unsigned long count, const EventTypeSpec* types, void* userData, void** outRef);
            int RegisterEventHotKey(unsigned int code, unsigned int modifiers, EventHotKeyID id, void* target, unsigned int options, void** outRef);
            int UnregisterEventHotKey(void* ref);
            int GetEventParameter(void* event, unsigned int name, unsigned int type, unsigned int* actualType, unsigned long size, unsigned long* actualSize, void* data);
        ', '/System/Library/Frameworks/Carbon.framework/Carbon');
    }

    private static function install(): void
    {
        if (self::$installed) {
            return;
        }
        $c = self::c();
        self::$handler = $c->new('HotKeyHolder'); // assigning the closure makes the C-callable thunk
        self::$handler->fn = function ($next, $event, $userData) use ($c): int {
            $id = $c->new('EventHotKeyID');
            if ($c->GetEventParameter($event, self::PARAM_DIRECT_OBJECT, self::TYPE_HOT_KEY_ID, null, FFI::sizeof($id), null, FFI::addr($id)) === 0) {
                $fn = self::$pressed[$id->id] ?? null;
                $fn?->__invoke();
            }
            return 0;
        };
        $spec = $c->new('EventTypeSpec');
        $spec->eventClass = self::CLASS_KEYBOARD;
        $spec->eventKind = self::HOT_KEY_PRESSED;
        $ref = $c->new('void*[1]');
        $status = $c->InstallEventHandler($c->GetApplicationEventTarget(), $c->cast('void*', self::$handler->fn), 1, FFI::addr($spec), null, $ref);
        if ($status !== 0) {
            throw new RuntimeException("InstallEventHandler failed ($status)");
        }
        self::$installed = true;
    }

    /** Registers a hot key; returns its id, or throws when the system refuses it (another app may hold it). */
    public static function register(int $code, int $modifiers, Closure $fn): int
    {
        self::install();
        $c = self::c();
        $id = $c->new('EventHotKeyID');
        $id->signature = self::SIGNATURE;
        $id->id = ++self::$next;
        $ref = $c->new('void*[1]');
        $status = $c->RegisterEventHotKey($code, $modifiers, $id, $c->GetApplicationEventTarget(), 0, $ref);
        if ($status !== 0) {
            throw new RuntimeException("the shortcut cannot be registered, another application may hold it ($status)");
        }
        self::$refs[$id->id] = $ref;
        self::$pressed[$id->id] = $fn;
        return $id->id;
    }

    public static function unregister(int $id): void
    {
        if (isset(self::$refs[$id])) {
            self::c()->UnregisterEventHotKey(self::$refs[$id][0]);
            unset(self::$refs[$id], self::$pressed[$id]);
        }
    }
}
