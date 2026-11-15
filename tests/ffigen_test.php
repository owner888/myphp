<?php
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Native\Build\{FfiGen, JniGen};
use MyPHP\Native\Jni;

function loadGeneratedPhp(string $code, string $tag): void
{
    $tmp = sys_get_temp_dir() . "/myphp_ffigen_{$tag}_" . getmypid() . '.php';
    file_put_contents($tmp, $code);
    try {
        require_once $tmp;
    } finally {
        @unlink($tmp);
    }
}

// 1. C Header FfiGen test
$cHeader = <<<'C'
#define AUDIO_SAMPLE_RATE 44100
#define AUDIO_CHANNELS 2

enum AudioState {
    AUDIO_STOPPED = 0,
    AUDIO_PLAYING = 1,
    AUDIO_PAUSED = 4
};

typedef struct {
    float left;
    float right;
} StereoFrame;

int audio_init(int sample_rate, int channels);
double audio_get_latency(void *handle);
void audio_shutdown(void);
C;

$cPhp = FfiGen::fromCHeader($cHeader, 'AudioFfi', 'MyPHP\\Tests\\Generated', 'libaudio.dylib');
check(str_contains($cPhp, 'final class AudioFfi'), 'FfiGen::fromCHeader generates class declaration');
check(str_contains($cPhp, 'public const AUDIO_SAMPLE_RATE = 44100;'), 'FfiGen::fromCHeader extracts #define constants');
check(str_contains($cPhp, 'public const AUDIO_PAUSED = 4;'), 'FfiGen::fromCHeader extracts enum constants');
check(str_contains($cPhp, 'public static function audio_init(int $sample_rate, int $channels): int'), 'FfiGen::fromCHeader maps int parameters and return');
check(str_contains($cPhp, 'public static function audio_get_latency(mixed $handle): float'), 'FfiGen::fromCHeader maps void* and double');
check(str_contains($cPhp, 'public static function audio_shutdown(): void'), 'FfiGen::fromCHeader maps void return');

// Verify generated C wrapper parses cleanly in PHP
loadGeneratedPhp($cPhp, 'c');
same(44100, \MyPHP\Tests\Generated\AudioFfi::AUDIO_SAMPLE_RATE, 'Generated C wrapper constant value');
same(4, \MyPHP\Tests\Generated\AudioFfi::AUDIO_PAUSED, 'Generated C wrapper enum constant value');

// 2. Apple Objective-C FfiGen test
$objcHeader = <<<'OBJC'
@interface AVAudioSession : NSObject
@property (readonly) NSString *category;
@property (nonatomic) double preferredIOBufferDuration;
+ (instancetype)sharedInstance;
- (BOOL)setActive:(BOOL)active error:(NSError **)outError;
- (NSInteger)outputNumberOfChannels;
@end
OBJC;

$objcPhp = FfiGen::fromObjCHeader($objcHeader, 'AVAudioSession', 'MyPHP\\Tests\\Generated', ['AVFAudio']);
check(str_contains($objcPhp, "ObjC::msg('p:', self::cls(), 'sharedInstance')"), 'FfiGen::fromObjCHeader generates static instancetype method');
check(str_contains($objcPhp, "ObjC::msg('b:bp', \$this->handle, 'setActive:error:'"), 'FfiGen::fromObjCHeader maps multi-part selector and types');
check(str_contains($objcPhp, "public function category(): string"), 'FfiGen::fromObjCHeader maps NSString* readonly property');
check(str_contains($objcPhp, "public function setPreferredIOBufferDuration(float \$value): void"), 'FfiGen::fromObjCHeader generates property setter');
loadGeneratedPhp($objcPhp, 'objc');
check(class_exists(\MyPHP\Tests\Generated\AVAudioSession::class), 'Generated Objective-C wrapper class loads cleanly');

// 3. JNI Signature Parser & JniGen test
$descs = Jni::parseArgDescriptors('(ILjava/lang/String;Z[B[Ljava/lang/Object;D)V');
same(['I', 'Ljava/lang/String;', 'Z', '[B', '[Ljava/lang/Object;', 'D'], $descs, 'Jni::parseArgDescriptors parses primitive, object, and array descriptors');

$javapSample = <<<'JAVAP'
Compiled from "BatteryManager.java"
public class android.os.BatteryManager {
  public android.os.BatteryManager();
    descriptor: ()V
  public boolean isCharging();
    descriptor: ()Z
  public int getIntProperty(int);
    descriptor: (I)I
  public long computeChargeTimeRemaining();
    descriptor: ()J
  public static java.lang.String extraName(int);
    descriptor: (I)Ljava/lang/String;
}
JAVAP;

