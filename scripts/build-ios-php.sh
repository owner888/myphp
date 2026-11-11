#!/bin/bash
set -euo pipefail

# Build PHP embed static library (libphp.a) for iOS (arm64) with ext-ffi.
# Part of myphp iOS platform toolchain.

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
# MYPHP_IOS_SDK=iphonesimulator builds the simulator's runtime (build/ios-simulator), the device's by default (build/ios)
IOS_SDK="${MYPHP_IOS_SDK:-iphoneos}"
BUILD_DIR="${ROOT_DIR}/build/$([ "$IOS_SDK" = iphonesimulator ] && echo ios-simulator || echo ios)"
SYSROOT="${BUILD_DIR}/sysroot"
PHP_BUILD_DIR="${BUILD_DIR}/php-build"
PHP_SRC_DIR="${ROOT_DIR}/build/android/php-8.3.16"

SDK_PATH="$(xcrun --sdk "$IOS_SDK" --show-sdk-path)"
IOS_TARGET="arm64-apple-ios16.0$([ "$IOS_SDK" = iphonesimulator ] && echo -simulator || true)"
OPENSSL_TARGET="$([ "$IOS_SDK" = iphonesimulator ] && echo iossimulator-arm64-xcrun || echo ios64-xcrun)"
OPENSSL_VERSION="3.4.1"
CURL_VERSION="8.12.1"
LIBXML2_VERSION="2.13.6"

echo "==> Using iOS SDK: ${SDK_PATH}"
echo "==> Target Architecture: ${IOS_TARGET}"

mkdir -p "${BUILD_DIR}" "${SYSROOT}/lib" "${SYSROOT}/include"

export PATH="/opt/homebrew/opt/bison/bin:/opt/homebrew/bin:${PATH}"
export CC="$(xcrun --sdk "$IOS_SDK" -f clang)"
export CXX="$(xcrun --sdk "$IOS_SDK" -f clang++)"
export AR="ar"
export RANLIB="ranlib"

# 1. Build libffi for iOS
if [ ! -f "${SYSROOT}/lib/libffi.a" ]; then
    echo "==> Preparing libffi for iOS..."
    if [ ! -d "${ROOT_DIR}/build/android/libffi-src" ]; then
        mkdir -p "${ROOT_DIR}/build/android"
        git clone --depth 1 --branch v3.4.6 https://github.com/libffi/libffi.git "${ROOT_DIR}/build/android/libffi-src"
    fi
    if [ ! -f "${ROOT_DIR}/build/android/libffi-src/configure" ]; then
        (cd "${ROOT_DIR}/build/android/libffi-src" && ./autogen.sh)
    fi
    mkdir -p "${BUILD_DIR}/libffi-build"
    cd "${BUILD_DIR}/libffi-build"
    "${ROOT_DIR}/build/android/libffi-src/configure" \
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

# 2. OpenSSL and curl, static, into the app's binary (package-ios-app.sh links them): PHP's curl and openssl
# extensions, and Native\Curl over FFI, which finds libcurl's symbols in the process. No CA bundle is built
# in: main.m sets SSL_CERT_FILE to the app's cacert.pem, which OpenSSL's default paths honor (--with-ca-fallback).
if [ ! -f "${SYSROOT}/lib/libssl.a" ]; then
    echo "==> Preparing OpenSSL ${OPENSSL_VERSION} for iOS..."
    cd "${BUILD_DIR}"
    if [ ! -f "openssl-${OPENSSL_VERSION}.tar.gz" ]; then
        curl -L -o "openssl-${OPENSSL_VERSION}.tar.gz" "https://github.com/openssl/openssl/releases/download/openssl-${OPENSSL_VERSION}/openssl-${OPENSSL_VERSION}.tar.gz"
    fi
    rm -rf "openssl-${OPENSSL_VERSION}"
    tar -xzf "openssl-${OPENSSL_VERSION}.tar.gz"
    cd "openssl-${OPENSSL_VERSION}"
    ./Configure "$OPENSSL_TARGET" --prefix="${SYSROOT}" --libdir=lib no-shared no-tests no-apps no-docs no-async \
        CC="${CC}" CFLAGS="-target ${IOS_TARGET} -isysroot ${SDK_PATH} -O2 -fPIC"
    make -j8 build_libs
    make install_dev
fi

if [ ! -f "${SYSROOT}/lib/libcurl.a" ]; then
    echo "==> Preparing curl ${CURL_VERSION} for iOS..."
    cd "${BUILD_DIR}"
    if [ ! -f "curl-${CURL_VERSION}.tar.gz" ]; then
        curl -L -o "curl-${CURL_VERSION}.tar.gz" "https://curl.se/download/curl-${CURL_VERSION}.tar.gz"
    fi
    rm -rf "curl-${CURL_VERSION}"
    tar -xzf "curl-${CURL_VERSION}.tar.gz"
    cd "curl-${CURL_VERSION}"
    ./configure \
        --host=arm64-apple-darwin \
        --prefix="${SYSROOT}" \
        --enable-static --disable-shared \
        --with-openssl="${SYSROOT}" --with-zlib \
        --with-ca-fallback --without-ca-bundle --without-ca-path \
        --disable-ldap --disable-ldaps --disable-rtsp --disable-dict --disable-telnet --disable-tftp --disable-pop3 --disable-imap --disable-smtp --disable-gopher --disable-mqtt --disable-smb \
        --without-libpsl --without-libidn2 --without-brotli --without-zstd --without-nghttp2 --without-librtmp \
        --disable-symbol-hiding \
        --disable-manual --disable-docs \
        CC="${CC}" CFLAGS="-target ${IOS_TARGET} -isysroot ${SDK_PATH} -O2 -fPIC -I${SYSROOT}/include" \
        CPPFLAGS="-target ${IOS_TARGET} -isysroot ${SDK_PATH} -I${SYSROOT}/include" \
        LDFLAGS="-target ${IOS_TARGET} -isysroot ${SDK_PATH} -L${SYSROOT}/lib" \
        PKG_CONFIG_PATH="${SYSROOT}/lib/pkgconfig"
    make -j8
    make install
