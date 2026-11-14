#!/bin/bash
set -euo pipefail

# Packages the locally built Android & iOS PHP runtimes into minimal .tar.gz
# archives ready for GitHub Releases (tag: runtime-php-${PHP_VERSION}), which
# build-android-php.sh and build-ios-php.sh download automatically.

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PHP_VERSION="${MYPHP_PHONE_PHP_VERSION:-8.5.12}"
OUT_DIR="${1:-${ROOT_DIR}/build/runtime-archives}"

mkdir -p "${OUT_DIR}"
cd "${ROOT_DIR}"

# 1. Android arm64 runtime archive (libphp.so + C headers required to compile libmyphp.so)
ANDROID_PHP="build/android/php-${PHP_VERSION}"
if [ -f "${ANDROID_PHP}/libs/libphp.so" ]; then
    ARCHIVE="${OUT_DIR}/myphp-runtime-android-arm64-php-${PHP_VERSION}.tar.gz"
    echo "==> Packaging Android arm64 runtime -> ${ARCHIVE}"
    tar -czf "${ARCHIVE}" \
        "${ANDROID_PHP}/libs/libphp.so" \
        "${ANDROID_PHP}/main"/*.h \
        "${ANDROID_PHP}/Zend"/*.h \
        "${ANDROID_PHP}/TSRM"/*.h \
        "${ANDROID_PHP}/ext/standard"/*.h \
        "build/android/sysroot/include"
    ls -lh "${ARCHIVE}"
fi

# 2. iOS arm64 & iOS Simulator arm64 runtime archives
for TARGET in ios ios-simulator; do
    BDIR="build/${TARGET}"
    NAME="$([ "$TARGET" = "ios-simulator" ] && echo ios-simulator-arm64 || echo ios-arm64)"
    if [ -f "${BDIR}/libs/libphp.a" ]; then
        ARCHIVE="${OUT_DIR}/myphp-runtime-${NAME}-php-${PHP_VERSION}.tar.gz"
        echo "==> Packaging ${NAME} runtime -> ${ARCHIVE}"
        tar -czf "${ARCHIVE}" \
            "${BDIR}/libs/libphp.a" \
            "${BDIR}/sysroot/lib"/*.a \
            "${BDIR}/sysroot/include" \
            "${BDIR}/php-build/main"/*.h \
            "${BDIR}/php-build/Zend"/*.h \
            "${ANDROID_PHP}/main"/*.h \
            "${ANDROID_PHP}/Zend"/*.h \
            "${ANDROID_PHP}/TSRM"/*.h
        ls -lh "${ARCHIVE}"
    fi
done

# 3. SHA256SUMS
if ls "${OUT_DIR}"/*.tar.gz >/dev/null 2>&1; then
    (cd "${OUT_DIR}" && shasum -a 256 *.tar.gz > SHA256SUMS)
    cat "${OUT_DIR}/SHA256SUMS"
fi
