#!/bin/bash
set -euo pipefail

# Build PHP shared library (libphp.so) for Android (aarch64) with ext-ffi.
# Part of myphp Android platform toolchain.

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BUILD_DIR="${ROOT_DIR}/build/android"
SYSROOT="${BUILD_DIR}/sysroot"

PHP_VERSION="8.3.16"
LIBFFI_TAG="v3.4.6"
ANDROID_API=31

# Detect NDK
if [ -z "${ANDROID_NDK_HOME:-}" ]; then
    if [ -d "/Users/kaka/Library/Android/sdk/ndk/28.2.13676358" ]; then
        ANDROID_NDK_HOME="/Users/kaka/Library/Android/sdk/ndk/28.2.13676358"
    elif [ -d "${HOME}/Library/Android/sdk/ndk" ]; then
        LATEST_NDK=$(ls -1d "${HOME}/Library/Android/sdk/ndk/"* | sort -V | tail -n 1)
        ANDROID_NDK_HOME="$LATEST_NDK"
    else
        echo "Error: ANDROID_NDK_HOME not set and no NDK found in standard locations" >&2
        exit 1
    fi
fi

TOOLCHAIN="${ANDROID_NDK_HOME}/toolchains/llvm/prebuilt/darwin-x86_64"
if [ ! -d "$TOOLCHAIN" ]; then
    # Try linux host if cross-building on linux
    TOOLCHAIN="${ANDROID_NDK_HOME}/toolchains/llvm/prebuilt/linux-x86_64"
fi

if [ ! -d "$TOOLCHAIN" ]; then
    echo "Error: Toolchain directory not found: $TOOLCHAIN" >&2
    exit 1
fi

echo "==> Using NDK: $ANDROID_NDK_HOME"
echo "==> Target: aarch64-linux-android${ANDROID_API}"

mkdir -p "$BUILD_DIR" "$SYSROOT"

# Toolchain environment
export PATH="/opt/homebrew/opt/bison/bin:/opt/homebrew/bin:${PATH}"
export CC="${TOOLCHAIN}/bin/aarch64-linux-android${ANDROID_API}-clang"
export CXX="${TOOLCHAIN}/bin/aarch64-linux-android${ANDROID_API}-clang++"
export AR="${TOOLCHAIN}/bin/llvm-ar"
export RANLIB="${TOOLCHAIN}/bin/llvm-ranlib"
export STRIP="${TOOLCHAIN}/bin/llvm-strip"
export PKG_CONFIG_PATH="${SYSROOT}/lib/pkgconfig"

# 1. Build libffi
if [ ! -f "${SYSROOT}/lib/libffi.a" ]; then
    echo "==> Preparing libffi ${LIBFFI_TAG}..."
    if [ ! -d "${BUILD_DIR}/libffi-src" ]; then
        git clone --depth 1 --branch "$LIBFFI_TAG" https://github.com/libffi/libffi.git "${BUILD_DIR}/libffi-src"
    fi
    cd "${BUILD_DIR}/libffi-src"
    if [ ! -f "configure" ]; then
        ./autogen.sh
    fi
    mkdir -p build-aarch64 && cd build-aarch64
    touch ../doc/libffi.info
    export CFLAGS="-fPIC -O2"
    ../configure \
        --host=aarch64-linux-android \
        --prefix="$SYSROOT" \
        --enable-static \
        --disable-shared \
        --disable-docs
    make -j"$(sysctl -n hw.ncpu 2>/dev/null || nproc)"
    make install
    echo "==> libffi installed to $SYSROOT"
fi

