#!/bin/bash
set -euo pipefail

# Package a MyPHP application as an iOS App Bundle (.app) and archive (.ipa).
# Usage: ./scripts/package-ios-app.sh <entry_php> [bundle_id] [app_name] [out_dir]

ENTRY="${1:-examples/counter-native/main.php}"
BUNDLE_ID="${2:-com.owner888.myphp}"
APP_NAME="${3:-MyphpApp}"
OUT_DIR="${4:-dist/ios}"
APP_VERSION="${5:-1.0.0}"
DISPLAY_NAME="${6:-${APP_NAME}}"

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
# MYPHP_IOS_SDK=iphonesimulator packages for the simulator (its runtime under build/ios-simulator), a device by default
IOS_SDK="${MYPHP_IOS_SDK:-iphoneos}"
BUILD_DIR="${ROOT_DIR}/build/$([ "$IOS_SDK" = iphonesimulator ] && echo ios-simulator || echo ios)"
SYSROOT="${BUILD_DIR}/sysroot"
PHP_BUILD="${BUILD_DIR}/php-build"
PHP_VERSION="${MYPHP_PHONE_PHP_VERSION:-8.5.12}"
PHP_SRC="${ROOT_DIR}/build/android/php-${PHP_VERSION}"

SDK_PATH="$(xcrun --sdk "$IOS_SDK" --show-sdk-path)"
IOS_TARGET="arm64-apple-ios16.0$([ "$IOS_SDK" = iphonesimulator ] && echo -simulator || true)"

echo "==> Packaging iOS App: ${APP_NAME} (${BUNDLE_ID})"
echo "==> Entry file: ${ENTRY}"

# 1. The PHP runtime (libffi, OpenSSL, libcurl.a, libphp.a), built once, and again when its script changed
"${ROOT_DIR}/scripts/build-ios-php.sh"
# A runtime without ext-ffi runs no app (all of myphp is FFI): refused here rather than at the app's first line. That FFI
# is on is the launcher's (main.m's ini_defaults; tests/embed_ini_test.php).
if ! xcrun nm -g "${BUILD_DIR}/libs/libphp.a" 2>/dev/null | grep ' _ffi_module_entry$' > /dev/null; then
    echo "error: ${BUILD_DIR}/libs/libphp.a has no ext-ffi (ffi_module_entry): delete it to build it again" >&2
    exit 1
fi

APP_DIR="${OUT_DIR}/${APP_NAME}.app"
# Only what this script makes: myphp build passes the project's whole output directory, where the other platforms' apps are.
rm -rf "${APP_DIR}" "${OUT_DIR}/${APP_NAME}.ipa"
mkdir -p "${APP_DIR}"

# 2. Compile launcher binary
echo "==> Compiling native iOS launcher..."
# What PHP's ext-ffi looks up in the app's own process (FFI::cdef without a library), exported so that -Wl,-dead_strip
# and strip -x keep it: the launcher's C bridge (Native\iOS), and libcurl's API (Native\Curl), linked in whole
# (-force_load) and found there, as the app has no libcurl of the system's. A pattern takes every curl_* function the
# library has, as ld's exported_symbols_list allows (its internal ones are Curl_*). tests/ios_exports_test.php checks
# that the functions declared for FFI are all in it.
EXPORT_LIST="${BUILD_DIR}/myphp_ios_exports.txt"
grep -oE 'myphp_ios_[a-zA-Z0-9_]+' "${ROOT_DIR}/src/Platform/iOS/launcher/main.m" | sort -u | sed 's/^/_/' > "${EXPORT_LIST}"
echo '_curl_*' >> "${EXPORT_LIST}"
xcrun --sdk "$IOS_SDK" clang -target ${IOS_TARGET} -isysroot "${SDK_PATH}" -Os -Wl,-dead_strip -Wl,-exported_symbols_list,"${EXPORT_LIST}" -fobjc-arc \
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
xcrun --sdk "$IOS_SDK" strip -x "${APP_DIR}/${APP_NAME}"
# What FFI finds in the process is in the app's exports, or the build fails here rather than every request in the app
# (a libcurl built with symbol hiding cannot be exported, whatever the list says; build-ios-php.sh builds it again).
if ! xcrun nm -gU "${APP_DIR}/${APP_NAME}" | grep ' _curl_easy_init$' > /dev/null; then
    echo "error: ${APP_NAME} does not export libcurl's functions (_curl_easy_init), which Native\Curl finds in the app" >&2
    exit 1
