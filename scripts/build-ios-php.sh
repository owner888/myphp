#!/bin/bash
set -euo pipefail

# Build PHP embed static library (libphp.a) for iOS (arm64) with ext-ffi.
# Part of myphp iOS platform toolchain.

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BUILD_DIR="${ROOT_DIR}/build/ios"
SYSROOT="${BUILD_DIR}/sysroot"
PHP_BUILD_DIR="${BUILD_DIR}/php-build"
PHP_SRC_DIR="${ROOT_DIR}/build/android/php-8.3.16"

SDK_PATH="$(xcrun --sdk iphoneos --show-sdk-path)"
IOS_TARGET="arm64-apple-ios16.0"

echo "==> Using iOS SDK: ${SDK_PATH}"
echo "==> Target Architecture: ${IOS_TARGET}"

mkdir -p "${BUILD_DIR}" "${SYSROOT}/lib" "${SYSROOT}/include"

export PATH="/opt/homebrew/opt/bison/bin:/opt/homebrew/bin:${PATH}"
export CC="$(xcrun --sdk iphoneos -f clang)"
export CXX="$(xcrun --sdk iphoneos -f clang++)"
export AR="ar"
export RANLIB="ranlib"

# 1. Build libffi for iOS
if [ ! -f "${SYSROOT}/lib/libffi.a" ]; then
    echo "==> Preparing libffi for iOS..."
    if [ ! -d "${ROOT_DIR}/build/android/libffi-src" ]; then
        echo "Error: libffi-src not found. Clone it first." >&2
        exit 1
    fi
    mkdir -p "${ROOT_DIR}/build/android/libffi-src/build-ios-arm64"
    cd "${ROOT_DIR}/build/android/libffi-src/build-ios-arm64"
    ../configure \
        --host=arm64-apple-darwin \
        --prefix="${SYSROOT}" \
        --enable-static \
        --disable-shared \
        --disable-docs \
        CC="${CC}" \
        CFLAGS="-target ${IOS_TARGET} -isysroot ${SDK_PATH} -O2 -fPIC"
    sed -i '' 's/#define HAVE_AS_CFI_PSEUDO_OP 1/\/* #undef HAVE_AS_CFI_PSEUDO_OP *\//' fficonfig.h || true
    make -j8
    make install
fi

# 2. Configure & Build PHP embed static library
if [ ! -f "${BUILD_DIR}/libs/libphp.a" ]; then
    echo "==> Configuring PHP for iOS..."
    mkdir -p "${PHP_BUILD_DIR}"
    cd "${PHP_BUILD_DIR}"

    export CFLAGS="-target ${IOS_TARGET} -isysroot ${SDK_PATH} -O2 -fPIC -DDARWIN -DBIND_8_COMPAT -I${SYSROOT}/include"
    export CXXFLAGS="-target ${IOS_TARGET} -isysroot ${SDK_PATH} -O2 -fPIC -DDARWIN -DBIND_8_COMPAT -I${SYSROOT}/include"
    export LDFLAGS="-target ${IOS_TARGET} -isysroot ${SDK_PATH} -L${SYSROOT}/lib"
    export PKG_CONFIG_PATH="${SYSROOT}/lib/pkgconfig"

    if [ ! -f "Makefile" ]; then
        "${PHP_SRC_DIR}/configure" \
            --host=arm64-apple-darwin \
            --target=arm64-apple-ios \
            --prefix="${SYSROOT}" \
            --enable-embed=static \
            --disable-all \
            --disable-cgi \
            --disable-cli \
            --disable-phpdbg \
            --without-pear \
            --with-ffi \
            --enable-opcache \
            --disable-opcache-jit \
            --enable-mbstring \
            --disable-mbregex \
            --with-zlib \
            --without-pcre-jit \
            ac_cv_type_long_double=yes \
            ac_cv_type_uid_t=yes \
            ac_cv_type_gid_t=yes \
            ac_cv_func_strlcpy=yes \
            ac_cv_func_strlcat=yes \
            ac_cv_func_fnmatch=yes \
            ac_cv_func_getpgrp=yes \
            ac_cv_func_setpgrp=yes \
            ac_cv_func_getpgrp_void=yes \
            ac_cv_func_setpgrp_void=yes \
            ac_cv_func_posix_spawn_file_actions_addchdir_np=no

        # Patch Darwin/Apple headers
        sed -i '' 's/#define uid_t int/\/* #undef uid_t *\//' main/php_config.h || true
        sed -i '' 's/#define gid_t int/\/* #undef gid_t *\//' main/php_config.h || true
        sed -i '' 's/#define HAVE_POSIX_SPAWN_FILE_ACTIONS_ADDCHDIR_NP 1/\/* #undef HAVE_POSIX_SPAWN_FILE_ACTIONS_ADDCHDIR_NP *\//' main/php_config.h || true
        echo "#define HAVE_LONG_DOUBLE 1" >> main/php_config.h
    fi

    echo "==> Compiling PHP static library..."
    make -j8
    mkdir -p "${BUILD_DIR}/libs"
    cp libs/libphp.a "${BUILD_DIR}/libs/libphp.a"
fi

echo "==> Built iOS PHP embed runtime:"
ls -lh "${BUILD_DIR}/libs/libphp.a"
