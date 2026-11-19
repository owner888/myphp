#!/bin/bash
set -euo pipefail

# Build PHP shared library (libphp.so) for Android (aarch64) with ext-ffi.
# Part of myphp Android platform toolchain.

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
source "$(dirname "$0")/phone-build-dir.sh"
BUILD_DIR="${PHONE_BUILD}/android"
SYSROOT="${BUILD_DIR}/sysroot"

PHP_VERSION="${MYPHP_PHONE_PHP_VERSION:-8.5.12}"
LIBFFI_TAG="v3.4.6"
OPENSSL_VERSION="3.4.1"
CURL_VERSION="8.12.1"
LIBXML2_VERSION="2.13.6"
ANDROID_API=31

# Detect NDK
if [ -z "${ANDROID_NDK_HOME:-}" ]; then
    if [ -n "${ANDROID_NDK_ROOT:-}" ] && [ -d "${ANDROID_NDK_ROOT}" ]; then
        ANDROID_NDK_HOME="${ANDROID_NDK_ROOT}"
    elif [ -n "${ANDROID_HOME:-}" ] && [ -d "${ANDROID_HOME}/ndk" ]; then
        ANDROID_NDK_HOME="$(ls -1d "${ANDROID_HOME}/ndk/"* | sort -V | tail -n 1)"
    elif [ -d "${HOME}/Library/Android/sdk/ndk" ]; then
        ANDROID_NDK_HOME="$(ls -1d "${HOME}/Library/Android/sdk/ndk/"* | sort -V | tail -n 1)"
    elif [ -d "${HOME}/android-sdk/ndk" ]; then
        ANDROID_NDK_HOME="$(ls -1d "${HOME}/android-sdk/ndk/"* | sort -V | tail -n 1)"
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

# Fast-path: download prebuilt Android PHP runtime archive BEFORE compiling any dependencies
PHP_DIR="${BUILD_DIR}/php-${PHP_VERSION}"
PREBUILT_URL="${MYPHP_PREBUILT_URL:-https://github.com/owner888/myphp/releases/download/runtime-php-${PHP_VERSION}}/myphp-runtime-android-arm64-php-${PHP_VERSION}.tar.gz"
ensure_php_embed_headers() {
    if [ ! -f "${PHP_DIR}/main/streams/php_stream_context.h" ] || [ ! -f "${PHP_DIR}/sapi/embed/php_embed.h" ]; then
        echo "==> Fetching missing PHP ${PHP_VERSION} embed sub-headers..."
        curl -fsSL "https://www.php.net/distributions/php-${PHP_VERSION}.tar.gz" \
            | tar -xz -C "${BUILD_DIR}" \
                "php-${PHP_VERSION}/main/streams" \
                "php-${PHP_VERSION}/Zend/Optimizer" \
                "php-${PHP_VERSION}/sapi/embed"
    fi
}

# Made again when this script changed since (a fix of the runtime, as memfd_create's was), by the prebuilt archive as a first run
# gets it: in a checkout, whose script a developer edited, from source, as the archive does not have the edit yet.
FROM_SOURCE="${MYPHP_BUILD_FROM_SOURCE:-0}"
if runtime_is_stale "${PHP_DIR}/libs/libphp.so" "$0"; then
    echo "==> This script changed since the Android runtime was made: making it again"
    rm -f "${PHP_DIR}/libs/libphp.so"
    [ -d "${ROOT_DIR}/.git" ] && FROM_SOURCE=1
fi
if [ "$FROM_SOURCE" != "1" ]; then
    if [ -f "${PHP_DIR}/libs/libphp.so" ]; then
        ensure_php_embed_headers
        exit 0
    fi
    echo "==> Trying prebuilt Android PHP runtime (android-arm64)..."
    TMP_TAR="${BUILD_DIR}/runtime-prebuilt.tar.gz"
    if curl -fsSL --connect-timeout 10 --max-time 180 -o "${TMP_TAR}" "${PREBUILT_URL}" 2>/dev/null; then
        echo "==> Extracting prebuilt Android runtime..."
        tar -xzf "${TMP_TAR}" --strip-components=1 -C "${PHONE_BUILD}" && rm -f "${TMP_TAR}" # its members are build/android/...
        if [ -f "${PHP_DIR}/libs/libphp.so" ]; then
            ensure_php_embed_headers
            touch "${PHP_DIR}/libs/libphp.so"
            runtime_stamp "${PHP_DIR}/libs/libphp.so" "$0"
            echo "==> Prebuilt Android PHP runtime ready:"
            ls -lh "${PHP_DIR}/libs/libphp.so"
            exit 0
        fi
    fi
    rm -f "${TMP_TAR}"
    echo "==> Prebuilt runtime not available; building from source..."
