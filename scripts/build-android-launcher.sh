#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BUILD_DIR="${ROOT_DIR}/build/android"
SYSROOT="${BUILD_DIR}/sysroot"
PHP_VERSION="${MYPHP_PHONE_PHP_VERSION:-8.5.12}"
PHP_DIR="${BUILD_DIR}/php-${PHP_VERSION}"
OUT_DIR="${BUILD_DIR}/libs/arm64-v8a"
ANDROID_API=31

if [ -z "${ANDROID_NDK_HOME:-}" ]; then
    ANDROID_NDK_HOME="$(ls -1d "${HOME}/Library/Android/sdk/ndk/"* | sort -V | tail -n 1)"
fi

TOOLCHAIN="${ANDROID_NDK_HOME}/toolchains/llvm/prebuilt/darwin-x86_64"
if [ ! -d "$TOOLCHAIN" ]; then
    TOOLCHAIN="${ANDROID_NDK_HOME}/toolchains/llvm/prebuilt/linux-x86_64"
fi

CC="${TOOLCHAIN}/bin/aarch64-linux-android${ANDROID_API}-clang"

# The PHP runtime (libffi, OpenSSL, libcurl.so, libphp.so), built once, and again when its script changed
"${ROOT_DIR}/scripts/build-android-php.sh"

mkdir -p "$OUT_DIR"

STRIP="${TOOLCHAIN}/bin/llvm-strip"

echo "==> Compiling libmyphp.so..."
$CC -shared -fPIC -Os -ffunction-sections -fdata-sections \
    -I"${ROOT_DIR}/src/Platform/Android/launcher" \
    -I"${PHP_DIR}" \
    -I"${PHP_DIR}/main" \
    -I"${PHP_DIR}/Zend" \
    -I"${PHP_DIR}/TSRM" \
    -I"${SYSROOT}/include" \
    "${ROOT_DIR}/src/Platform/Android/launcher/myphp_launcher.c" \
    "${ROOT_DIR}/src/Platform/Android/launcher/gl_bridge.c" \
    "${ROOT_DIR}/src/Platform/Android/launcher/image_decoder.c" \
    -L"${PHP_DIR}/libs" -lphp \
    -llog -landroid -lEGL -lGLESv3 -ljnigraphics -lm \
    -Wl,-rpath,'$ORIGIN' \
    -Wl,--gc-sections \
    -Wl,-z,max-page-size=16384 \
    -o "${OUT_DIR}/libmyphp.so"

echo "==> Copying and stripping libphp.so and libmyphp.so..."
rm -f "${OUT_DIR}/libcurl.so"
cp "${PHP_DIR}/libs/libphp.so" "${OUT_DIR}/libphp.so"
"$STRIP" --strip-unneeded "${OUT_DIR}/libmyphp.so" "${OUT_DIR}/libphp.so"

echo "==> Built launcher:"
ls -lh "${OUT_DIR}"