$jniPhp = JniGen::fromJavapOutput($javapSample, 'android.os.BatteryManager', 'MyPHP\\Tests\\Generated');
check(str_contains($jniPhp, "public const JAVA_CLASS = 'android/os/BatteryManager';"), 'JniGen sets JAVA_CLASS constant');
check(str_contains($jniPhp, "public static function create(): self"), 'JniGen generates constructor factory');
check(str_contains($jniPhp, "public function isCharging(): bool"), 'JniGen maps ()Z to bool');
check(str_contains($jniPhp, "public function getIntProperty(int \$arg0): int"), 'JniGen maps (I)I to int');
check(str_contains($jniPhp, "public static function extraName(int \$arg0): ?string"), 'JniGen maps static (I)Ljava/lang/String; to ?string');
loadGeneratedPhp($jniPhp, 'jni');
same('android/os/BatteryManager', \MyPHP\Tests\Generated\BatteryManager::JAVA_CLASS, 'Generated JNI wrapper class constant');

// 4. Windows Win32 / DLL Header FfiGen test (__declspec, WINAPI, SAL annotations, Win32 typedefs)
$winHeader = <<<'C'
#define MB_OK 0x00000000
#define MB_ICONINFORMATION 0x00000040

WINUSERAPI int WINAPI MessageBoxW(_In_opt_ HWND hWnd, _In_opt_ LPCWSTR lpText, _In_opt_ LPCWSTR lpCaption, _In_ UINT uType);
__declspec(dllexport) BOOL __stdcall SetWindowPos(HWND hWnd, HWND hWndInsertAfter, int X, int Y, int cx, int cy, UINT uFlags);
DWORD WINAPI GetCurrentProcessId(void);
C;

$winPhp = FfiGen::fromCHeader($winHeader, 'User32Ffi', 'MyPHP\\Tests\\Generated', 'user32.dll');
check(str_contains($winPhp, 'typedef void* HWND;') && str_contains($winPhp, 'typedef unsigned int DWORD;'), 'FfiGen injects required Win32 typedefs into CDEF');
check(!str_contains($winPhp, 'WINAPI') && !str_contains($winPhp, '_In_opt_') && !str_contains($winPhp, '__declspec'), 'FfiGen strips Win32 SAL annotations and calling convention macros');
check(str_contains($winPhp, 'public static function MessageBoxW(mixed $hWnd, mixed $lpText, mixed $lpCaption, int $uType): int'), 'FfiGen parses Win32 MessageBoxW signature');
check(str_contains($winPhp, 'public static function GetCurrentProcessId(): int'), 'FfiGen maps DWORD return to int');
loadGeneratedPhp($winPhp, 'win');
same(0x40, \MyPHP\Tests\Generated\User32Ffi::MB_ICONINFORMATION, 'Generated Win32 wrapper constant');

// 5. Linux GTK / GLib Header FfiGen test (GDK_AVAILABLE_IN_ALL, __attribute__, gboolean, gint, GtkWidget*)
$gtkHeader = <<<'C'
G_BEGIN_DECLS
#define GTK_WINDOW_TOPLEVEL 0

GDK_AVAILABLE_IN_ALL GtkWidget* gtk_window_new(gint type) G_GNUC_CONST;
GDK_AVAILABLE_IN_3_10 void gtk_window_set_title(GtkWidget *window, const gchar *title) __attribute__((visibility("default")));
GLIB_AVAILABLE_IN_ALL gboolean g_file_test(const gchar *filename, gint test) __THROW;
G_END_DECLS
C;

$gtkPhp = FfiGen::fromCHeader($gtkHeader, 'Gtk3Ffi', 'MyPHP\\Tests\\Generated', 'libgtk-3.so.0');
check(str_contains($gtkPhp, 'typedef int gboolean;') && str_contains($gtkPhp, 'typedef struct _GtkWidget GtkWidget;'), 'FfiGen injects required Linux GLib/GTK typedefs into CDEF');
check(!str_contains($gtkPhp, 'GDK_AVAILABLE_IN_ALL') && !str_contains($gtkPhp, '__attribute__') && !str_contains($gtkPhp, 'G_BEGIN_DECLS'), 'FfiGen strips Linux GLib/GTK/GCC macros');
check(str_contains($gtkPhp, 'public static function gtk_window_new(int $type): mixed'), 'FfiGen parses Linux gtk_window_new signature');
check(str_contains($gtkPhp, 'public static function g_file_test(mixed $filename, int $test): bool|int'), 'FfiGen maps gboolean return to bool|int');
loadGeneratedPhp($gtkPhp, 'gtk');
same(0, \MyPHP\Tests\Generated\Gtk3Ffi::GTK_WINDOW_TOPLEVEL, 'Generated Linux GTK wrapper constant');