fi

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

# 2. Build OpenSSL, static: inside libcurl.so, and PHP's openssl extension
if [ ! -f "${SYSROOT}/lib/libssl.a" ]; then
    echo "==> Preparing OpenSSL ${OPENSSL_VERSION}..."
    cd "$BUILD_DIR"
    if [ ! -f "openssl-${OPENSSL_VERSION}.tar.gz" ]; then
        curl -L -o "openssl-${OPENSSL_VERSION}.tar.gz" "https://github.com/openssl/openssl/releases/download/openssl-${OPENSSL_VERSION}/openssl-${OPENSSL_VERSION}.tar.gz"
    fi
    rm -rf "openssl-${OPENSSL_VERSION}"
    tar -xzf "openssl-${OPENSSL_VERSION}.tar.gz"
    cd "openssl-${OPENSSL_VERSION}"
    export ANDROID_NDK_ROOT="$ANDROID_NDK_HOME"
    PATH="${TOOLCHAIN}/bin:${PATH}" ./Configure android-arm64 -D__ANDROID_API__=${ANDROID_API} \
        --prefix="$SYSROOT" --libdir=lib no-shared no-tests no-apps no-docs
    PATH="${TOOLCHAIN}/bin:${PATH}" make -j"$(sysctl -n hw.ncpu 2>/dev/null || nproc)" build_libs
    PATH="${TOOLCHAIN}/bin:${PATH}" make install_dev
    echo "==> OpenSSL installed to $SYSROOT"
fi

# 3. Build curl as static libcurl.a (linked once into libphp.so alongside OpenSSL, saving ~5.3 MB of duplicate OpenSSL code):
# Native\Curl resolves libcurl's symbols directly from the process (FFI::cdef(CDEF)), just as on iOS.
# No CA bundle nor path is built in: the launcher sets SSL_CERT_FILE (the system's certificates, MyphpActivity
# writes them as PEM), which OpenSSL's default paths honor (--with-ca-fallback).
if [ ! -f "${SYSROOT}/lib/libcurl.a" ]; then
    echo "==> Preparing curl ${CURL_VERSION}..."
    cd "$BUILD_DIR"
    if [ ! -f "curl-${CURL_VERSION}.tar.gz" ]; then
        curl -L -o "curl-${CURL_VERSION}.tar.gz" "https://curl.se/download/curl-${CURL_VERSION}.tar.gz"
    fi
    rm -rf "curl-${CURL_VERSION}"
    tar -xzf "curl-${CURL_VERSION}.tar.gz"
    cd "curl-${CURL_VERSION}"
    export CFLAGS="-fPIC -Os -ffunction-sections -fdata-sections -I${SYSROOT}/include"
    export LDFLAGS="-L${SYSROOT}/lib"
    ./configure \
        --host=aarch64-linux-android \
        --prefix="$SYSROOT" \
        --enable-static --disable-shared \
        --with-openssl="$SYSROOT" --with-zlib \
        --with-ca-fallback --without-ca-bundle --without-ca-path \
        --disable-ldap --disable-ldaps --disable-rtsp --disable-dict --disable-telnet --disable-tftp --disable-pop3 --disable-imap --disable-smtp --disable-gopher --disable-mqtt --disable-smb \
        --without-libpsl --without-libidn2 --without-brotli --without-zstd --without-nghttp2 --without-librtmp \
        --disable-symbol-hiding \
        --disable-manual --disable-docs
    make -j"$(sysctl -n hw.ncpu 2>/dev/null || nproc)"
    make install
    rm -f "${SYSROOT}/lib/libcurl.so" "${SYSROOT}/lib/libcurl.la"
    echo "==> curl installed to $SYSROOT"
fi

# 4. Build libxml2, static: PHP's xml, xmlreader, xmlwriter, dom and simplexml (UI\Svg reads SVG with XMLReader)
if [ ! -f "${SYSROOT}/lib/libxml2.a" ]; then
    echo "==> Preparing libxml2 ${LIBXML2_VERSION}..."
    cd "$BUILD_DIR"
    if [ ! -f "libxml2-${LIBXML2_VERSION}.tar.xz" ]; then
        curl -L -o "libxml2-${LIBXML2_VERSION}.tar.xz" "https://download.gnome.org/sources/libxml2/${LIBXML2_VERSION%.*}/libxml2-${LIBXML2_VERSION}.tar.xz"
    fi
    rm -rf "libxml2-${LIBXML2_VERSION}"
    tar -xf "libxml2-${LIBXML2_VERSION}.tar.xz"
    cd "libxml2-${LIBXML2_VERSION}"
    export CFLAGS="-fPIC -O2"
    ./configure \
        --host=aarch64-linux-android \
        --prefix="$SYSROOT" \
        --enable-static --disable-shared \
        --without-python --without-lzma --without-zlib --without-iconv --without-icu --without-http --without-debug
    make -j"$(sysctl -n hw.ncpu 2>/dev/null || nproc)"
    make install
    echo "==> libxml2 installed to $SYSROOT"
