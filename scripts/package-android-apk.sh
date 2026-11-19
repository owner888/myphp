#!/bin/bash
set -euo pipefail

# Package Android APK (or, with MYPHP_ANDROID_FORMAT=aab, the app bundle Google Play takes) without Gradle.
# Uses aapt2, javac, d8, zipalign, apksigner (an APK) or jarsigner (a bundle).

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
source "$(dirname "$0")/phone-build-dir.sh"
BUILD_DIR="${PHONE_BUILD}/android"
TEMPLATE_DIR="${ROOT_DIR}/bin/template/android"

PROJECT_DIR="${1:-}"
OUTPUT_APK="${2:-${BUILD_DIR}/myphp-debug.apk}"
APP_NAME="${3:-}"
FORMAT="${MYPHP_ANDROID_FORMAT:-apk}" # apk, or aab: the app bundle of Google Play
case "$FORMAT" in apk|aab) ;; *) echo "error: MYPHP_ANDROID_FORMAT is apk or aab, not $FORMAT" >&2; exit 1 ;; esac

if [ -n "${ANDROID_HOME:-}" ] && [ -d "${ANDROID_HOME}" ]; then
    SDK_DIR="${ANDROID_HOME}"
elif [ -d "${HOME}/Library/Android/sdk" ]; then
    SDK_DIR="${HOME}/Library/Android/sdk"
elif [ -d "${HOME}/android-sdk" ]; then
    SDK_DIR="${HOME}/android-sdk"
else
    SDK_DIR="${ANDROID_SDK_ROOT:-/usr/local/lib/android/sdk}"
fi
BUILD_TOOLS_DIR="${SDK_DIR}/build-tools/35.0.0"
if [ ! -d "$BUILD_TOOLS_DIR" ]; then
    BUILD_TOOLS_DIR=$(ls -1d "${SDK_DIR}/build-tools/"* | sort -V | tail -n 1)
fi
PLATFORM_JAR="${SDK_DIR}/platforms/android-34/android.jar"
if [ ! -f "$PLATFORM_JAR" ]; then
    PLATFORM_JAR=$(ls -1 "${SDK_DIR}/platforms/"*/android.jar | sort -V | tail -n 1)
fi

if [ -n "${JAVA_HOME:-}" ] && [ -x "${JAVA_HOME}/bin/javac" ]; then
    JAVAC="${JAVA_HOME}/bin/javac"
    KEYTOOL="${JAVA_HOME}/bin/keytool"
elif [ -x "/opt/homebrew/opt/openjdk@17/bin/javac" ]; then
    JAVAC="/opt/homebrew/opt/openjdk@17/bin/javac"
    KEYTOOL="/opt/homebrew/opt/openjdk@17/bin/keytool"
elif [ -x "/opt/homebrew/opt/openjdk@21/bin/javac" ]; then
    JAVAC="/opt/homebrew/opt/openjdk@21/bin/javac"
    KEYTOOL="/opt/homebrew/opt/openjdk@21/bin/keytool"
else
    JAVAC="$(command -v javac)"
    KEYTOOL="$(command -v keytool)"
fi
JARSIGNER="$(dirname "$KEYTOOL")/jarsigner"
AAPT2="${BUILD_TOOLS_DIR}/aapt2"
D8="${BUILD_TOOLS_DIR}/d8"
ZIPALIGN="${BUILD_TOOLS_DIR}/zipalign"
APKSIGNER="${BUILD_TOOLS_DIR}/apksigner"

echo "==> Using SDK: $SDK_DIR"
echo "==> Using Build Tools: $BUILD_TOOLS_DIR"
echo "==> Using Platform JAR: $PLATFORM_JAR"

# The native libraries: the runtime as it stands (built once, again when its script changed), the launcher compiled anew (one clang, so that a change in src/Platform/Android/launcher is in)
"${ROOT_DIR}/scripts/build-android-launcher.sh"

WORK_DIR="${BUILD_DIR}/pkg_work"
rm -rf "$WORK_DIR"
mkdir -p "${WORK_DIR}/gen" "${WORK_DIR}/classes" "${WORK_DIR}/dex" "${WORK_DIR}/staging"

