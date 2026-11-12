#!/bin/bash
set -euo pipefail

# Package locally built iOS (device & simulator) and Android PHP runtimes into
# distributable tar.gz archives for GitHub Releases (`runtime-php-8.3.16`),
# so `myphp run -d ios|android` downloads them in seconds without compiling PHP.
#
# Usage:
#   ./scripts/pack-phone-runtimes.sh [out_dir]
#   gh release create runtime-php-8.3.16 dist/runtimes/*.tar.gz --title "Phone PHP 8.3.16 Runtimes"

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
OUT_DIR="${1:-${ROOT_DIR}/dist/runtimes}"
PHP_VERSION="${MYPHP_PHONE_PHP_VERSION:-8.5.12}"

mkdir -p "${OUT_DIR}"
cd "${ROOT_DIR}"

pack_ios() {
    local kind="$1" # ios or ios-simulator
    local arch_name="$2" # ios-arm64 or ios-simulator-arm64
    if [ ! -f "build/${kind}/libs/libphp.a" ]; then
        echo "==> Skipping ${arch_name} (build/${kind}/libs/libphp.a not built)"
        return
    fi
    local archive="${OUT_DIR}/myphp-runtime-${arch_name}-php-${PHP_VERSION}.tar.gz"
    echo "==> Packing ${archive}..."
    tar -czf "${archive}" \
        "build/${kind}/libs/libphp.a" \
        "build/${kind}/sysroot/lib" \
        "build/${kind}/sysroot/include" \
        "build/${kind}/php-build/main" \
        "build/${kind}/php-build/Zend" \
        "build/${kind}/php-build/TSRM" \
        "build/android/php-${PHP_VERSION}/main" \
        "build/android/php-${PHP_VERSION}/Zend" \
        "build/android/php-${PHP_VERSION}/TSRM" \
        "build/android/php-${PHP_VERSION}/sapi/embed"
    ls -lh "${archive}"
}

pack_android() {
    if [ ! -f "build/android/php-${PHP_VERSION}/libs/libphp.so" ]; then
        echo "==> Skipping android-arm64 (build/android/php-${PHP_VERSION}/libs/libphp.so not built)"
        return
    fi
    local archive="${OUT_DIR}/myphp-runtime-android-arm64-php-${PHP_VERSION}.tar.gz"
    echo "==> Packing ${archive}..."
    tar -czf "${archive}" \
        "build/android/php-${PHP_VERSION}/libs/libphp.so" \
        "build/android/sysroot/lib/libcurl.so" \
        "build/android/sysroot/include" \
        "build/android/php-${PHP_VERSION}/main" \
        "build/android/php-${PHP_VERSION}/Zend" \
        "build/android/php-${PHP_VERSION}/TSRM" \
        "build/android/php-${PHP_VERSION}/sapi/embed"
    ls -lh "${archive}"
}

pack_micro() {
    local micro="${HOME}/.myphp/micro.sfx"
    if [ ! -f "$micro" ]; then
        echo "==> Skipping desktop micro.sfx ($micro not built yet; run 'myphp micro' to build)"
        return
    fi
    local os_name="darwin"
    [ "$(uname -s)" = "Linux" ] && os_name="linux"
    local arch_name="arm64"
    [ "$(uname -m)" = "x86_64" ] && arch_name="amd64"
    local dest="${OUT_DIR}/myphp-micro-${os_name}-${arch_name}-php-8.4.sfx"
    cp "$micro" "$dest"
    ls -lh "$dest"
}

# Note for future TV platforms:
# - Android TV (arm64-v8a / aarch64) shares `myphp-runtime-android-arm64-php-8.3.16.tar.gz` directly (zero extra build).
# - tvOS will add `pack_ios "tvos" "tvos-arm64"` and `pack_ios "tvos-simulator" "tvos-simulator-arm64"` when targeted.
pack_ios "ios" "ios-arm64"
pack_ios "ios-simulator" "ios-simulator-arm64"
pack_android
pack_micro