fi

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
    <string>${DISPLAY_NAME}</string>
    <key>CFBundleDisplayName</key>
    <string>${DISPLAY_NAME}</string>
    <key>CFBundlePackageType</key>
    <string>APPL</string>
    <key>CFBundleShortVersionString</key>
    <string>${APP_VERSION}</string>
    <key>CFBundleVersion</key>
    <string>${APP_VERSION}</string>
    <key>LSRequiresIPhoneOS</key>
    <true/>
    <key>CFBundleIcons</key>
    <dict>
        <key>CFBundlePrimaryIcon</key>
        <dict>
            <key>CFBundleIconFiles</key>
            <array>
                <string>AppIcon60x60</string>
            </array>
            <key>UIPrerenderedIcon</key>
            <true/>
        </dict>
    </dict>
    <key>UILaunchScreen</key>
    <dict/>
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
    [ -f "${ENTRY_DIR}/myphp.json" ] && cp "${ENTRY_DIR}/myphp.json" "${APP_DIR}/myphp.json"
    (cd "${ENTRY_DIR}" && find . -name '*.php' -not -path './dist/*' -not -path './build/*' -not -path './vendor/owner888/myphp/*' -not -path './node_modules/*' -not -path './tests/*' -not -path './test/*' -not -name '*.local.php' -not -path './.*') | while read -r rel; do
        rel="${rel#./}"
        [ "${ENTRY_DIR}/$rel" = "${ENTRY}" ] && continue
        if [ -n "${MYPHP_EXCLUDE:-}" ]; then
            skip=0
            IFS=':' read -ra PATS <<< "${MYPHP_EXCLUDE}"
            for pat in "${PATS[@]}"; do
                case "$rel" in $pat|*/$pat) skip=1; break ;; esac
            done
            [ "$skip" = "1" ] && continue
        fi
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

# 4b. AppIcon: project's resources/icon.png if present, else MyPHP's default rocket-riding ElePHPant icon
if [ -f "${ENTRY_DIR}/resources/icon.png" ]; then
    cp "${ENTRY_DIR}/resources/icon.png" "${APP_DIR}/AppIcon60x60@2x.png"
    cp "${ENTRY_DIR}/resources/icon.png" "${APP_DIR}/AppIcon60x60@3x.png"
else
    php -d ffi.enable=1 -r 'require "'"${ROOT_DIR}"'/src/autoload.php"; $png = \MyPHP\Native\Build\Icns::defaultIcon(180); file_put_contents("'"${APP_DIR}"'/AppIcon60x60@2x.png", $png); file_put_contents("'"${APP_DIR}"'/AppIcon60x60@3x.png", $png);'
fi

# 5. The CA certificates HTTPS trusts (curl's copy of Mozilla's): OpenSSL in the app has no way to the system's
if [ ! -f "${BUILD_DIR}/cacert.pem" ]; then
    echo "==> Fetching the CA bundle..."
    curl -L -o "${BUILD_DIR}/cacert.pem" "https://curl.se/ca/cacert.pem"
fi
cp "${BUILD_DIR}/cacert.pem" "${APP_DIR}/cacert.pem"

# 6. Code sign: for an iPhone with the identity and provisioning profile myphp chose (bin/myphp's iosSigning: the profile
# in the app as embedded.mobileprovision, the entitlements the app has of it), as iOS installs nothing else ("The
# executable contains an invalid signature", 0xe8008014); for the simulator ad hoc, which it runs.
echo "==> Code signing application..."
if [ -n "${MYPHP_IOS_SIGN_IDENTITY:-}" ]; then
    cp "${MYPHP_IOS_PROFILE}" "${APP_DIR}/embedded.mobileprovision"
    codesign --force --sign "${MYPHP_IOS_SIGN_IDENTITY}" --entitlements "${MYPHP_IOS_ENTITLEMENTS}" --generate-entitlement-der --timestamp=none "${APP_DIR}"
elif [ "$IOS_SDK" = iphoneos ]; then
    echo "error: an iPhone's app needs a signing identity and a provisioning profile (myphp run and myphp build choose them: ios in myphp.json)" >&2
    exit 1
else
    codesign -s - --force --deep --timestamp=none "${APP_DIR}"
fi

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