# 1. Prepare and compile resources (supports custom project resources/icon.png as Adaptive Icon foreground)
BUNDLE_ID="com.owner888.myphp"
APP_LABEL="${APP_NAME:-MyPHP}"
APP_VERSION="1.0.0"
VERSION_CODE="1"
ANDROID_CHANNEL="${MYPHP_ANDROID_CHANNEL:-apk}"
if [ -n "$PROJECT_DIR" ] && [ -f "${PROJECT_DIR}/myphp.json" ]; then
    BUNDLE_ID="$(php -r '$c = json_decode(file_get_contents($argv[1]), true) ?: []; echo $c["identifier"] ?? "com.owner888.myphp";' "${PROJECT_DIR}/myphp.json")"
    APP_LABEL="$(php -r '$c = json_decode(file_get_contents($argv[1]), true) ?: []; echo $c["name"] ?? $c["displayName"] ?? $argv[2];' "${PROJECT_DIR}/myphp.json" "$APP_LABEL")"
    APP_VERSION="$(php -r '$c = json_decode(file_get_contents($argv[1]), true) ?: []; $v = (string)($c["version"] ?? "1.0.0"); echo explode("+", $v, 2)[0];' "${PROJECT_DIR}/myphp.json")"
    VERSION_CODE="$(php -r '
        $c = json_decode(file_get_contents($argv[1]), true) ?: [];
        $raw = (string)($c["version"] ?? "1.0.0");
        [$ver, $plus] = array_pad(explode("+", $raw, 2), 2, "");
        $build = (int)($c["buildNumber"] ?? ($plus !== "" ? $plus : 0));
        $base = preg_match("/^(\d+)\.(\d+)\.(\d+)/", $ver, $m) ? ($m[1] * 10000 + $m[2] * 100 + $m[3]) : 0;
        echo $build >= 20000 ? $build : max(1, $base + $build);
    ' "${PROJECT_DIR}/myphp.json")"
    if [ -z "${MYPHP_ANDROID_CHANNEL:-}" ]; then
        ANDROID_CHANNEL="$(php -r '$c = json_decode(file_get_contents($argv[1]), true) ?: []; echo $c["android"]["channel"] ?? $c["channel"] ?? "apk";' "${PROJECT_DIR}/myphp.json")"
    fi
fi
echo "==> App Identity: package=${BUNDLE_ID} label=${APP_LABEL} version=${APP_VERSION} (${VERSION_CODE}) channel=${ANDROID_CHANNEL}"

mkdir -p "${WORK_DIR}/res"
cp -R "${TEMPLATE_DIR}/res/"* "${WORK_DIR}/res/"
php -r '
$f = $argv[1];
$label = htmlspecialchars($argv[2], ENT_XML1 | ENT_QUOTES, "UTF-8");
file_put_contents($f, "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<resources>\n    <string name=\"app_name\">{$label}</string>\n</resources>\n");
' "${WORK_DIR}/res/values/strings.xml" "$APP_LABEL"
if [ -n "$PROJECT_DIR" ] && [ -f "${PROJECT_DIR}/resources/icon.png" ]; then
    echo "==> Using custom app icon from ${PROJECT_DIR}/resources/icon.png..."
    rm -f "${WORK_DIR}/res/drawable/ic_launcher_foreground.xml"
    cp "${PROJECT_DIR}/resources/icon.png" "${WORK_DIR}/res/drawable/ic_launcher_foreground.png"
    cp "${PROJECT_DIR}/resources/icon.png" "${WORK_DIR}/res/drawable/ic_launcher.png"
fi
echo "==> Compiling resources with aapt2..."
"$AAPT2" compile --dir "${WORK_DIR}/res" -o "${WORK_DIR}/res.zip"

# 2. Link resources and generate R.java; debuggable for myphp run (MYPHP_ANDROID_DEBUGGABLE=1), whose hot restart copies files in with run-as
echo "==> Linking APK and generating R.java..."
cp "${TEMPLATE_DIR}/AndroidManifest.xml" "${WORK_DIR}/AndroidManifest.xml"
if [ "${MYPHP_ANDROID_DEBUGGABLE:-}" != "1" ]; then
    php -r '$f=$argv[1]; file_put_contents($f, str_replace("<application ", "<application android:extractNativeLibs=\"true\" ", file_get_contents($f)));' "${WORK_DIR}/AndroidManifest.xml"
fi
if [ "$ANDROID_CHANNEL" = "play" ]; then
    echo "==> Play Store channel: removing REQUEST_INSTALL_PACKAGES permission..."
    php -r '$f=$argv[1]; file_put_contents($f, preg_replace("/\s*<uses-permission[^>]*REQUEST_INSTALL_PACKAGES[^>]*\/>/", "", file_get_contents($f)));' "${WORK_DIR}/AndroidManifest.xml"
