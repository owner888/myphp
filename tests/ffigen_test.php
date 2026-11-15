<?php
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Native\Build\{FfiGen, JniGen};
use MyPHP\Native\Jni;

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
eval('?>' . $cPhp);
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
eval('?>' . $objcPhp);
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
eval('?>' . $jniPhp);
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
eval('?>' . $winPhp);
same(0x40, \MyPHP\Tests\Generated\User32Ffi::MB_ICONINFORMATION, 'Generated Win32 wrapper constant');