fi

# 5. Build PHP
# Built again when this script changed since (an extension added, as curl was): the one stamp of what libphp has.
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
    export CFLAGS="-fPIC -Os -ffunction-sections -fdata-sections -I${SYSROOT}/include"
    # 16 KB pages (-z max-page-size): Google Play requires every native library of an app targeting Android 15+ to load on devices with them
    export LDFLAGS="-L${SYSROOT}/lib -Wl,-rpath,'\$ORIGIN' -Wl,--gc-sections -Wl,-z,max-page-size=16384 -Wl,-z,common-page-size=16384"
    export LIBS=""
    # The libraries configure asks pkg-config for are named outright, and pkg-config reads the sysroot's .pc files only (a
    # runtime unpacked from an archive has none, and a Homebrew's libcurl.pc is the Mac's)
    export PKG_CONFIG_LIBDIR="${SYSROOT}/lib/pkgconfig"
    unset PKG_CONFIG_PATH
    export OPENSSL_CFLAGS="-I${SYSROOT}/include" OPENSSL_LIBS="-L${SYSROOT}/lib -lssl -lcrypto"
    export LIBXML_CFLAGS="-I${SYSROOT}/include/libxml2" LIBXML_LIBS="-L${SYSROOT}/lib -lxml2 -lm"
    export CURL_CFLAGS="-I${SYSROOT}/include" CURL_LIBS="-L${SYSROOT}/lib -lcurl -lssl -lcrypto -lz"
    export FFI_CFLAGS="-I${SYSROOT}/include" FFI_LIBS="-L${SYSROOT}/lib -lffi"
    export ZLIB_CFLAGS="-I${SYSROOT}/include" ZLIB_LIBS="-lz" # the SDK's zlib, no .pc of its own; configure takes an empty ZLIB_CFLAGS for unset

    echo "==> Configuring PHP..."
    ./configure \
        --host=aarch64-linux-android \
        --target=aarch64-linux-android \
        --build=aarch64-apple-darwin \
        --prefix="$SYSROOT" \
        --enable-embed=shared \
        --disable-all \
        --disable-cli \
        --disable-cgi \
        --disable-phpdbg \
        --disable-fpm \
        --without-pear \
        --with-ffi \
        --enable-opcache \
        --disable-opcache-jit \
        --enable-mbstring \
        --disable-mbregex \
        --enable-ctype \
        --enable-filter \
        --enable-tokenizer \
        --with-zlib \
        --with-curl \
        --with-openssl \
        --with-libxml \
        --enable-dom \
        --enable-simplexml \
        --enable-xml \
        --enable-xmlreader \
        --enable-xmlwriter \
        --without-pcre-jit \
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
    # opcache's lock is a memfd by default, and fcntl() on one is denied (EACCES, "Cannot create lock") by the SELinux policy
    # of a stock Android (untrusted_app has no "lock" on a memfd's tmpfs); a phone with a permissive policy hides it. Without
    # memfd_create opcache locks a file made in opcache.lockfile_path (the launcher sets it to the app's cache folder), which
    # the app may lock.
    sed -i '' 's/#define HAVE_MEMFD_CREATE 1/\/* #undef HAVE_MEMFD_CREATE *\//' main/php_config.h

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
    make -j"$(sysctl -n hw.ncpu 2>/dev/null || nproc)" EXTRA_LDFLAGS="-avoid-version -module -L${SYSROOT}/lib -Wl,-rpath,'\$ORIGIN' -XCClinker -Wl,--gc-sections -XCClinker -Wl,-z,max-page-size=16384 -XCClinker -Wl,-z,common-page-size=16384 -XCClinker -Wl,--exclude-libs=libssl.a:libcrypto.a:libxml2.a:libffi.a"
    "$STRIP" --strip-unneeded "${PHP_DIR}/libs/libphp.so"
fi

runtime_stamp "${PHP_DIR}/libs/libphp.so" "$0"
echo "==> PHP built successfully!"
ls -lh "${PHP_DIR}/libs/libphp.so"