# 2. Build PHP
PHP_DIR="${BUILD_DIR}/php-${PHP_VERSION}"
if [ ! -f "${PHP_DIR}/libs/libphp.so" ]; then
    echo "==> Preparing PHP ${PHP_VERSION}..."
    cd "$BUILD_DIR"
    if [ ! -f "php-${PHP_VERSION}.tar.gz" ]; then
        curl -L -o "php-${PHP_VERSION}.tar.gz" "https://www.php.net/distributions/php-${PHP_VERSION}.tar.gz"
    fi
    if [ ! -d "$PHP_DIR" ]; then
        tar -xzf "php-${PHP_VERSION}.tar.gz"
    fi

    cd "$PHP_DIR"
    export YACC="/opt/homebrew/opt/bison/bin/bison -y"
    export RE2C="/opt/homebrew/bin/re2c"
    export CFLAGS="-fPIC -O2 -I${SYSROOT}/include"
    export LDFLAGS="-L${SYSROOT}/lib"
    export LIBS=""

    echo "==> Configuring PHP..."
    ./configure \
        --host=aarch64-linux-android \
        --target=aarch64-linux-android \
        --build=aarch64-apple-darwin \
        --prefix="$SYSROOT" \
        --enable-embed=shared \
        --disable-cli \
        --disable-cgi \
        --disable-phpdbg \
        --disable-fpm \
        --with-ffi \
        --enable-opcache \
        --enable-mbstring \
        --with-zlib \
        --without-sqlite3 \
        --without-pdo-sqlite \
        --without-pear \
        --disable-mbregex \
        --without-iconv \
        --without-libxml \
        --disable-dom \
        --disable-simplexml \
        --disable-xml \
        --disable-xmlreader \
        --disable-xmlwriter \
        --disable-phar \
        --disable-fileinfo \
        ac_cv_func_strlcpy=yes \
        ac_cv_func_strlcat=yes \
        ac_cv_func_fnmatch=yes \
        ac_cv_func_res_search=no \
        ac_cv_func_getpgrp=yes \
        ac_cv_func_setpgrp=yes \
        ac_cv_func_getpgrp_void=yes \
        ac_cv_func_setpgrp_void=yes \
        ac_cv_lib_crypt_crypt=no \
        ac_cv_header_valgrind_valgrind_h=no

    # Patch Android bionic differences in generated php_config.h
    echo "==> Patching main/php_config.h for Android bionic..."
    sed -i '' 's/#define HAVE_DN_EXPAND 1/\/* #undef HAVE_DN_EXPAND *\//' main/php_config.h
    sed -i '' 's/#define HAVE_DN_SKIPNAME 1/\/* #undef HAVE_DN_SKIPNAME *\//' main/php_config.h
    sed -i '' 's/#define HAVE_RES_NSEARCH 1/\/* #undef HAVE_RES_NSEARCH *\//' main/php_config.h
    sed -i '' 's/#define HAVE_RES_SEARCH 1/\/* #undef HAVE_RES_SEARCH *\//' main/php_config.h

    # Patch HARDCODED_INI in sapi/embed/php_embed.c for ffi.enable=1 & opcache
    echo "==> Enabling ffi.enable=1 and opcache in sapi/embed/php_embed.c..."
    sed -i '' 's/"max_input_time=-1\\n\\0"/"max_input_time=-1\\nffi.enable=1\\nopcache.enable=1\\nopcache.enable_cli=1\\n\\0"/' sapi/embed/php_embed.c

    # Inject getdtablesize macro before PHP_CONFIG_H guard
    php -r '
    $f = "main/php_config.h";
    $content = file_get_contents($f);
    $pos = strrpos($content, "#endif /* PHP_CONFIG_H */");
    if ($pos !== false) {
        $before = substr($content, 0, $pos);
        $new = $before . "\n#ifndef getdtablesize\n#define getdtablesize() ((int)sysconf(_SC_OPEN_MAX))\n#endif\n\n#endif /* PHP_CONFIG_H */\n";
        file_put_contents($f, $new);
    }
    '

    echo "==> Compiling PHP..."
    make -j"$(sysctl -n hw.ncpu 2>/dev/null || nproc)"
fi

echo "==> PHP built successfully!"
ls -lh "${PHP_DIR}/libs/libphp.so"
