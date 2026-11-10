#!/bin/bash
set -euo pipefail

# Package a MyPHP application as an iOS App Bundle (.app) and archive (.ipa).
# Usage: ./scripts/package-ios-app.sh <entry_php> [bundle_id] [app_name] [out_dir]

ENTRY="${1:-examples/counter-native/main.php}"
BUNDLE_ID="${2:-com.owner888.myphp}"
APP_NAME="${3:-MyphpApp}"
OUT_DIR="${4:-dist/ios}"
APP_VERSION="${5:-1.0.0}"

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
# MYPHP_IOS_SDK=iphonesimulator packages for the simulator (its runtime under build/ios-simulator), a device by default
IOS_SDK="${MYPHP_IOS_SDK:-iphoneos}"
BUILD_DIR="${ROOT_DIR}/build/$([ "$IOS_SDK" = iphonesimulator ] && echo ios-simulator || echo ios)"
SYSROOT="${BUILD_DIR}/sysroot"
PHP_BUILD="${BUILD_DIR}/php-build"
PHP_SRC="${ROOT_DIR}/build/android/php-8.3.16"

SDK_PATH="$(xcrun --sdk "$IOS_SDK" --show-sdk-path)"
IOS_TARGET="arm64-apple-ios16.0$([ "$IOS_SDK" = iphonesimulator ] && echo -simulator || true)"

echo "==> Packaging iOS App: ${APP_NAME} (${BUNDLE_ID})"
echo "==> Entry file: ${ENTRY}"

# 1. The PHP runtime (libffi, OpenSSL, libcurl.a, libphp.a), built once, and again when its script changed
"${ROOT_DIR}/scripts/build-ios-php.sh"

APP_DIR="${OUT_DIR}/${APP_NAME}.app"
rm -rf "${OUT_DIR}"
mkdir -p "${APP_DIR}"

# 2. Compile launcher binary
echo "==> Compiling native iOS launcher..."
xcrun --sdk "$IOS_SDK" clang -target ${IOS_TARGET} -isysroot "${SDK_PATH}" -O2 -fobjc-arc \
    -I"${PHP_BUILD}" \
    -I"${PHP_BUILD}/main" \
    -I"${PHP_BUILD}/Zend" \
    -I"${PHP_BUILD}/TSRM" \
    -I"${PHP_SRC}" \
    -I"${PHP_SRC}/main" \
    -I"${PHP_SRC}/Zend" \
    -I"${PHP_SRC}/TSRM" \
    -I"${SYSROOT}/include" \
    "${ROOT_DIR}/src/Platform/iOS/launcher/main.m" \
    "${BUILD_DIR}/libs/libphp.a" \
    "${SYSROOT}/lib/libffi.a" \
    -Wl,-force_load,"${SYSROOT}/lib/libcurl.a" \
    "${SYSROOT}/lib/libssl.a" "${SYSROOT}/lib/libcrypto.a" "${SYSROOT}/lib/libxml2.a" \
    -lz -lm -lresolv \
    -framework UIKit -framework Foundation -framework CoreGraphics -framework Metal -framework MetalKit -framework QuartzCore -framework UserNotifications -framework Network -framework Security -framework WebKit -framework AudioToolbox -framework PhotosUI -framework UniformTypeIdentifiers \
    -o "${APP_DIR}/${APP_NAME}"

# 3. Create Info.plist
cat <<PLIST > "${APP_DIR}/Info.plist"
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>CFBundleDevelopmentRegion</key>
    <string>en</string>
    <key>CFBundleExecutable</key>
    <string>${APP_NAME}</string>
    <key>CFBundleIdentifier</key>
    <string>${BUNDLE_ID}</string>
    <key>CFBundleInfoDictionaryVersion</key>
    <string>6.0</string>
    <key>CFBundleName</key>
    <string>${APP_NAME}</string>
    <key>CFBundlePackageType</key>
    <string>APPL</string>
    <key>CFBundleShortVersionString</key>
    <string>${APP_VERSION}</string>
    <key>CFBundleVersion</key>
    <string>${APP_VERSION}</string>
    <key>LSRequiresIPhoneOS</key>
    <true/>
    <key>UIApplicationSceneManifest</key>
    <dict>
        <key>UIApplicationSupportsMultipleScenes</key>
        <false/>
        <key>UISceneConfigurations</key>
        <dict>
            <key>UIWindowSceneSessionRoleApplication</key>
            <array>
                <dict>
                    <key>UISceneConfigurationName</key>
                    <string>Default</string>
                    <key>UISceneDelegateClassName</key>
                    <string>MyphpSceneDelegate</string>
                </dict>
            </array>
        </dict>
    </dict>
    <key>UIRequiredDeviceCapabilities</key>
    <array>
        <string>arm64</string>
        <string>metal</string>
    </array>
    <key>UISupportedInterfaceOrientations</key>
    <array>
        <string>UIInterfaceOrientationPortrait</string>
        <string>UIInterfaceOrientationLandscapeLeft</string>
        <string>UIInterfaceOrientationLandscapeRight</string>
    </array>