fi
# The project's android/ folder: manifest-permissions.xml goes before <application>, manifest-application.xml (services, receivers) into it
EXTRA_DIR=""
if [ -n "$PROJECT_DIR" ] && [ -d "${PROJECT_DIR}/android" ]; then EXTRA_DIR="${PROJECT_DIR}/android"; fi
if [ -n "$EXTRA_DIR" ]; then
    php -r '
    [$f, $dir] = [$argv[1], $argv[2]];
    $m = file_get_contents($f);
    foreach ([["manifest-permissions.xml", "<application"], ["manifest-application.xml", "</application>"]] as [$name, $anchor]) {
        $snippet = "$dir/$name";
        if (!is_file($snippet)) continue;
        $pos = strpos($m, $anchor);
        if ($pos === false) { fwrite(STDERR, "manifest anchor $anchor missing\n"); exit(1); }
        $m = substr($m, 0, $pos) . trim(file_get_contents($snippet)) . "\n" . substr($m, $pos);
    }
    file_put_contents($f, $m);
    ' "${WORK_DIR}/AndroidManifest.xml" "$EXTRA_DIR"
fi
DEBUG_MODE=""
if [ "${MYPHP_ANDROID_DEBUGGABLE:-}" = "1" ]; then DEBUG_MODE="--debug-mode"; fi
PROTO_FORMAT="" # a bundle's resources and manifest are in aapt2's protobuf format, which bundletool (Play) turns into the APKs
if [ "$FORMAT" = "aab" ]; then PROTO_FORMAT="--proto-format"; fi
"$AAPT2" link $DEBUG_MODE $PROTO_FORMAT \
    -I "$PLATFORM_JAR" \
    --manifest "${WORK_DIR}/AndroidManifest.xml" \
    --rename-manifest-package "$BUNDLE_ID" \
    --version-name "$APP_VERSION" \
    --version-code "$VERSION_CODE" \
    --java "${WORK_DIR}/gen" \
    -o "${WORK_DIR}/base.apk" \
    "${WORK_DIR}/res.zip" \
    --auto-add-overlay

# 3. Compile Java code
echo "==> Compiling Java sources with javac..."
mkdir -p "${WORK_DIR}/src"
cp -R "${TEMPLATE_DIR}/src/"* "${WORK_DIR}/src/"
if [ "$ANDROID_CHANNEL" = "play" ]; then
    echo "==> Play Store channel: stripping in-app APK installer from MyphpActivity.java..."
    php -r '
    $f = $argv[1];
    $code = file_get_contents($f);
    $code = preg_replace("#// BEGIN_APK_SELF_UPDATE.*?// END_APK_SELF_UPDATE#s", "", $code);
    $code = str_replace("boolean playBuild = false;", "boolean playBuild = true;", $code);
    file_put_contents($f, $code);
    ' "${WORK_DIR}/src/com/owner888/myphp/MyphpActivity.java"
fi
# The project's own Java (android/java), compiled with the template's
PROJECT_JAVA=""
if [ -n "$EXTRA_DIR" ] && [ -d "${EXTRA_DIR}/java" ]; then
    PROJECT_JAVA="$(find "${EXTRA_DIR}/java" -name "*.java")"
fi
"$JAVAC" -source 17 -target 17 \
    -d "${WORK_DIR}/classes" \
    -cp "$PLATFORM_JAR" \
    $(find "${WORK_DIR}/gen" -name "*.java") \
    $(find "${WORK_DIR}/src" -name "*.java") \
    $PROJECT_JAVA

# 4. Dex with d8
echo "==> Converting .class to classes.dex with d8..."
CLASS_FILES=$(find "${WORK_DIR}/classes" -name "*.class")
"$D8" --min-api 31 --output "${WORK_DIR}/dex" --lib "$PLATFORM_JAR" $CLASS_FILES

# 5. Assemble staging directory
echo "==> Assembling APK contents..."
cd "${WORK_DIR}/staging"
unzip -q "${WORK_DIR}/base.apk"
cp "${WORK_DIR}/dex/classes.dex" .

mkdir -p "lib/arm64-v8a"
cp "${BUILD_DIR}/libs/arm64-v8a/"*.so "lib/arm64-v8a/"

# The project's native libraries (android/jniLibs/<abi>/*.so): only for an ABI the APK has the PHP runtime of, or the app would install where it cannot start.
# A file named lib*.so is also run as a program: it lands in the app's native library directory, which needs extractNativeLibs (the default; a release sets it).
if [ -n "$EXTRA_DIR" ] && [ -d "${EXTRA_DIR}/jniLibs" ]; then
    for abi_dir in "${EXTRA_DIR}/jniLibs/"*/; do
        [ -d "$abi_dir" ] || continue
        abi="$(basename "$abi_dir")"
        if [ -d "lib/$abi" ]; then
            echo "==> Adding native libraries for $abi from ${abi_dir}"
            cp "${abi_dir}"*.so "lib/$abi/"
        else
            echo "==> Skipping android/jniLibs/$abi: this APK has no PHP runtime for it"
        fi
    done
