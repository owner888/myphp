#!/bin/bash
set -euo pipefail

# Package a MyPHP application as an iOS App Bundle (.app) and archive (.ipa).
# Usage: ./scripts/package-ios-app.sh <entry_php> [bundle_id] [app_name] [out_dir]

ENTRY="${1:-examples/counter-native/main.php}"
BUNDLE_ID="${2:-com.owner888.myphp}"
APP_NAME="${3:-MyphpApp}"
OUT_DIR="${4:-dist/ios}"

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BUILD_DIR="${ROOT_DIR}/build/ios"
SYSROOT="${BUILD_DIR}/sysroot"
PHP_BUILD="${BUILD_DIR}/php-build"
PHP_SRC="${ROOT_DIR}/build/android/php-8.3.16"

SDK_PATH="$(xcrun --sdk iphoneos --show-sdk-path)"
IOS_TARGET="arm64-apple-ios16.0"

echo "==> Packaging iOS App: ${APP_NAME} (${BUNDLE_ID})"
echo "==> Entry file: ${ENTRY}"

# 1. Ensure libphp.a and libffi.a are present
if [ ! -f "${BUILD_DIR}/libs/libphp.a" ]; then
    echo "==> Building PHP iOS embed library first..."
    "${ROOT_DIR}/scripts/build-ios-php.sh"
fi

APP_DIR="${OUT_DIR}/${APP_NAME}.app"
rm -rf "${OUT_DIR}"
mkdir -p "${APP_DIR}"

# 2. Compile launcher binary
echo "==> Compiling native iOS launcher..."
xcrun --sdk iphoneos clang -target ${IOS_TARGET} -isysroot "${SDK_PATH}" -O2 -fobjc-arc \
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
    -lz -lm -lresolv \
    -framework UIKit -framework Foundation -framework CoreGraphics -framework Metal -framework MetalKit -framework QuartzCore \
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
    <string>1.0.0</string>
    <key>CFBundleVersion</key>
    <string>1</string>
    <key>LSRequiresIPhoneOS</key>
    <true/>
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
    <key>UIViewControllerBasedStatusBarAppearance</key>
    <false/>
</dict>
</plist>
PLIST

# 4. Bundle app entry and src
echo "==> Bundling app source..."
if [[ "${ENTRY}" = /* ]]; then
    cp "${ENTRY}" "${APP_DIR}/app.php"
else
    cp "${ROOT_DIR}/${ENTRY}" "${APP_DIR}/app.php"
fi
cp -R "${ROOT_DIR}/src" "${APP_DIR}/src"

# 5. Ad-Hoc code sign
echo "==> Code signing application..."
codesign -s - --force --deep --timestamp=none "${APP_DIR}"

# 6. Generate .ipa archive
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
