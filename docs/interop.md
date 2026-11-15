# Native Interop (`ffigen` & `jnigen`)

MyPHP apps run across macOS, Windows, Linux, Android and iOS, and reach host
operating system APIs or third-party native libraries directly and
synchronously through `ext-ffi` — with zero asynchronous message-channel
serialization overhead.

Inspired by Flutter 3.50's native interop architecture, MyPHP provides two
built-in code generators in `bin/myphp` so you never have to write `FFI::cdef`
boilerplate, look up Objective-C `objc_msgSend` type letters, or write custom
C JNI glue by hand:

- **`myphp ffigen`**: Parses **C header files (`.h`)** and **Apple Objective-C
  `@interface` headers (macOS & iOS)** to generate type-safe PHP 8.5 wrapper
  classes over `FFI::cdef()` and `MyPHP\Native\ObjC`.
- **`myphp jnigen`**: Inspects **Android SDK classes (`android.jar`)** or any
  **Java / Kotlin `.jar` / `.class`** via `javap` to generate type-safe PHP 8.5
  wrapper classes over `MyPHP\Native\Jni`.

---

## 1. C Header Bindings (`myphp ffigen`)

Given a C header file (e.g. `audio.h`):

```c
#define AUDIO_SAMPLE_RATE 44100

int audio_init(int sample_rate, int channels);
double audio_get_latency(void *handle);
void audio_shutdown(void);
```

Run `myphp ffigen`:

```sh
myphp ffigen -class AudioFfi -namespace App\\Native -lib libaudio.dylib -out src/Native/AudioFfi.php audio.h
```

`myphp ffigen` extracts `#define` constants, `enum` values, `struct`
definitions, and function prototypes into a ready-to-call PHP class with full
IDE autocomplete. On Windows headers (`.h` for `.dll` / Win32 APIs) and Linux system headers
(GLib / GTK / glibc `.so`), it automatically strips `__declspec(dllexport|dllimport)`,
`WINAPI`, `__stdcall`, SAL annotations (`_In_`, `_Out_opt_`),
`__attribute__((...))`, `GDK_AVAILABLE_IN_*`, `GLIB_AVAILABLE_IN_*`, `G_GNUC_*`,
and `__THROW`, and injects standard Win32 (`HWND`, `HANDLE`, `DWORD`, `LPCWSTR`,
`HRESULT`) and Linux GLib/GTK (`gboolean`, `gint`, `guint`, `gchar`, `gpointer`,
`GtkWidget`) typedefs.

---

## 2. Apple Objective-C & Framework Bindings (`myphp ffigen -objc`)

To call an iOS or macOS Objective-C class (such as `AVAudioSession`,
`MTLTextureDescriptor`, or `UIDevice`), pass `-objc <ClassName>` and
`-framework <FrameworkName>`:

```sh
myphp ffigen -objc AVAudioSession -framework AVFAudio -out src/Native/AVAudioSession.php AVAudioSession.h
```

The generated PHP class automatically:
- Calls `ObjC::boot(['AVFAudio'])`;
- Maps Objective-C types (`instancetype`, `NSString*`, `BOOL`, `NSInteger`,
  `NSUInteger`, `CGFloat`, `NSRect`, `CGPoint`, `CGSize`) to `ObjC::msg('ret:args', ...)`
  register-safe ARM64 signatures;
- Converts PHP `string` arguments to `NSString*` (`ObjC::str()`) and converts
  returned `NSString*` pointers back to PHP `string` (`ObjC::phpStr()`).

---

## 3. Android Java / Kotlin Bindings (`myphp jnigen` & `MyPHP\Native\Jni`)

On Android, `MyPHP\Native\Jni` provides direct synchronous JNI access from PHP
over `ext-ffi` (`FindClass`, `GetMethodID`, `NewObjectA`, `Call*MethodA`).

To generate a type-safe PHP wrapper for any Android SDK class or third-party
`.jar`:

```sh
# Generate a PHP wrapper for android.os.BatteryManager from the Android SDK
myphp jnigen -out src/Native/BatteryManager.php android.os.BatteryManager

# Or from a custom .jar library
myphp jnigen -cp libs/my-sdk.jar -out src/Native/MySdk.php com.example.sdk.MySdk
```

Call the generated class directly from PHP on Android:

<!-- no-test -->
```php
use App\Native\Android\BatteryManager;

$bm = new BatteryManager($serviceHandle);
$charging = $bm->isCharging(); // bool, synchronous JNI call
$bm->release();               // release JNI global reference when done
```