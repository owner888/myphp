# iOS Platform Support — MyPHP

Native iOS immediate-mode UI support via Metal and CoreText, powered by an embedded static PHP 8.3 runtime and `ext-ffi`.

## Architecture

- **Toolchain & Runtime**:
  - `libffi.a` cross-compiled for `arm64-apple-ios16.0`.
  - Embedded static `libphp.a` (PHP 8.3) with `ext-ffi`, `opcache`, `mbstring`, `zlib`, targeting iOS SDK.
  - Native iOS launcher (`src/Platform/iOS/launcher/main.m`) initializes PHP embed SAPI on a dedicated background thread and drives full-screen `UIWindow`.
- **GPU Rendering**:
  - Full reuse of Metal rendering pipeline (`MyPHP\Native\Metal`) and CoreText font shaping engine (`MyPHP\Native\CoreText`).
- **Platform Layer (`src/Platform/iOS/`)**:
  - `Backend`: iOS event loop and service dispatch.
  - `Window`: Full-screen single window constraint.
  - `Clipboard`: Integrated with `UIPasteboard`.
  - `Dialogs`: System alerts via `UIAlertController`.
  - `Shell`: External URL handling via `UIApplication.sharedApplication`.
  - `Power`: Screen wake lock via `idleTimerDisabled`.
  - `Theme`: System dark/light mode via `UITraitCollection`.
  - `Screen`: Display metrics via `UIScreen.mainScreen`.

## Building

```bash
# Package an iOS application (.app and .ipa)
bin/myphp build -platform ios/arm64 examples/counter-native
```

## Running in Simulator

```bash
# Build and automatically run inside the iOS Simulator
./scripts/run-ios-app.sh examples/counter-native
```