fi

mkdir -p "assets/src"
cp -r "${ROOT_DIR}/src/"* "assets/src/"
if [ -n "$PROJECT_DIR" ] && [ -d "$PROJECT_DIR" ]; then
    [ -f "${PROJECT_DIR}/myphp.json" ] && cp "${PROJECT_DIR}/myphp.json" "assets/myphp.json"
    # The project's PHP, its folders too (view files, pages/): main.php as app.php, the rest where it is, src over the framework's
    (cd "${PROJECT_DIR}" && find . -name '*.php' -not -path './dist/*' -not -path './build/*' -not -path './vendor/owner888/myphp/*' -not -path './node_modules/*' -not -path './tests/*' -not -path './test/*' -not -name '*.local.php' -not -path './.*') | while read -r rel; do
        rel="${rel#./}"
        if [ -n "${MYPHP_EXCLUDE:-}" ]; then
            skip=0
            IFS=':' read -ra PATS <<< "${MYPHP_EXCLUDE}"
            for pat in "${PATS[@]}"; do
                case "$rel" in $pat|*/$pat) skip=1; break ;; esac
            done
            [ "$skip" = "1" ] && continue
        fi
        dest="assets/$rel"
        [ "$rel" = "main.php" ] && dest="assets/app.php"
        mkdir -p "$(dirname "$dest")"
        cp "${PROJECT_DIR}/$rel" "$dest"
    done

    # The project's resources directory, merged into assets/ (App::path('resources')) as on iOS
    if [ -d "${PROJECT_DIR}/resources" ]; then
        (cd "${PROJECT_DIR}/resources" && find . -mindepth 1 -maxdepth 1 -not -name '.*' -not -name 'darwin*' -not -name 'linux*' -not -name 'windows*' -not -name 'ios*') | while read -r r; do
            cp -R "${PROJECT_DIR}/resources/${r#./}" "assets/"
        done
        for sub in android android-arm64; do
            if [ -d "${PROJECT_DIR}/resources/$sub" ]; then
                cp -R "${PROJECT_DIR}/resources/$sub/." "assets/"
            fi
        done
    fi
    # The catalogs of messages (myphp.json's i18n): the JSON a generated accessor reads, beside it
    (cd "${PROJECT_DIR}" && grep -rl --include='*.php' --exclude-dir=dist --exclude-dir=build --exclude-dir=vendor --exclude-dir=node_modules '^// Code generated by myphp generate from the catalogs in this directory' . || true) | while read -r rel; do
        d="$(dirname "${rel#./}")"
        cp "${PROJECT_DIR}/$d/"*.json "assets/$d/"
    done
elif [ -d "${TEMPLATE_DIR}/assets" ]; then
    cp -r "${TEMPLATE_DIR}/assets/"* "assets/"
fi

if [ "$FORMAT" = "aab" ]; then
    # The base module, as bundletool lays one out: manifest/, dex/, lib/, assets/, res/ and resources.pb; the bundle is
    # that under base/, with BundleConfig.pb beside it.
    echo "==> Assembling the app bundle..."
    MODULE="${WORK_DIR}/aab/base"
    rm -rf "${WORK_DIR}/aab"
    mkdir -p "${MODULE}/manifest" "${MODULE}/dex"
    mv AndroidManifest.xml "${MODULE}/manifest/"
    mv resources.pb "${MODULE}/"
    mv classes.dex "${MODULE}/dex/"
    for d in lib assets res; do
        [ -d "$d" ] && mv "$d" "${MODULE}/$d"
    done
    "${ROOT_DIR}/scripts/android-bundle-config.sh" "${WORK_DIR}/aab/BundleConfig.pb"
    UNSIGNED_AAB="${WORK_DIR}/unsigned.aab"
    rm -f "$UNSIGNED_AAB"
    (cd "${WORK_DIR}/aab" && zip -q -X -D -r "$UNSIGNED_AAB" BundleConfig.pb base)
fi

if [ "$FORMAT" = "apk" ]; then
# 6. Zip into unaligned APK:
# Android 30+ requires resources.arsc to be stored uncompressed (-0).
# For release builds (myphp build), compress lib/ (-9) with extractNativeLibs=true to shrink APK size by ~60% (~12 MB vs ~32 MB).
rm -f "${WORK_DIR}/unaligned.apk"
if [ -f "resources.arsc" ]; then
    zip -q -0 "${WORK_DIR}/unaligned.apk" "resources.arsc"
fi
if [ -d "lib" ]; then
    if [ "${MYPHP_ANDROID_DEBUGGABLE:-}" = "1" ]; then
        zip -q -0 -r "${WORK_DIR}/unaligned.apk" "lib"
    else
        zip -q -9 -r "${WORK_DIR}/unaligned.apk" "lib"
    fi
fi
zip -q -9 -r "${WORK_DIR}/unaligned.apk" AndroidManifest.xml classes.dex
if [ -d "assets" ]; then
    zip -q -r "${WORK_DIR}/unaligned.apk" "assets"
fi
if [ -d "res" ]; then
    zip -q -r "${WORK_DIR}/unaligned.apk" "res"
fi

# 7. Zipalign (4-byte alignment, page-align uncompressed so files)
echo "==> Aligning APK with zipalign..."
rm -f "${WORK_DIR}/aligned.apk"
"$ZIPALIGN" -v -p 4 "${WORK_DIR}/unaligned.apk" "${WORK_DIR}/aligned.apk" > /dev/null

# Check alignment
"$ZIPALIGN" -c -v 4 "${WORK_DIR}/aligned.apk" | grep -i "resources.arsc" || true

fi

# 8. Setup Keystore (supports production release keystore via env vars or ANDROID_KEYSTORE_BASE64)
if [ -n "${ANDROID_KEYSTORE_BASE64:-}" ] && [ -z "${ANDROID_KEYSTORE_PATH:-}" ]; then
    ANDROID_KEYSTORE_PATH="${WORK_DIR}/release.keystore"
    php -r 'file_put_contents($argv[1], base64_decode($argv[2]));' "$ANDROID_KEYSTORE_PATH" "$ANDROID_KEYSTORE_BASE64"
    chmod 600 "$ANDROID_KEYSTORE_PATH"
    # the release key this wrote is not left in the build folder when the script ends, however it ends
    trap 'rm -f "$ANDROID_KEYSTORE_PATH"' EXIT
fi
KEYSTORE="${ANDROID_KEYSTORE_PATH:-${BUILD_DIR}/debug.keystore}"
KEYSTORE_PASS="${ANDROID_KEYSTORE_PASS:-android}"
KEY_ALIAS="${ANDROID_KEY_ALIAS:-androiddebugkey}"
KEY_PASS="${ANDROID_KEY_PASS:-android}"

if [ ! -f "$KEYSTORE" ]; then
    echo "==> Generating debug.keystore..."
    "$KEYTOOL" -genkeypair -v \
        -keystore "$KEYSTORE" \
        -storepass "$KEYSTORE_PASS" \
        -alias "$KEY_ALIAS" \
        -keypass "$KEY_PASS" \
        -keyalg RSA \
        -keysize 2048 \
        -validity 10000 \
        -dname "CN=Android Debug,O=Android,C=US"
fi

# 9. Sign: an APK with apksigner; a bundle with jarsigner (Google Play checks a bundle's JAR signature, and signs the APKs it makes with its own key)
FINAL_APK="$OUTPUT_APK"
mkdir -p "$(dirname "$FINAL_APK")"
if [ "$FORMAT" = "aab" ]; then
    echo "==> Signing the app bundle with jarsigner..."
    cp "$UNSIGNED_AAB" "$FINAL_APK"
    "$JARSIGNER" -sigalg SHA256withRSA -digestalg SHA-256 \
        -keystore "$KEYSTORE" -storepass "$KEYSTORE_PASS" -keypass "$KEY_PASS" "$FINAL_APK" "$KEY_ALIAS" > /dev/null
    echo "==> Verifying signature..."
    "$JARSIGNER" -verify "$FINAL_APK" | head -1
    echo "==> App bundle packaged successfully: $FINAL_APK"
    ls -lh "$FINAL_APK"
    exit 0
fi
echo "==> Signing APK with apksigner..."
"$APKSIGNER" sign \
    --ks "$KEYSTORE" \
    --ks-pass "pass:$KEYSTORE_PASS" \
    --ks-key-alias "$KEY_ALIAS" \
    --key-pass "pass:$KEY_PASS" \
    --out "$FINAL_APK" \
    "${WORK_DIR}/aligned.apk"

echo "==> Verifying signature..."
"$APKSIGNER" verify "$FINAL_APK"

echo "==> APK packaged successfully: $FINAL_APK"
ls -lh "$FINAL_APK"