</dict>
</plist>
PLIST

# 4. Bundle app entry and src: the entry as app.php, the PHP files beside it (its views of files, Content::file) and its project's src over the framework's, as on Android
echo "==> Bundling app source..."
if [[ "${ENTRY}" != /* ]]; then
    ENTRY="${ROOT_DIR}/${ENTRY}"
fi
cp "${ENTRY}" "${APP_DIR}/app.php"
cp -R "${ROOT_DIR}/src" "${APP_DIR}/src"
ENTRY_DIR="$(dirname "${ENTRY}")"
if [ "${ENTRY_DIR}" != "${ROOT_DIR}" ]; then
    (cd "${ENTRY_DIR}" && find . -name '*.php' -not -path './dist/*' -not -path './build/*' -not -path './vendor/*' -not -path './node_modules/*' -not -path './.*') | while read -r rel; do
        rel="${rel#./}"
        [ "${ENTRY_DIR}/$rel" = "${ENTRY}" ] && continue
        mkdir -p "$(dirname "${APP_DIR}/$rel")"
        cp "${ENTRY_DIR}/$rel" "${APP_DIR}/$rel"
    done
    # The catalogs of messages (myphp.json's i18n): the JSON a generated accessor reads, beside it
    (cd "${ENTRY_DIR}" && grep -rl --include='*.php' --exclude-dir=dist --exclude-dir=build --exclude-dir=vendor --exclude-dir=node_modules '^// Code generated by myphp generate from the catalogs in this directory' . || true) | while read -r rel; do
        d="$(dirname "${rel#./}")"
        cp "${ENTRY_DIR}/$d/"*.json "${APP_DIR}/$d/"
    done
    # The project's resources directory, merged into the bundle's root (App::path('resources'))
    if [ -d "${ENTRY_DIR}/resources" ]; then
        (cd "${ENTRY_DIR}/resources" && find . -mindepth 1 -maxdepth 1 -not -name '.*' -not -name 'darwin*' -not -name 'linux*' -not -name 'windows*' -not -name 'android*') | while read -r r; do
            cp -R "${ENTRY_DIR}/resources/${r#./}" "${APP_DIR}/"
        done
        for sub in ios ios-arm64; do
            if [ -d "${ENTRY_DIR}/resources/$sub" ]; then
                cp -R "${ENTRY_DIR}/resources/$sub/." "${APP_DIR}/"
            fi
        done
    fi
fi

# 5. The CA certificates HTTPS trusts (curl's copy of Mozilla's): OpenSSL in the app has no way to the system's
if [ ! -f "${BUILD_DIR}/cacert.pem" ]; then
    echo "==> Fetching the CA bundle..."
    curl -L -o "${BUILD_DIR}/cacert.pem" "https://curl.se/ca/cacert.pem"
fi
cp "${BUILD_DIR}/cacert.pem" "${APP_DIR}/cacert.pem"

# 6. Ad-Hoc code sign
echo "==> Code signing application..."
codesign -s - --force --deep --timestamp=none "${APP_DIR}"

# 7. Generate .ipa archive
echo "==> Packaging .ipa archive..."
(
    cd "${OUT_DIR}"
    mkdir -p Payload
    cp -R "${APP_NAME}.app" Payload/
    zip -q -r "${APP_NAME}.ipa" Payload
    rm -rf Payload
)

echo "==> Packaging complete!"
ls -lh "${OUT_DIR}/${APP_NAME}.ipa"
ls -lh "${APP_DIR}/${APP_NAME}"
