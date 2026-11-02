#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BUILD_DIR="${ROOT_DIR}/build/android"
SYSROOT="${BUILD_DIR}/sysroot"
PHP_VERSION="8.3.16"
PHP_DIR="${BUILD_DIR}/php-${PHP_VERSION}"
OUT_DIR="${BUILD_DIR}/libs/arm64-v8a"
ANDROID_API=31

if [ -z "${ANDROID_NDK_HOME:-}" ]; then
    if [ -d "/Users/kaka/Library/Android/sdk/ndk/28.2.13676358" ]; then
        ANDROID_NDK_HOME="/Users/kaka/Library/Android/sdk/ndk/28.2.13676358"
    else
        ANDROID_NDK_HOME="$(ls -1d "${HOME}/Library/Android/sdk/ndk/"* | sort -V | tail -n 1)"
    fi
fi

TOOLCHAIN="${ANDROID_NDK_HOME}/toolchains/llvm/prebuilt/darwin-x86_64"
if [ ! -d "$TOOLCHAIN" ]; then
    TOOLCHAIN="${ANDROID_NDK_HOME}/toolchains/llvm/prebuilt/linux-x86_64"
fi

CC="${TOOLCHAIN}/bin/aarch64-linux-android${ANDROID_API}-clang"

if [ ! -f "${PHP_DIR}/libs/libphp.so" ]; then
    echo "Error: libphp.so not found at ${PHP_DIR}/libs/libphp.so. Run scripts/build-android-php.sh first." >&2
    exit 1
fi

mkdir -p "$OUT_DIR"

echo "==> Compiling libmyphp.so..."
$CC -shared -fPIC -O2 \
    -I"${ROOT_DIR}/src/Platform/Android/launcher" \
    -I"${PHP_DIR}" \
    -I"${PHP_DIR}/main" \
    -I"${PHP_DIR}/Zend" \
    -I"${PHP_DIR}/TSRM" \
    -I"${SYSROOT}/include" \
    "${ROOT_DIR}/src/Platform/Android/launcher/myphp_launcher.c" \
    -L"${PHP_DIR}/libs" -lphp \
    -llog -landroid \
    -Wl,-rpath,'$ORIGIN' \
    -o "${OUT_DIR}/libmyphp.so"

echo "==> Copying libphp.so..."
cp "${PHP_DIR}/libs/libphp.so" "${OUT_DIR}/libphp.so"

echo "==> Built launcher:"
ls -lh "${OUT_DIR}"