fi

# 3. Configure & Build PHP embed static library
# libxml2, static: PHP's xml, xmlreader, xmlwriter, dom and simplexml (UI\Svg reads SVG with XMLReader)
if [ ! -f "${SYSROOT}/lib/libxml2.a" ]; then
    echo "==> Preparing libxml2 ${LIBXML2_VERSION} for iOS..."
    cd "${BUILD_DIR}"
    if [ ! -f "libxml2-${LIBXML2_VERSION}.tar.xz" ]; then
        curl -L -o "libxml2-${LIBXML2_VERSION}.tar.xz" "https://download.gnome.org/sources/libxml2/${LIBXML2_VERSION%.*}/libxml2-${LIBXML2_VERSION}.tar.xz"
    fi
    rm -rf "libxml2-${LIBXML2_VERSION}"
    tar -xf "libxml2-${LIBXML2_VERSION}.tar.xz"
    cd "libxml2-${LIBXML2_VERSION}"
    ./configure \
        --host=arm64-apple-darwin \
        --prefix="${SYSROOT}" \
        --enable-static --disable-shared \
        --without-python --without-lzma --without-zlib --without-iconv --without-icu --without-http --without-debug \
        CC="${CC}" CFLAGS="-target ${IOS_TARGET} -isysroot ${SDK_PATH} -O2 -fPIC" \
        CPPFLAGS="-target ${IOS_TARGET} -isysroot ${SDK_PATH}"
    make -j8
    make install
fi

# The PHP source, shared with the Android build
if [ ! -f "${PHP_SRC_DIR}/configure" ]; then
    mkdir -p "${ROOT_DIR}/build/android"
    cd "${ROOT_DIR}/build/android"
    if [ ! -f "php-8.3.16.tar.gz" ]; then
        curl -L -o "php-8.3.16.tar.gz" "https://www.php.net/distributions/php-8.3.16.tar.gz"
    fi
    tar -xzf "php-8.3.16.tar.gz"
fi
# PHP 8.3's configure only enables MAP_ANON shared memory when cross-compiling for *linux*, so --enable-opcache fails on
# freshly extracted sources when targeting arm64-apple-darwin / arm64-apple-ios unless *darwin* is added to that case.
if grep -q '\*linux\*)' "${PHP_SRC_DIR}/configure" 2>/dev/null; then
    sed -i '' 's/\*linux\*)/\*linux\* | \*darwin\* | \*apple\*)/' "${PHP_SRC_DIR}/configure"
fi

# Built again when this script changed since (an extension added, as curl was): configured anew, the one stamp of what libphp has.
if [ -f "${BUILD_DIR}/libs/libphp.a" ] && [ "$0" -nt "${BUILD_DIR}/libs/libphp.a" ]; then
    rm -f "${BUILD_DIR}/libs/libphp.a" "${PHP_BUILD_DIR}/Makefile"
fi
if [ ! -f "${BUILD_DIR}/libs/libphp.a" ]; then
    echo "==> Configuring PHP for iOS..."
    mkdir -p "${PHP_BUILD_DIR}"
    cd "${PHP_BUILD_DIR}"

    export CFLAGS="-target ${IOS_TARGET} -isysroot ${SDK_PATH} -O2 -fPIC -DDARWIN -DBIND_8_COMPAT -I${SYSROOT}/include"
    export CXXFLAGS="-target ${IOS_TARGET} -isysroot ${SDK_PATH} -O2 -fPIC -DDARWIN -DBIND_8_COMPAT -I${SYSROOT}/include"
    export LDFLAGS="-target ${IOS_TARGET} -isysroot ${SDK_PATH} -L${SYSROOT}/lib"
    export CPPFLAGS="-target ${IOS_TARGET} -isysroot ${SDK_PATH} -I${SYSROOT}/include"
    export PKG_CONFIG_PATH="${SYSROOT}/lib/pkgconfig"
    export PKG_CONFIG="pkg-config --static" # libcurl.a's and OpenSSL's own dependencies into libphp.a's link line
    export OPENSSL_CFLAGS="-I${SYSROOT}/include" OPENSSL_LIBS="-L${SYSROOT}/lib -lssl -lcrypto"
    export LIBXML_CFLAGS="-I${SYSROOT}/include/libxml2" LIBXML_LIBS="-L${SYSROOT}/lib -lxml2 -lm"

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
            ac_cv_func_posix_spawn_file_actions_addchdir_np=no \
            ac_cv_shm_mmap_anon=yes \
            ac_cv_shm_mmap_posix=no \
            ac_cv_shm_ipc=no

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
