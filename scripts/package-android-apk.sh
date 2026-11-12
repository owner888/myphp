#!/bin/bash
set -euo pipefail

# Package Android APK without Gradle.
# Uses aapt2, javac, d8, zipalign, apksigner.

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BUILD_DIR="${ROOT_DIR}/build/android"
TEMPLATE_DIR="${ROOT_DIR}/bin/template/android"

PROJECT_DIR="${1:-}"
OUTPUT_APK="${2:-${BUILD_DIR}/myphp-debug.apk}"
APP_NAME="${3:-}"

SDK_DIR="${ANDROID_HOME:-${HOME}/Library/Android/sdk}"
BUILD_TOOLS_DIR="${SDK_DIR}/build-tools/35.0.0"
PLATFORM_JAR="${SDK_DIR}/platforms/android-34/android.jar"
if [ ! -f "$PLATFORM_JAR" ]; then
    PLATFORM_JAR=$(ls -1 "${SDK_DIR}/platforms/"*/android.jar | sort -V | tail -n 1)
fi

JAVAC="/opt/homebrew/opt/openjdk@17/bin/javac"
KEYTOOL="/opt/homebrew/opt/openjdk@17/bin/keytool"
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
mkdir -p "${WORK_DIR}/res"
cp -R "${TEMPLATE_DIR}/res/"* "${WORK_DIR}/res/"
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
    sed -i '' 's/<application /<application android:extractNativeLibs="true" /' "${WORK_DIR}/AndroidManifest.xml"
fi
DEBUG_MODE=""
if [ "${MYPHP_ANDROID_DEBUGGABLE:-}" = "1" ]; then DEBUG_MODE="--debug-mode"; fi
"$AAPT2" link $DEBUG_MODE \
    -I "$PLATFORM_JAR" \
    --manifest "${WORK_DIR}/AndroidManifest.xml" \
    --java "${WORK_DIR}/gen" \
    -o "${WORK_DIR}/base.apk" \
    "${WORK_DIR}/res.zip" \
    --auto-add-overlay

# 3. Compile Java code
echo "==> Compiling Java sources with javac..."
"$JAVAC" -source 17 -target 17 \
    -d "${WORK_DIR}/classes" \
    -cp "$PLATFORM_JAR" \
    $(find "${WORK_DIR}/gen" -name "*.java") \
    $(find "${TEMPLATE_DIR}/src" -name "*.java")

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

mkdir -p "assets/src"
cp -r "${ROOT_DIR}/src/"* "assets/src/"
if [ -n "$PROJECT_DIR" ] && [ -d "$PROJECT_DIR" ]; then
    [ -f "${PROJECT_DIR}/myphp.json" ] && cp "${PROJECT_DIR}/myphp.json" "assets/myphp.json"
    # The project's PHP, its folders too (view files, pages/): main.php as app.php, the rest where it is, src over the framework's
    (cd "${PROJECT_DIR}" && find . -name '*.php' -not -path './dist/*' -not -path './build/*' -not -path './vendor/*' -not -path './node_modules/*' -not -path './tests/*' -not -path './test/*' -not -name '*.local.php' -not -path './.*') | while read -r rel; do
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

# 8. Setup Keystore (supports production release keystore via env vars)
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

# 9. Sign APK
FINAL_APK="$OUTPUT_APK"
mkdir -p "$(dirname "$FINAL_APK")"
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
