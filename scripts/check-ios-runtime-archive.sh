#!/bin/bash
set -euo pipefail

# Compiles the iOS launcher (syntax only) against nothing but what a prebuilt iOS runtime archive holds, as `myphp run -d <iPhone>`
# does after it downloads one: an archive that lacks a header the launcher includes fails here, in CI, not on a developer's phone.
#
# Usage: scripts/check-ios-runtime-archive.sh dist-runtimes/myphp-runtime-ios-arm64-php-8.5.12.tar.gz

ARCHIVE="${1:?usage: check-ios-runtime-archive.sh <archive.tar.gz>}"
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PHP_VERSION="${MYPHP_PHONE_PHP_VERSION:-8.5.12}"

case "$(basename "${ARCHIVE}")" in
    *ios-simulator-arm64*) KIND=ios-simulator; IOS_SDK=iphonesimulator; TARGET="arm64-apple-ios16.0-simulator" ;;
    *ios-arm64*) KIND=ios; IOS_SDK=iphoneos; TARGET="arm64-apple-ios16.0" ;;
    *) echo "error: not an iOS runtime archive: ${ARCHIVE}" >&2; exit 1 ;;
esac

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT
tar -xzf "${ARCHIVE}" -C "${WORK}"

BUILD_DIR="${WORK}/build/${KIND}"
PHP_SRC="${WORK}/build/android/php-${PHP_VERSION}"
echo "==> Compiling the launcher against ${ARCHIVE}"
# The same includes as package-ios-app.sh
xcrun --sdk "${IOS_SDK}" clang -target "${TARGET}" -isysroot "$(xcrun --sdk "${IOS_SDK}" --show-sdk-path)" -fobjc-arc -fsyntax-only \
    -I"${BUILD_DIR}/php-build" -I"${BUILD_DIR}/php-build/main" -I"${BUILD_DIR}/php-build/Zend" -I"${BUILD_DIR}/php-build/TSRM" \
    -I"${PHP_SRC}" -I"${PHP_SRC}/main" -I"${PHP_SRC}/Zend" -I"${PHP_SRC}/TSRM" \
    -I"${BUILD_DIR}/sysroot/include" \
    "${ROOT_DIR}/src/Platform/iOS/launcher/main.m"
echo "==> ${KIND}: the launcher compiles from the archive alone"
